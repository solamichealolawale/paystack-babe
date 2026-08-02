<?php
/**
 * Plugin Name:       Booking Gateway for Paystack and BA Book Everything
 * Plugin URI:        https://github.com/solamichealolawale/booking-gateway-for-paystack
 * Description:       Adds Paystack as a payment method to the BA Book Everything booking plugin. Supports NGN, GHS, ZAR, KES, USD and XOF, test mode, server-side verification and signed webhooks.
 * Version:           0.4.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Olusola Olawale
 * Author URI:        https://github.com/solamichealolawale
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       booking-gateway-for-paystack
 *
 * @package PaystackBabe
 */

defined( 'ABSPATH' ) || exit;

define( 'PAYSTACK_BABE_VERSION', '0.4.0' );
define( 'PAYSTACK_BABE_FILE', __FILE__ );
define( 'PAYSTACK_BABE_PATH', plugin_dir_path( __FILE__ ) );

/**
 * The payment method key registered with BA Book Everything.
 *
 * This value is baked into hook names (babe_order_start_paying_with_paystack),
 * the webhook URL (babe-api/ipn_paystack) and stored payment tokens. Changing it
 * after go-live orphans in-flight payments, so treat it as permanent.
 */
define( 'PAYSTACK_BABE_METHOD', 'paystack' );

require_once PAYSTACK_BABE_PATH . 'includes/class-paystack-babe-api.php';
require_once PAYSTACK_BABE_PATH . 'includes/class-paystack-babe-settings.php';
require_once PAYSTACK_BABE_PATH . 'includes/class-paystack-babe-gateway.php';
require_once PAYSTACK_BABE_PATH . 'includes/class-paystack-babe-webhook.php';
require_once PAYSTACK_BABE_PATH . 'includes/class-paystack-babe-assets.php';

/**
 * Boot the plugin once all plugins are loaded.
 *
 * BA Book Everything is a hard dependency. If it is missing or too old we bail
 * out quietly and show an admin notice rather than fataling — a payment plugin
 * that white-screens the site is worse than one that is simply absent.
 */
function paystack_babe_bootstrap() {
	if ( ! paystack_babe_dependency_met() ) {
		add_action( 'admin_notices', 'paystack_babe_dependency_notice' );
		return;
	}

	Paystack_Babe_Gateway::init();
	Paystack_Babe_Webhook::init();
	Paystack_Babe_Assets::init();
}
add_action( 'plugins_loaded', 'paystack_babe_bootstrap', 20 );

/**
 * Check that BA Book Everything is active and exposes the API we bind to.
 *
 * We assert on the specific symbols we use rather than on a version number.
 * BABE deleted several hooks between 1.7.24 and 1.8.26 with no major version
 * bump, and because it couples via do_action()/apply_filters() a removed hook
 * fails *silently* — the gateway would just stop appearing, or stop completing
 * orders, with no error anywhere. An explicit capability check turns that
 * invisible failure into a visible one.
 *
 * @return bool
 */
function paystack_babe_dependency_met() {
	return class_exists( 'BABE_Payments' )
		&& class_exists( 'BABE_Order' )
		&& method_exists( 'BABE_Payments', 'add_payment_method' )
		&& method_exists( 'BABE_Payments', 'do_complete_order' );
}

/**
 * Render the dependency failure notice.
 */
function paystack_babe_dependency_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$message = class_exists( 'BABE_Payments' )
		? __( 'Booking Gateway for Paystack is inactive: the installed version of BA Book Everything does not expose the payment API this plugin needs. Payments will not be offered. Check for a BA Book Everything update, or report this at the plugin repository.', 'booking-gateway-for-paystack' )
		: __( 'Booking Gateway for Paystack requires the BA Book Everything plugin to be installed and active.', 'booking-gateway-for-paystack' );

	printf(
		'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
		esc_html__( 'Booking Gateway for Paystack', 'booking-gateway-for-paystack' ),
		esc_html( $message )
	);
}
