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

		if ( class_exists( 'BABE_Settings' ) && isset( BABE_Settings::$option_name ) ) {
			add_filter( 'babe_sanitize_' . BABE_Settings::$option_name, array( Paystack_Babe_Settings::class, 'sanitize' ), 10, 2 );
		}
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

		return '' !== $configured ? $configured : __( 'Pay with Paystack', 'paystack-babe' );
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
			return __( 'Paystack is in TEST mode — no real payment will be taken.', 'paystack-babe' );
		}

		return __( 'Pay securely by card, bank transfer or USSD.', 'paystack-babe' );
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
			self::fail( __( 'Could not identify the booking to pay for.', 'paystack-babe' ), $current_url );
		}

		if ( ! Paystack_Babe_Settings::is_configured() ) {
			self::fail( __( 'Paystack is not configured. Please contact the property.', 'paystack-babe' ), $current_url );
		}

		$amount   = self::resolve_amount( $order_id, $args );
		$currency = self::resolve_currency( $order_id );

		if ( $amount <= 0 ) {
			self::fail( __( 'This booking has nothing left to pay.', 'paystack-babe' ), $current_url );
		}

		if ( ! Paystack_Babe_Api::supports_currency( $currency ) ) {
			self::fail(
				sprintf(
					/* translators: %s: ISO currency code. */
					__( 'Paystack does not support %s.', 'paystack-babe' ),
					$currency
				),
				$current_url
			);
		}

		$reference = self::build_reference( $order_id );
		$email     = self::resolve_email( $order_id, $args );

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
					'source'        => 'paystack-babe',
					'cancel_action' => $current_url,
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			// Paystack's own message is far more useful than anything generic
			// we could invent — "Currency not supported by merchant" tells the
			// operator exactly what to fix.
			self::fail( $result->get_error_message(), $current_url );
		}

		if ( empty( $result['authorization_url'] ) ) {
			self::fail( __( 'Paystack did not return a checkout URL.', 'paystack-babe' ), $current_url );
		}

		// Remember where to send the guest back to. Stored against the
		// reference so the callback can recover it without trusting the query
		// string it is handed.
		set_transient(
			'paystack_babe_return_' . $reference,
			array(
				'order_id'    => $order_id,
				'success_url' => $success_url,
				'cancel_url'  => $current_url,
				'amount'      => $amount,
				'currency'    => $currency,
			),
			DAY_IN_SECONDS
		);

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
		if ( isset( $args['amount'] ) && is_numeric( $args['amount'] ) && (float) $args['amount'] > 0 ) {
			return (float) $args['amount'];
		}

		if ( method_exists( 'BABE_Order', 'get_order_prepaid_amount' ) ) {
			$prepaid = (float) BABE_Order::get_order_prepaid_amount( $order_id );
			if ( $prepaid > 0 ) {
				return $prepaid;
			}
		}

		if ( method_exists( 'BABE_Order', 'get_order_total_amount' ) ) {
			return (float) BABE_Order::get_order_total_amount( $order_id );
		}

		return 0.0;
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
	private static function fail( $message, $redirect_to = '' ) {
		if ( ! session_id() && ! headers_sent() ) {
			@session_start(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$_SESSION['paystack_babe_error'] = $message;

		wp_redirect( $redirect_to ? esc_url_raw( $redirect_to ) : home_url() );
		exit;
	}
}
