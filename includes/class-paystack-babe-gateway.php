<?php
/**
 * BA Book Everything gateway registration and checkout redirect.
 *
 * Hook names target BA Book Everything 1.8.x. The older 1.7.x equivalents
 * (babe_payment_methods_init, babe_order_to_pay_by_, babe_checkout_payment_fields_)
 * were deprecated in 1.7.24 and deleted in 1.8.26, so binding to them would
 * break on any current install. The hooks used here exist in both.
 *
 * @package PaystackBabe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Paystack_Babe_Gateway
 */
class Paystack_Babe_Gateway {

	/**
	 * Wire up all BABE integration points.
	 */
	public static function init() {
		$method = PAYSTACK_BABE_METHOD;

		add_action( 'babe_init_payment_methods', array( __CLASS__, 'register_method' ) );
		add_action( 'babe_settings_payment_method_' . $method, array( Paystack_Babe_Settings::class, 'render_fields' ), 10, 3 );

		add_filter( 'babe_checkout_payment_title_' . $method, array( __CLASS__, 'checkout_title' ) );
		add_filter( 'babe_checkout_payment_description_' . $method, array( __CLASS__, 'checkout_description' ) );

		// BABE fires this with five arguments; the fifth (customer id) is
		// dropped by some existing integrations, which is why it is easy to
		// miss. We take all five.
		add_action( 'babe_order_start_paying_with_' . $method, array( __CLASS__, 'start_payment' ), 10, 5 );

		Paystack_Babe_Settings::register();
	}

	/**
	 * Register the payment method with BABE.
	 */
	public static function register_method() {
		BABE_Payments::add_payment_method( PAYSTACK_BABE_METHOD, self::checkout_title( '' ) );
	}

	/**
	 * Checkout tab title.
	 *
	 * @param string $title Incoming title.
	 * @return string
	 */
	public static function checkout_title( $title ) {
		$configured = Paystack_Babe_Settings::get( Paystack_Babe_Settings::KEY_TAB_TITLE );

		return '' !== $configured ? $configured : __( 'Pay with Paystack', 'booking-gateway-for-paystack' );
	}

	/**
	 * Checkout description.
	 *
	 * @param string $description Incoming description.
	 * @return string
	 */
	public static function checkout_description( $description ) {
		$configured = Paystack_Babe_Settings::get( Paystack_Babe_Settings::KEY_DESCRIPTION );

		if ( '' !== $configured ) {
			return $configured;
		}

		if ( Paystack_Babe_Settings::is_test_mode() ) {
			return __( 'Paystack is in TEST mode — no real payment will be taken.', 'booking-gateway-for-paystack' );
		}

		return __( 'Pay securely by card, bank transfer or USSD.', 'booking-gateway-for-paystack' );
	}

