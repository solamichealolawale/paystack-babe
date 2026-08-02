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
 * at all if the guest closes the tab. Both funnel into one routine whose
 * mutual exclusion is a conditional UPDATE in the database — see
 * Paystack_Babe_Store::claim() for why a transient guard was not sufficient.
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
	 * Fulfilment outcomes, so callers can tell these apart. Returning a bare
	 * order id for both "I credited this" and "someone else holds it" is what
	 * previously let a guest be shown a confirmation page for an unpaid booking.
	 */
	const RESULT_FULFILLED = 'fulfilled';
	const RESULT_ALREADY   = 'already_fulfilled';

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
	 *
	 * Note the response is sent *after* the work, not before. Acknowledging
	 * early looks tidy but tells Paystack the delivery succeeded before anything
	 * has happened — so a failed verify, a database error or a timeout would
	 * never be retried, silently losing the payment. Paystack retrying a slow
	 * request is the desired behaviour, not a problem to optimise away.
	 */
	public static function handle_webhook() {
		$raw       = file_get_contents( 'php://input' );
		$signature = isset( $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ) ? wp_unslash( $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ) : '';

		if ( ! Paystack_Babe_Api::verify_webhook_signature( $raw, $signature, Paystack_Babe_Settings::secret_key() ) ) {
			self::log( 'Webhook rejected: bad or missing signature.', 'warning' );
			status_header( 401 );
			exit;
		}

		$event = json_decode( $raw, true );

		if ( ! is_array( $event ) || empty( $event['event'] ) || 'charge.success' !== $event['event'] ) {
			status_header( 200 );
			exit;
		}

		$reference = isset( $event['data']['reference'] ) ? (string) $event['data']['reference'] : '';
		$result    = '' === $reference ? new WP_Error( 'no_reference', 'No reference in payload.' ) : self::fulfil( $reference, 'webhook' );

		if ( is_wp_error( $result ) ) {
			// 500 so Paystack retries. The one exception is a reference we never
			// issued, which will never become valid — acknowledge those to stop
			// 72 hours of pointless redelivery.
			$permanent = in_array( $result->get_error_code(), array( 'unknown_reference', 'no_reference' ), true );
			status_header( $permanent ? 200 : 500 );
			exit;
		}

		status_header( 200 );
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

		$payment = Paystack_Babe_Store::get( $reference );

		if ( ! $payment ) {
			// Not a reference this site issued. Bail before touching the Paystack
			// API — otherwise this unauthenticated endpoint is a free way to burn
			// the merchant's API rate limit.
			self::log( sprintf( 'Callback for unknown reference: %s', $reference ), 'warning' );
			wp_safe_redirect( home_url() );
			exit;
		}

		$order_id = (int) $payment['order_id'];
		$result   = self::fulfil( $reference, 'callback' );

		if ( is_wp_error( $result ) ) {
			self::remember_error( $order_id, $result->get_error_message() );
			wp_safe_redirect( self::checkout_url( $order_id ) );
			exit;
		}

		wp_safe_redirect( self::confirmation_url( $order_id ) );
		exit;
	}

	/**
	 * Verify a reference with Paystack and credit the booking exactly once.
	 *
	 * @param string $reference Transaction reference.
	 * @param string $source    'callback' or 'webhook', for logging.
	 * @return string|WP_Error One of the RESULT_* constants.
	 */
	private static function fulfil( $reference, $source ) {
		$payment = Paystack_Babe_Store::get( $reference );

		if ( ! $payment ) {
			self::log( sprintf( '[%s] Unknown reference, ignoring: %s', $source, $reference ), 'warning' );

			return new WP_Error( 'unknown_reference', __( 'Unrecognised payment reference.', 'booking-gateway-for-paystack' ) );
		}

		$order_id = (int) $payment['order_id'];

		if ( Paystack_Babe_Store::STATUS_FULFILLED === $payment['status'] ) {
			return self::RESULT_ALREADY;
		}

		if ( ! get_post( $order_id ) ) {
			// The order was deleted between checkout and delivery. Say so loudly:
			// a real charge exists with nothing to attach it to.
			self::log( sprintf( '[%s] Order %d no longer exists for paid reference %s — MANUAL ACTION REQUIRED.', $source, $order_id, $reference ), 'error' );

			return new WP_Error( 'order_missing', __( 'The booking for this payment no longer exists.', 'booking-gateway-for-paystack' ) );
		}

		$api    = new Paystack_Babe_Api( Paystack_Babe_Settings::secret_key() );
		$verify = $api->verify_transaction( $reference );

		if ( is_wp_error( $verify ) ) {
			self::log( sprintf( '[%s] Verify failed for %s: %s', $source, $reference, $verify->get_error_message() ), 'error' );

			return $verify;
		}

		// The transaction status lives in data.status. The envelope's own status
		// only reports whether the API call worked.
		$status = isset( $verify['status'] ) ? (string) $verify['status'] : '';

		if ( 'success' !== $status ) {
			self::log( sprintf( '[%s] Not fulfilling %s — transaction status is "%s".', $source, $reference, $status ), 'info' );

			return new WP_Error( 'not_successful', __( 'The payment was not completed.', 'booking-gateway-for-paystack' ) );
		}

		$paid_subunit = isset( $verify['amount'] ) ? (int) $verify['amount'] : 0;
		$paid_ccy     = isset( $verify['currency'] ) ? strtoupper( (string) $verify['currency'] ) : '';
		$paid_major   = $paid_subunit / 100;

		$expected_amount   = (float) $payment['expected_amount'];
		$expected_currency = strtoupper( (string) $payment['expected_currency'] );

		// Durable, so unlike the previous transient this cannot quietly vanish
		// and take the check with it.
		if ( $paid_subunit < Paystack_Babe_Api::to_subunit( $expected_amount ) ) {
			self::log( sprintf( '[%s] Underpayment on %s: expected %d, got %d.', $source, $reference, Paystack_Babe_Api::to_subunit( $expected_amount ), $paid_subunit ), 'error' );

			return new WP_Error( 'amount_mismatch', __( 'The amount paid did not match the booking total.', 'booking-gateway-for-paystack' ) );
		}

		if ( '' !== $expected_currency && $paid_ccy !== $expected_currency ) {
			self::log( sprintf( '[%s] Currency mismatch on %s: expected %s, got %s.', $source, $reference, $expected_currency, $paid_ccy ), 'error' );

			return new WP_Error( 'currency_mismatch', __( 'The payment currency did not match the booking.', 'booking-gateway-for-paystack' ) );
		}

		// Atomic. Exactly one concurrent caller can win this.
		if ( ! Paystack_Babe_Store::claim( $reference, $paid_major ) ) {
			self::log( sprintf( '[%s] %s already claimed elsewhere — no double credit.', $source, $reference ), 'info' );

			return self::RESULT_ALREADY;
		}

		BABE_Payments::do_complete_order(
			$order_id,
			PAYSTACK_BABE_METHOD,
			$reference,
			$paid_major,
			$paid_ccy,
			array( 'source' => $source )
		);

		self::log( sprintf( '[%s] Completed order %d from %s (%s %s).', $source, $order_id, $reference, $paid_major, $paid_ccy ), 'info' );

		return self::RESULT_FULFILLED;
	}

	/**
	 * Stash a failure message where the guest's next page load can show it.
	 *
	 * Replaces a `$_SESSION` key that nothing ever read — every payment failure
	 * was silent, and native sessions interact badly with page caches anyway.
	 *
	 * @param int    $order_id Order id.
	 * @param string $message  Message to show.
	 */
	private static function remember_error( $order_id, $message ) {
		if ( $order_id ) {
			set_transient( 'paystack_babe_error_' . $order_id, $message, 15 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Read and clear a stored failure message.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	public static function take_error( $order_id ) {
		$key     = 'paystack_babe_error_' . absint( $order_id );
		$message = get_transient( $key );

		if ( $message ) {
			delete_transient( $key );
		}

		return $message ? (string) $message : '';
	}

	/**
	 * BABE's checkout page for an order, so a failed payment can be retried.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	private static function checkout_url( $order_id ) {
		if ( $order_id && method_exists( 'BABE_Order', 'get_order_checkout_page' ) ) {
			$url = BABE_Order::get_order_checkout_page( $order_id );
			if ( ! empty( $url ) ) {
				return $url;
			}
		}

		return home_url();
	}

	/**
	 * BABE's confirmation page for an order.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	private static function confirmation_url( $order_id ) {
		if ( $order_id && method_exists( 'BABE_Order', 'get_order_confirmation_page' ) ) {
			$url = BABE_Order::get_order_confirmation_page( $order_id );
			if ( ! empty( $url ) ) {
				return $url;
			}
		}

		return home_url();
	}

	/**
	 * Record a payment event.
	 *
	 * Money events are logged unconditionally. Gating these behind WP_DEBUG —
	 * which is off on any sane production site — meant a guest reporting "I paid
	 * but my booking says unpaid" left no trail at all to reconstruct.
	 *
	 * @param string $message Message.
	 * @param string $level   'info', 'warning' or 'error'.
	 */
	private static function log( $message, $level = 'info' ) {
		/**
		 * Fires for every payment event, so a site can route these into its own
		 * logging stack.
		 *
		 * @param string $message Message.
		 * @param string $level   Severity.
		 */
		do_action( 'paystack_babe_log', $message, $level );

		if ( 'info' !== $level || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			error_log( '[booking-gateway-for-paystack][' . $level . '] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
