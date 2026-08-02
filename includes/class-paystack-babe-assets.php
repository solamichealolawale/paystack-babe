<?php
/**
 * Front-end presentation for the Paystack payment tab.
 *
 * BA Book Everything renders payment methods as plain text tabs, so an
 * unbranded "Pay with Paystack" gives guests nothing to recognise at the moment
 * they are about to hand over card details. This adds the Paystack mark and a
 * little structure to that tab.
 *
 * The mark is an inline SVG data URI rather than a bundled file so there is no
 * extra HTTP request and nothing to break if the plugin directory moves. It is
 * applied via CSS rather than by returning HTML from the title filter, because
 * that same title string is also used as the method label in wp-admin, where
 * markup would leak into the settings screen.
 *
 * @package PaystackBabe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Paystack_Babe_Assets
 */
class Paystack_Babe_Assets {

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 100 );
	}

	/**
	 * The Paystack mark: four stacked bars, in Paystack's cyan.
	 *
	 * @return string A data: URI suitable for use in CSS.
	 */
	private static function mark_data_uri() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 22">'
			. '<g fill="#00C3F7">'
			. '<rect x="0" y="0"    width="24" height="4" rx="1"/>'
			. '<rect x="0" y="6"    width="24" height="4" rx="1"/>'
			. '<rect x="0" y="12"   width="24" height="4" rx="1"/>'
			. '<rect x="0" y="18"   width="14" height="4" rx="1"/>'
			. '</g></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Enqueue the tab styling.
	 */
	public static function enqueue() {
		$mark = self::mark_data_uri();

		$css = "
/* Paystack mark on the payment-method tab. */
.payment_method_title_paystack {
	background-image: url('{$mark}');
	background-repeat: no-repeat;
	background-position: 14px center;
	background-size: 18px auto;
	padding-left: 42px !important;
}

/* Give the tab strip a little breathing room so the tabs read as buttons. */
.payment_titles_group .payment_method_title {
	padding: 12px 16px;
	cursor: pointer;
}

/* The description panel is otherwise an unpadded empty-looking box. */
.payment_method_fields_paystack {
	padding: 12px 16px;
}
";

		wp_register_style( 'booking-gateway-for-paystack', false, array(), PAYSTACK_BABE_VERSION );
		wp_enqueue_style( 'booking-gateway-for-paystack' );
		wp_add_inline_style( 'booking-gateway-for-paystack', $css );
	}
}
