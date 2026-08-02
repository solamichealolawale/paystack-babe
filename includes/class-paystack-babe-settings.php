<?php
/**
 * Settings storage and admin fields.
 *
 * Values live inside BA Book Everything's own settings array rather than a
 * separate option, so they appear under BA Settings → Payments alongside the
 * other payment methods and are exported/imported with the rest of BABE's
 * configuration.
 *
 * @package PaystackBabe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Paystack_Babe_Settings
 */
class Paystack_Babe_Settings {

	/**
	 * Setting keys, namespaced to avoid colliding with BABE or other add-ons.
	 */
	const KEY_TEST_MODE      = 'paystack_babe_test_mode';
	const KEY_TEST_SECRET    = 'paystack_babe_test_secret_key';
	const KEY_TEST_PUBLIC    = 'paystack_babe_test_public_key';
	const KEY_LIVE_SECRET    = 'paystack_babe_live_secret_key';
	const KEY_LIVE_PUBLIC    = 'paystack_babe_live_public_key';
	const KEY_TAB_TITLE      = 'paystack_babe_tab_title';
	const KEY_DESCRIPTION    = 'paystack_babe_description';

	/**
	 * Register the settings hooks.
	 *
	 * Kept in this class rather than the gateway so the filter registration and
	 * the callback that implements it are read together — the argument-order bug
	 * that made saving impossible survived review precisely because they lived in
	 * different files.
	 */
	public static function register() {
		if ( ! class_exists( 'BABE_Settings' ) || ! isset( BABE_Settings::$option_name ) ) {
			return;
		}

		$filter = 'babe_sanitize_' . BABE_Settings::$option_name;

		// Snapshot first, so a save of some other settings section cannot drop
		// values that are not present in that submission.
		add_filter( $filter, array( __CLASS__, 'snapshot_current_filter' ), 5, 2 );
		add_filter( $filter, array( __CLASS__, 'sanitize' ), 10, 2 );
	}

	/**
	 * Filter shim for the pre-save snapshot.
	 *
	 * @param array $sanitized BABE's rebuilt whitelist.
	 * @param array $raw_input Raw submitted values.
	 * @return array Unmodified.
	 */
	public static function snapshot_current_filter( $sanitized, $raw_input = array() ) {
		self::snapshot_current();

		return $sanitized;
	}

	/**
	 * Read a single setting from BABE's settings array.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when unset.
	 * @return mixed
	 */
	public static function get( $key, $default = '' ) {
		if ( ! class_exists( 'BABE_Settings' ) || ! isset( BABE_Settings::$settings ) ) {
			return $default;
		}

		$settings = BABE_Settings::$settings;

		return isset( $settings[ $key ] ) && '' !== $settings[ $key ] ? $settings[ $key ] : $default;
	}

	/**
	 * Whether the gateway is in test mode.
	 *
	 * Defaults to true. A misconfigured plugin should fail towards test keys,
	 * never towards taking real money by accident.
	 *
	 * @return bool
	 */
	public static function is_test_mode() {
		return '0' !== (string) self::get( self::KEY_TEST_MODE, '1' );
	}

	/**
	 * Active secret key for the current mode.
	 *
	 * @return string
	 */
	public static function secret_key() {
		return trim( (string) self::get( self::is_test_mode() ? self::KEY_TEST_SECRET : self::KEY_LIVE_SECRET ) );
	}

	/**
	 * Active public key for the current mode.
	 *
	 * @return string
	 */
	public static function public_key() {
		return trim( (string) self::get( self::is_test_mode() ? self::KEY_TEST_PUBLIC : self::KEY_LIVE_PUBLIC ) );
	}

