<?php
/**
 * Removes everything this plugin stored.
 *
 * Notably this includes the Paystack API keys. They live inside BA Book
 * Everything's shared settings array rather than an option of our own, so
 * deleting the plugin previously left live secret keys sitting in wp_options
 * with no interface to view or purge them — discoverable only in a database
 * dump. Anything holding a credential must be cleaned up on uninstall.
 *
 * @package PaystackBabe
 */

// Only ever runs from WordPress's uninstall flow.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/*
 * 1. Drop the payments table.
 */
$table = $wpdb->prefix . 'paystack_babe_payments';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB

delete_option( 'paystack_babe_schema' );

/*
 * 2. Remove our keys — including the API secrets — from BA Book Everything's
 *    settings array, for every language variant of the option.
 */
$our_keys = array(
	'paystack_babe_test_mode',
	'paystack_babe_test_secret_key',
	'paystack_babe_test_public_key',
	'paystack_babe_live_secret_key',
	'paystack_babe_live_public_key',
	'paystack_babe_tab_title',
	'paystack_babe_description',
);

$option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'babe_settings%'"
);

foreach ( (array) $option_names as $option_name ) {
	$settings = get_option( $option_name );

	if ( ! is_array( $settings ) ) {
		continue;
	}

	$changed = false;
	foreach ( $our_keys as $key ) {
		if ( array_key_exists( $key, $settings ) ) {
			unset( $settings[ $key ] );
			$changed = true;
		}
	}

	if ( $changed ) {
		update_option( $option_name, $settings );
	}
}

/*
 * 3. Clear leftover transients (error messages shown after a failed payment).
 */
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_paystack_babe_%'
	    OR option_name LIKE '_transient_timeout_paystack_babe_%'"
);

/*
 * 4. Remove the legacy fulfilment ledger from releases before the payments
 *    table existed.
 */
delete_option( 'paystack_babe_fulfilled' );
