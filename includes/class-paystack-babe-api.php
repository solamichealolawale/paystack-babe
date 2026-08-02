<?php
/**
 * Thin Paystack REST client.
 *
 * Deliberately has no dependency on the official PHP SDK. Both official PHP
 * libraries (paystack-php, omnipay-paystack) are effectively unmaintained,
 * while the two endpoints we need are trivial over wp_remote_*.
 *
 * @package PaystackBabe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Paystack_Babe_Api
 */
class Paystack_Babe_Api {

	const BASE_URL = 'https://api.paystack.co';

	/**
	 * Currencies Paystack supports, mapped to whether they use a 100x subunit.
	 *
	 * Every listed currency is sent multiplied by 100. XOF has no subunit in
	 * reality, but Paystack's docs are explicit that it must still be
	 * multiplied by 100, so it is treated identically here.
	 *
	 * @var string[]
	 */
	const SUPPORTED_CURRENCIES = array( 'NGN', 'GHS', 'ZAR', 'KES', 'USD', 'XOF' );

	/**
	 * Secret key used for API calls.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Constructor.
	 *
	 * @param string $secret_key Paystack secret key (sk_test_… or sk_live_…).
	 */
	public function __construct( $secret_key ) {
		$this->secret_key = trim( (string) $secret_key );
	}

	/**
	 * Initialise a transaction and return the hosted checkout URL.
	 *
	 * @param array $payload Paystack transaction payload.
	 * @return array|WP_Error Decoded `data` object on success.
	 */
	public function initialize_transaction( array $payload ) {
		return $this->request( 'POST', '/transaction/initialize', $payload );
	}

	/**
	 * Verify a transaction by reference.
	 *
	 * This is the only trustworthy source of truth about whether money moved.
	 * Paystack's docs are blunt about it: "Just because the callback_url was
	 * visited doesn't prove that transaction was successful."
	 *
	 * @param string $reference Transaction reference.
	 * @return array|WP_Error Decoded `data` object on success.
	 */
	public function verify_transaction( $reference ) {
		return $this->request( 'GET', '/transaction/verify/' . rawurlencode( $reference ) );
	}

	/**
	 * Perform an API request and unwrap Paystack's envelope.
	 *
	 * Paystack responses carry two different `status` fields and conflating
	 * them is the single most common integration bug:
	 *
	 *   - $body['status']         — did the API call succeed (boolean)
	 *   - $body['data']['status'] — did the payment succeed (string)
	 *
	 * This method handles the former and returns `data` untouched so callers
	 * are forced to inspect the latter themselves.
	 *
	 * @param string $method HTTP verb.
	 * @param string $path   Endpoint path.
	 * @param array  $body   Optional request body.
	 * @return array|WP_Error
	 */
	private function request( $method, $path, array $body = array() ) {
		if ( '' === $this->secret_key ) {
			return new WP_Error( 'paystack_babe_no_key', __( 'No Paystack secret key is configured.', 'booking-gateway-for-paystack' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->secret_key,
				'Content-Type'  => 'application/json',
				'Cache-Control' => 'no-cache',
			),
		);

		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::BASE_URL . $path, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code   = wp_remote_retrieve_response_code( $response );
		$parsed = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $parsed ) ) {
			return new WP_Error(
				'paystack_babe_bad_response',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Paystack returned an unreadable response (HTTP %d).', 'booking-gateway-for-paystack' ),
					$code
				)
			);
		}

		if ( empty( $parsed['status'] ) ) {
			// Surface Paystack's own message — e.g. "Currency not supported by
			// merchant", which is by far the most common first-run failure for
			// Nigerian accounts that have not enabled USD.
			return new WP_Error(
				'paystack_babe_api_error',
				isset( $parsed['message'] ) ? (string) $parsed['message'] : __( 'Paystack rejected the request.', 'booking-gateway-for-paystack' ),
				array( 'http_code' => $code )
			);
		}

		return isset( $parsed['data'] ) && is_array( $parsed['data'] ) ? $parsed['data'] : array();
	}

	/**
	 * Convert a major-unit amount into Paystack's subunit.
	 *
	 * Uses round() rather than intval() so 710.10 becomes 71010 and not 71009,
	 * which float truncation would otherwise produce.
	 *
	 * @param float $amount Amount in major units.
	 * @return int
	 */
	public static function to_subunit( $amount ) {
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * Whether Paystack supports the given currency at all.
	 *
	 * Note this is a platform-level check. An individual merchant may still
	 * lack a currency on their integration — USD in particular has to be
	 * separately enabled for Nigerian accounts — and that only surfaces as an
	 * API error at initialise time.
	 *
	 * @param string $currency ISO currency code.
	 * @return bool
	 */
	public static function supports_currency( $currency ) {
		return in_array( strtoupper( (string) $currency ), self::SUPPORTED_CURRENCIES, true );
	}

	/**
	 * Validate a webhook signature.
	 *
	 * Two things matter here and both are easy to get wrong:
	 *
	 *  1. The HMAC must be computed over the *raw* request body. Re-encoding a
	 *     decoded array produces different bytes and the check will never pass.
	 *  2. The comparison must be constant-time, hence hash_equals().
	 *
	 * @param string $raw_body   Raw POST body.
	 * @param string $signature  Value of the x-paystack-signature header.
	 * @param string $secret_key Paystack secret key.
	 * @return bool
	 */
	public static function verify_webhook_signature( $raw_body, $signature, $secret_key ) {
		if ( '' === (string) $signature || '' === (string) $secret_key ) {
			return false;
		}

		return hash_equals( hash_hmac( 'sha512', $raw_body, $secret_key ), $signature );
	}
}
