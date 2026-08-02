<?php
/**
 * Callback and webhook handling — the security-critical half of the plugin.
 *
 * Two independent signals report the same payment:
 *
 *   1. The browser callback, when the guest is redirected back.
 *   2. The Paystack webhook (charge.success), sent server-to-server.
 *
 * Either may arrive first, both may arrive, and the callback may never arrive
 * at all if the guest closes the tab. Both paths therefore funnel into the same
 * idempotent fulfilment routine, and neither trusts anything the browser says.
 *
 * @package PaystackBabe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Paystack_Babe_Webhook
 */
class Paystack_Babe_Webhook {

	/**
	 * Query arg identifying the browser callback.
	 */
	const CALLBACK_ARG = 'paystack_babe_callback';

	/**
	 * Paystack's webhook source IPs.
	 *
	 * Advisory only — used to annotate logs, not to reject. The signature check
	 * is the real gate, and rejecting on IP alone breaks behind proxies and
	 * CDNs where REMOTE_ADDR is not the true origin.
	 *
	 * @var string[]
	 */
	const PAYSTACK_IPS = array( '52.31.139.75', '52.49.173.169', '52.214.14.220' );

	/**
	 * Register handlers.
	 */
	public static function init() {
		add_action( 'babe_payment_server_' . PAYSTACK_BABE_METHOD . '_response', array( __CLASS__, 'handle_webhook' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_callback' ) );
	}

	/**
	 * URL Paystack should POST webhooks to.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		if ( method_exists( 'BABE_Payments', 'get_payment_server_response_page_url' ) ) {
			return BABE_Payments::get_payment_server_response_page_url( PAYSTACK_BABE_METHOD );
		}

		return home_url( 'babe-api/ipn_' . PAYSTACK_BABE_METHOD );
	}

	/**
	 * URL the guest's browser returns to after paying.
	 *
	 * @return string
	 */
	public static function callback_url() {
		return add_query_arg( self::CALLBACK_ARG, '1', home_url( '/' ) );
	}

	/**
	 * Handle the server-to-server webhook.
	 */
	public static function handle_webhook() {
		$raw       = file_get_contents( 'php://input' );
		$signature = isset( $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ) ? wp_unslash( $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ) : '';

		if ( ! Paystack_Babe_Api::verify_webhook_signature( $raw, $signature, Paystack_Babe_Settings::secret_key() ) ) {
			self::log( 'Webhook rejected: bad or missing signature.' );
			status_header( 401 );
			exit;
		}

		$event = json_decode( $raw, true );

		// Acknowledge immediately. Paystack times out slow endpoints and will
		// retry for 72 hours, which would otherwise pile up duplicate work.
		status_header( 200 );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		if ( ! is_array( $event ) || empty( $event['event'] ) || 'charge.success' !== $event['event'] ) {
			exit;
		}

		$reference = isset( $event['data']['reference'] ) ? $event['data']['reference'] : '';

		if ( '' !== $reference ) {
			// Re-verify against the API rather than trusting the payload. The
			// signature proves it came from Paystack; verify proves the current
			// state of the transaction.
			self::fulfil_from_reference( $reference, 'webhook' );
		}

		exit;
	}

	/**
	 * Handle the browser returning from Paystack.
	 */
	public static function maybe_handle_callback() {
		if ( empty( $_GET[ self::CALLBACK_ARG ] ) ) {
			return;
		}

		// Paystack documents `reference`; `trxref` is also commonly present.
		// Accept either.
		$reference = '';
		foreach ( array( 'reference', 'trxref' ) as $key ) {
			if ( ! empty( $_GET[ $key ] ) ) {
				$reference = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
				break;
			}
		}

		if ( '' === $reference ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		$context = get_transient( 'paystack_babe_return_' . $reference );
		$result  = self::fulfil_from_reference( $reference, 'callback' );

		if ( is_wp_error( $result ) ) {
			if ( ! session_id() && ! headers_sent() ) {
				@session_start(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			$_SESSION['paystack_babe_error'] = $result->get_error_message();

			$cancel = ! empty( $context['cancel_url'] ) ? $context['cancel_url'] : home_url();
			wp_redirect( esc_url_raw( $cancel ) );
			exit;
		}

		$success = ! empty( $context['success_url'] ) ? $context['success_url'] : self::confirmation_url( $result );

		wp_redirect( esc_url_raw( $success ) );
		exit;
	}

	/**
	 * Verify a reference with Paystack and mark the booking paid.
	 *
	 * @param string $reference Transaction reference.
	 * @param string $source    'callback' or 'webhook', for logging.
	 * @return int|WP_Error Order id on success.
	 */
	private static function fulfil_from_reference( $reference, $source ) {
		$order_id = Paystack_Babe_Gateway::order_id_from_reference( $reference );

		if ( ! $order_id ) {
			self::log( sprintf( 'Ignoring reference not created by this plugin: %s', $reference ) );
			return new WP_Error( 'paystack_babe_foreign_reference', __( 'Unrecognised payment reference.', 'booking-gateway-for-paystack' ) );
		}

		// Serialise callback and webhook so they cannot both fulfil the same
		// reference concurrently.
		$lock_key = 'paystack_babe_lock_' . md5( $reference );
		if ( get_transient( $lock_key ) ) {
			return $order_id;
		}
		set_transient( $lock_key, 1, 2 * MINUTE_IN_SECONDS );

		$api    = new Paystack_Babe_Api( Paystack_Babe_Settings::secret_key() );
		$verify = $api->verify_transaction( $reference );

		if ( is_wp_error( $verify ) ) {
			delete_transient( $lock_key );
			self::log( sprintf( '[%s] Verify failed for %s: %s', $source, $reference, $verify->get_error_message() ) );
			return $verify;
		}

		// The transaction status lives in data.status. The envelope's own
		// status only reports whether the API call worked.
		$status = isset( $verify['status'] ) ? (string) $verify['status'] : '';

		if ( 'success' !== $status ) {
			delete_transient( $lock_key );
			self::log( sprintf( '[%s] Not fulfilling %s — transaction status is "%s".', $source, $reference, $status ) );
			return new WP_Error(
				'paystack_babe_not_successful',
				__( 'The payment was not completed.', 'booking-gateway-for-paystack' )
			);
		}

		$context  = get_transient( 'paystack_babe_return_' . $reference );
		$expected = isset( $context['amount'] ) ? (float) $context['amount'] : 0.0;
		$currency = isset( $context['currency'] ) ? (string) $context['currency'] : '';

		$paid_subunit = isset( $verify['amount'] ) ? (int) $verify['amount'] : 0;
		$paid_ccy     = isset( $verify['currency'] ) ? strtoupper( (string) $verify['currency'] ) : '';

		// Re-check what was actually paid against what we asked for. Compared
		// against our own stored figure, never anything echoed by the browser.
		if ( $expected > 0 ) {
			if ( $paid_subunit < Paystack_Babe_Api::to_subunit( $expected ) ) {
				delete_transient( $lock_key );
				self::log( sprintf( '[%s] Underpayment on %s: expected %d, got %d.', $source, $reference, Paystack_Babe_Api::to_subunit( $expected ), $paid_subunit ) );
				return new WP_Error( 'paystack_babe_amount_mismatch', __( 'The amount paid did not match the booking total.', 'booking-gateway-for-paystack' ) );
			}

			if ( '' !== $currency && $paid_ccy !== strtoupper( $currency ) ) {
				delete_transient( $lock_key );
				self::log( sprintf( '[%s] Currency mismatch on %s: expected %s, got %s.', $source, $reference, $currency, $paid_ccy ) );
				return new WP_Error( 'paystack_babe_currency_mismatch', __( 'The payment currency did not match the booking.', 'booking-gateway-for-paystack' ) );
			}
		}

		if ( self::already_fulfilled( $reference ) ) {
			self::log( sprintf( '[%s] %s already fulfilled — skipping.', $source, $reference ) );
			return $order_id;
		}

		BABE_Payments::do_complete_order(
			$order_id,
			PAYSTACK_BABE_METHOD,
			$reference,
			$paid_subunit / 100,
			$paid_ccy,
			array( 'source' => $source )
		);

		self::mark_fulfilled( $reference );
		self::log( sprintf( '[%s] Completed order %d from %s.', $source, $order_id, $reference ) );

		return $order_id;
	}

	/**
	 * Whether this reference has already been turned into a completed booking.
	 *
	 * Persisted rather than cached — a transient expiring must never allow a
	 * second fulfilment of the same payment.
	 *
	 * @param string $reference Transaction reference.
	 * @return bool
	 */
	private static function already_fulfilled( $reference ) {
		$done = get_option( 'paystack_babe_fulfilled', array() );

		return is_array( $done ) && isset( $done[ $reference ] );
	}

	/**
	 * Record a reference as fulfilled.
	 *
	 * @param string $reference Transaction reference.
	 */
	private static function mark_fulfilled( $reference ) {
		$done = get_option( 'paystack_babe_fulfilled', array() );

		if ( ! is_array( $done ) ) {
			$done = array();
		}

		$done[ $reference ] = time();

		// Keep the ledger bounded; retain the most recent 500 references.
		if ( count( $done ) > 500 ) {
			asort( $done );
			$done = array_slice( $done, -500, null, true );
		}

		update_option( 'paystack_babe_fulfilled', $done, false );
	}

	/**
	 * Resolve BABE's confirmation page for an order.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	private static function confirmation_url( $order_id ) {
		if ( method_exists( 'BABE_Order', 'get_order_confirmation_page' ) ) {
			$url = BABE_Order::get_order_confirmation_page( $order_id );
			if ( ! empty( $url ) ) {
				return $url;
			}
		}

		return home_url();
	}

	/**
	 * Write to the PHP error log when WP_DEBUG is on.
	 *
	 * @param string $message Message.
	 */
	private static function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[paystack-babe] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