	/**
	 * Begin payment: initialise with Paystack and redirect to their checkout.
	 *
	 * @param int    $order_id    BABE order id.
	 * @param array  $args        Payment arguments supplied by BABE.
	 * @param string $current_url URL the guest is currently on.
	 * @param string $success_url URL to return to after payment.
	 * @param int    $customer_id BABE customer id.
	 */
	public static function start_payment( $order_id, $args = array(), $current_url = '', $success_url = '', $customer_id = 0 ) {
		$order_id = absint( $order_id );

		if ( ! $order_id ) {
			self::fail( __( 'Could not identify the booking to pay for.', 'booking-gateway-for-paystack' ), $current_url, $order_id );
		}

		if ( ! Paystack_Babe_Settings::is_configured() ) {
			self::fail( __( 'Paystack is not configured. Please contact the property.', 'booking-gateway-for-paystack' ), $current_url, $order_id );
		}

		$amount   = self::resolve_amount( $order_id, $args );
		$currency = self::resolve_currency( $order_id );

		if ( $amount <= 0 ) {
			self::fail( __( 'This booking has nothing left to pay.', 'booking-gateway-for-paystack' ), $current_url, $order_id );
		}

		if ( ! Paystack_Babe_Api::supports_currency( $currency ) ) {
			self::fail(
				sprintf(
					/* translators: %s: ISO currency code. */
					__( 'Paystack does not support %s.', 'booking-gateway-for-paystack' ),
					$currency
				),
				$current_url, $order_id );
		}

		$reference = self::build_reference( $order_id );
		$email     = self::resolve_email( $order_id, $args );

		// Record the payment BEFORE sending the guest to Paystack. If this row
		// does not exist, the callback and webhook will refuse the reference —
		// which is what stops the unauthenticated callback being used to make
		// unlimited API calls on the merchant's key.
		if ( ! Paystack_Babe_Store::record_issued( $reference, $order_id, $amount, $currency ) ) {
			self::fail( __( 'Could not start the payment. Please try again.', 'booking-gateway-for-paystack' ), $current_url, $order_id );
		}

		$api    = new Paystack_Babe_Api( Paystack_Babe_Settings::secret_key() );
		$result = $api->initialize_transaction(
			array(
				'email'        => $email,
				'amount'       => Paystack_Babe_Api::to_subunit( $amount ),
				'currency'     => $currency,
				'reference'    => $reference,
				'callback_url' => Paystack_Babe_Webhook::callback_url(),
				'metadata'     => array(
					'babe_order_id' => $order_id,
					'customer_id'   => absint( $customer_id ),
					'source'        => 'booking-gateway-for-paystack',
					'cancel_action' => $current_url,
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			// Paystack's own message is far more useful than anything generic
			// we could invent — "Currency not supported by merchant" tells the
			// operator exactly what to fix.
			self::fail( $result->get_error_message(), $current_url, $order_id );
		}

		if ( empty( $result['authorization_url'] ) ) {
			self::fail( __( 'Paystack did not return a checkout URL.', 'booking-gateway-for-paystack' ), $current_url, $order_id );
		}

		wp_redirect( esc_url_raw( $result['authorization_url'] ) );
		exit;
	}

	/**
	 * Work out how much to charge now.
	 *
	 * BABE supports deposits, so the amount due at checkout is not always the
	 * order total. We prefer whatever BABE hands us, fall back to its deposit
	 * amount, and only then to the full total.
	 *
	 * @param int   $order_id Order id.
	 * @param array $args     Payment args from BABE.
	 * @return float
	 */
	private static function resolve_amount( $order_id, array $args ) {
		$total = method_exists( 'BABE_Order', 'get_order_total_amount' )
			? (float) BABE_Order::get_order_total_amount( $order_id )
			: 0.0;

		$deposit = method_exists( 'BABE_Order', 'get_order_prepaid_amount' )
			? (float) BABE_Order::get_order_prepaid_amount( $order_id )
			: 0.0;

		// BABE puts the guest's choice in $args['payment']['amount_to_pay'] as
		// the string 'full' or 'deposit'. Reading it is not optional: where a
		// booking offers both, ignoring it charged the deposit even when the
		// guest had explicitly chosen to pay in full — and BABE then marks the
		// order fully settled, silently writing off the balance.
		$choice = '';
		if ( isset( $args['payment']['amount_to_pay'] ) && is_scalar( $args['payment']['amount_to_pay'] ) ) {
			$choice = (string) $args['payment']['amount_to_pay'];
		}

		if ( 'full' === $choice && $total > 0 ) {
			return $total;
		}

		if ( 'deposit' === $choice && $deposit > 0 ) {
			return $deposit;
		}

		// No explicit choice: fall back to the deposit when one is due,
		// otherwise the full total.
		if ( $deposit > 0 ) {
			return $deposit;
		}

		return $total;
	}

	/**
	 * Determine the order currency.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	private static function resolve_currency( $order_id ) {
		if ( method_exists( 'BABE_Order', 'get_order_currency' ) ) {
			$currency = BABE_Order::get_order_currency( $order_id );
			if ( ! empty( $currency ) ) {
				return strtoupper( $currency );
			}
		}

		$fallback = Paystack_Babe_Settings::get( 'currency', 'NGN' );

		return strtoupper( $fallback );
	}

	/**
	 * Find the guest's email address.
	 *
	 * Paystack requires a valid address and rejects the transaction outright if
	 * it is malformed, so this walks several sources and validates each one.
	 *
	 * Order of preference:
	 *   1. Whatever BABE handed us in $args.
	 *   2. The order's own customer email (the address typed at checkout).
	 *   3. The full customer details array, in case the direct getter is absent.
	 *   4. The logged-in user.
	 *   5. The site admin — last resort, so the payment is traceable rather
	 *      than failing, though it means the receipt goes to the wrong person.
	 *
	 * @param int   $order_id Order id.
	 * @param array $args     Payment args.
	 * @return string
	 */
	private static function resolve_email( $order_id, array $args ) {
		if ( ! empty( $args['email'] ) && is_email( $args['email'] ) ) {
			return $args['email'];
		}

		if ( method_exists( 'BABE_Order', 'get_order_customer_email' ) ) {
			$email = BABE_Order::get_order_customer_email( $order_id );
			if ( ! empty( $email ) && is_email( $email ) ) {
				return $email;
			}
		}

		if ( method_exists( 'BABE_Order', 'get_order_customer_details' ) ) {
			$details = BABE_Order::get_order_customer_details( $order_id );
			if ( is_array( $details ) && ! empty( $details['email'] ) && is_email( $details['email'] ) ) {
				return $details['email'];
			}
		}

		$user = wp_get_current_user();
		if ( $user && ! empty( $user->user_email ) && is_email( $user->user_email ) ) {
			return $user->user_email;
		}

		return get_option( 'admin_email' );
	}

	/**
	 * Build a unique transaction reference.
	 *
	 * The order id is embedded so the callback and webhook can both recover it
	 * without depending on Paystack echoing our metadata back. Paystack only
	 * permits alphanumerics plus - . = in references.
	 *
	 * A fresh reference is generated per attempt: re-using one across retries
	 * is rejected as a duplicate, which would silently strand a guest who
	 * abandoned a first attempt and came back.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	private static function build_reference( $order_id ) {
		return sprintf( 'BABE-%d-%d-%s', absint( $order_id ), time(), wp_generate_password( 6, false, false ) );
	}

	/**
	 * Extract the order id from a reference produced by build_reference().
	 *
	 * @param string $reference Transaction reference.
	 * @return int Zero when the reference is not ours.
	 */
	public static function order_id_from_reference( $reference ) {
		return preg_match( '/^BABE-(\d+)-/', (string) $reference, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Abort payment with a message and send the guest back to checkout.
	 *
	 * BABE's checkout has no error slot of its own, so the message is stashed
	 * in the session and surfaced by the checkout template. Crucially the order
	 * status is left alone — setting it to payment_processing or canceled would
	 * permanently block the guest from trying again.
	 *
	 * @param string $message     Error message.
	 * @param string $redirect_to URL to return to.
	 */
	private static function fail( $message, $redirect_to = '', $order_id = 0 ) {
		/**
		 * Fires when a payment could not be started.
		 *
		 * @param string $message  Reason.
		 * @param int    $order_id Order id, if known.
		 */
		do_action( 'paystack_babe_payment_failed', $message, $order_id );

		// Logged unconditionally. This used to write to a $_SESSION key that
		// nothing read and log nothing at all, so an operator whose keys were
		// missing or whose currency was unsupported had no way to discover that
		// guests were being turned away.
		error_log( '[booking-gateway-for-paystack][error] start_payment: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		if ( $order_id ) {
			set_transient( 'paystack_babe_error_' . absint( $order_id ), $message, 15 * MINUTE_IN_SECONDS );
		}

		wp_redirect( $redirect_to ? esc_url_raw( $redirect_to ) : home_url() );
		exit;
	}
}