	/**
	 * Whether enough configuration exists to attempt a payment.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::secret_key();
	}

	/**
	 * Register admin fields on BABE's Payments settings section.
	 *
	 * BABE fires this action for each registered method and hands us the
	 * section id, settings page slug and option name it wants us to write into.
	 *
	 * @param string $section_id    Settings section id.
	 * @param string $settings_page Settings page slug.
	 * @param string $option_name   Option name the fields serialise into.
	 */
	public static function render_fields( $section_id, $settings_page, $option_name ) {
		$fields = array(
			self::KEY_TAB_TITLE   => array(
				'label' => __( 'Payment tab title', 'booking-gateway-for-paystack' ),
				'type'  => 'text',
				'desc'  => __( 'Shown to guests on the checkout page. Defaults to "Pay with Paystack".', 'booking-gateway-for-paystack' ),
			),
			self::KEY_DESCRIPTION => array(
				'label' => __( 'Payment description', 'booking-gateway-for-paystack' ),
				'type'  => 'textarea',
				'desc'  => __( 'Optional text shown under the payment tab title.', 'booking-gateway-for-paystack' ),
			),
			self::KEY_TEST_MODE   => array(
				'label' => __( 'Test mode', 'booking-gateway-for-paystack' ),
				'type'  => 'checkbox',
				'desc'  => __( 'Use test API keys. No real money moves while this is on.', 'booking-gateway-for-paystack' ),
			),
			self::KEY_TEST_SECRET => array(
				'label' => __( 'Test secret key', 'booking-gateway-for-paystack' ),
				'type'  => 'password',
				'desc'  => __( 'Starts with sk_test_. Never share this key.', 'booking-gateway-for-paystack' ),
			),
			self::KEY_TEST_PUBLIC => array(
				'label' => __( 'Test public key', 'booking-gateway-for-paystack' ),
				'type'  => 'text',
				'desc'  => __( 'Starts with pk_test_.', 'booking-gateway-for-paystack' ),
			),
			self::KEY_LIVE_SECRET => array(
				'label' => __( 'Live secret key', 'booking-gateway-for-paystack' ),
				'type'  => 'password',
				'desc'  => __( 'Starts with sk_live_. Never share this key.', 'booking-gateway-for-paystack' ),
			),
			self::KEY_LIVE_PUBLIC => array(
				'label' => __( 'Live public key', 'booking-gateway-for-paystack' ),
				'type'  => 'text',
				'desc'  => __( 'Starts with pk_live_.', 'booking-gateway-for-paystack' ),
			),
		);

		foreach ( $fields as $key => $field ) {
			add_settings_field(
				$key,
				esc_html( $field['label'] ),
				array( __CLASS__, 'render_field' ),
				$settings_page,
				$section_id,
				array(
					'key'         => $key,
					'type'        => $field['type'],
					'desc'        => $field['desc'],
					'option_name' => $option_name,
					'label_for'   => $key,
				)
			);
		}

		self::render_webhook_hint( $settings_page, $section_id, $option_name );
	}

	/**
	 * Show the webhook URL so it can be pasted into the Paystack dashboard.
	 *
	 * @param string $settings_page Settings page slug.
	 * @param string $section_id    Section id.
	 * @param string $option_name   Option name.
	 */
	private static function render_webhook_hint( $settings_page, $section_id, $option_name ) {
		add_settings_field(
			'paystack_babe_webhook_url',
			esc_html__( 'Webhook URL', 'booking-gateway-for-paystack' ),
			function () {
				printf(
					'<code style="user-select:all">%s</code><p class="description">%s</p>',
					esc_html( Paystack_Babe_Webhook::endpoint_url() ),
					esc_html__( 'Paste this into Paystack Dashboard → Settings → API Keys & Webhooks. Without it, a guest who closes their browser mid-payment will be charged without the booking being marked paid.', 'booking-gateway-for-paystack' )
				);
			},
			$settings_page,
			$section_id,
			array( 'option_name' => $option_name )
		);
	}

	/**
	 * Render a single settings field.
	 *
	 * @param array $args Field arguments.
	 */
	public static function render_field( $args ) {
		$key   = $args['key'];
		$name  = $args['option_name'] . '[' . $key . ']';
		$value = self::get( $key, self::KEY_TEST_MODE === $key ? '1' : '' );

		switch ( $args['type'] ) {
			case 'checkbox':
				printf(
					'<input type="hidden" name="%1$s" value="0" /><input type="checkbox" id="%2$s" name="%1$s" value="1" %3$s />',
					esc_attr( $name ),
					esc_attr( $key ),
					checked( '1', (string) $value, false )
				);
				break;

			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="3" class="large-text">%3$s</textarea>',
					esc_attr( $key ),
					esc_attr( $name ),
					esc_textarea( $value )
				);
				break;

			case 'password':
				printf(
					'<input type="password" id="%1$s" name="%2$s" value="%3$s" class="regular-text" autocomplete="off" />',
					esc_attr( $key ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;

			default:
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text" />',
					esc_attr( $key ),
					esc_attr( $name ),
					esc_attr( $value )
				);
		}

		if ( ! empty( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
		}
	}

	/**
	 * Carry our keys through BABE's settings save.
	 *
	 * BABE fires this as:
	 *
	 *     apply_filters( 'babe_sanitize_' . $option_name, $sanitized, $raw_input )
	 *
	 * where `$sanitized` is an array BABE rebuilds *from scratch* containing only
	 * its own whitelisted keys, and `$raw_input` is the untouched `$_POST` data.
	 *
	 * BABE knows nothing about our fields, so they exist only in `$raw_input`.
	 * Reading them from `$sanitized` — as this did until it was caught in review —
	 * means nothing is ever copied across and every Paystack setting is silently
	 * discarded on save, wiping working keys whenever *any* BABE settings section
	 * is saved. Read from the raw input, write into the sanitized array.
	 *
	 * @param array $sanitized BABE's rebuilt whitelist. Must be returned.
	 * @param array $raw_input Raw submitted values, where our fields actually live.
	 * @return array
	 */
	public static function sanitize( $sanitized, $raw_input = array() ) {
		if ( ! is_array( $sanitized ) ) {
			return $sanitized;
		}

		if ( ! is_array( $raw_input ) ) {
			$raw_input = array();
		}

		$text_keys = array(
			self::KEY_TEST_SECRET,
			self::KEY_TEST_PUBLIC,
			self::KEY_LIVE_SECRET,
			self::KEY_LIVE_PUBLIC,
			self::KEY_TAB_TITLE,
		);

		foreach ( $text_keys as $key ) {
			if ( isset( $raw_input[ $key ] ) ) {
				$sanitized[ $key ] = sanitize_text_field( wp_unslash( $raw_input[ $key ] ) );
			} elseif ( isset( self::$settings_cache[ $key ] ) ) {
				// Field absent from this submission (e.g. a different settings
				// section was saved) — preserve what is already stored rather
				// than dropping it.
				$sanitized[ $key ] = self::$settings_cache[ $key ];
			}
		}

		if ( isset( $raw_input[ self::KEY_DESCRIPTION ] ) ) {
			$sanitized[ self::KEY_DESCRIPTION ] = wp_kses_post( wp_unslash( $raw_input[ self::KEY_DESCRIPTION ] ) );
		} elseif ( isset( self::$settings_cache[ self::KEY_DESCRIPTION ] ) ) {
			$sanitized[ self::KEY_DESCRIPTION ] = self::$settings_cache[ self::KEY_DESCRIPTION ];
		}

		// An unchecked checkbox submits nothing, so the hidden companion field is
		// what distinguishes "turned off" from "not on this form at all".
		if ( isset( $raw_input[ self::KEY_TEST_MODE ] ) ) {
			$sanitized[ self::KEY_TEST_MODE ] = '1' === (string) $raw_input[ self::KEY_TEST_MODE ] ? '1' : '0';
		} elseif ( isset( self::$settings_cache[ self::KEY_TEST_MODE ] ) ) {
			$sanitized[ self::KEY_TEST_MODE ] = self::$settings_cache[ self::KEY_TEST_MODE ];
		}

		return $sanitized;
	}

	/**
	 * Snapshot of our stored settings, taken before BABE overwrites the option.
	 *
	 * @var array
	 */
	private static $settings_cache = array();

	/**
	 * Capture current values so a partial save cannot drop them.
	 *
	 * Runs on the same filter as sanitize() but at an earlier priority, while
	 * BABE_Settings::$settings still reflects what is on disk.
	 */
	public static function snapshot_current() {
		if ( class_exists( 'BABE_Settings' ) && isset( BABE_Settings::$settings ) && is_array( BABE_Settings::$settings ) ) {
			self::$settings_cache = BABE_Settings::$settings;
		}
	}
}
