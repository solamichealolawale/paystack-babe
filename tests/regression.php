<?php
/**
 * Regression tests for every finding raised in the four independent reviews.
 * Run with: php ../wp-cli.phar eval-file pbg-tests.php
 */

$GLOBALS["pbg_pass"] = 0;
$GLOBALS["pbg_fail"] = 0;

function t( $name, $got, $want, $note = '' ) {
	$ok = ( $got === $want );
	$ok ? $GLOBALS["pbg_pass"]++ : $GLOBALS["pbg_fail"]++;
	printf(
		"%s  %-58s %s\n",
		$ok ? ' PASS' : '*FAIL',
		$name,
		$ok ? '' : sprintf( 'got %s, want %s %s', var_export( $got, true ), var_export( $want, true ), $note )
	);
}

echo "\n=== A. SETTINGS PERSISTENCE (blocker: keys never saved) ===\n";

require_once WP_PLUGIN_DIR . '/ba-book-everything/includes/class-babe-settings-admin.php';
$opt = BABE_Settings::$option_name;

// A1: a direct save through the real pipeline persists our keys.
$base = get_option( $opt );
foreach ( array_keys( $base ) as $k ) {
	if ( 0 === strpos( $k, 'paystack_babe_' ) ) {
		unset( $base[ $k ] );
	}
}
update_option( $opt, $base );
BABE_Settings::$settings = $base;

$raw                                  = $base;
$raw['paystack_babe_test_secret_key'] = 'sk_test_A1PERSIST';
$raw['paystack_babe_test_mode']       = '1';
$out                                  = BABE_Settings_Admin::sanitize_settings( $raw );
t( 'A1 direct save persists secret key', $out['paystack_babe_test_secret_key'] ?? null, 'sk_test_A1PERSIST' );

// A2: saving an unrelated section must not wipe stored keys.
update_option( $opt, $out );
BABE_Settings::$settings = $out;
$other                   = $out;
foreach ( array_keys( $other ) as $k ) {
	if ( 0 === strpos( $k, 'paystack_babe_' ) ) {
		unset( $other[ $k ] );
	}
}
$out2 = BABE_Settings_Admin::sanitize_settings( $other );
t( 'A2 unrelated save preserves secret key', $out2['paystack_babe_test_secret_key'] ?? null, 'sk_test_A1PERSIST' );

// A3: values are sanitized, not trusted raw.
$raw3                                  = $out;
$raw3['paystack_babe_tab_title']       = "Pay <script>alert(1)</script>";
$out3                                  = BABE_Settings_Admin::sanitize_settings( $raw3 );
t( 'A3 tab title is sanitized', false !== strpos( (string) ( $out3['paystack_babe_tab_title'] ?? '' ), '<script>' ), false );

// restore the working key for later tests
$restore                                  = $out;
$restore['paystack_babe_test_secret_key'] = 'sk_test_6ed8c3f5a4a0875955ff537fc988201537e58a2a';
$restore['paystack_babe_test_mode']       = '1';
update_option( $opt, $restore );
BABE_Settings::$settings = $restore;
t( 'A4 plugin reports configured', Paystack_Babe_Settings::is_configured(), true );

echo "\n=== B. ATOMIC CLAIM (blocker: double-credit race) ===\n";

$ref = 'BABE-4242-' . time() . '-' . wp_generate_password( 6, false, false );
Paystack_Babe_Store::record_issued( $ref, 4242, 5000.00, 'NGN' );

t( 'B1 first claim wins', Paystack_Babe_Store::claim( $ref, 5000.00 ), true );
t( 'B2 second claim loses', Paystack_Babe_Store::claim( $ref, 5000.00 ), false );
t( 'B3 third claim loses', Paystack_Babe_Store::claim( $ref, 5000.00 ), false );

$row = Paystack_Babe_Store::get( $ref );
t( 'B4 row marked fulfilled', $row['status'], Paystack_Babe_Store::STATUS_FULFILLED );
t( 'B5 paid amount recorded', (float) $row['paid_amount'], 5000.00 );

// B6: release puts it back so a genuine retry can succeed.
Paystack_Babe_Store::release( $ref );
t( 'B6 release re-opens for retry', Paystack_Babe_Store::get( $ref )['status'], Paystack_Babe_Store::STATUS_ISSUED );
t( 'B7 claim works again after release', Paystack_Babe_Store::claim( $ref, 5000.00 ), true );

echo "\n=== C. UNKNOWN REFERENCES (should-fix: API rate-limit drain) ===\n";

t( 'C1 unissued reference is unknown', Paystack_Babe_Store::get( 'BABE-1-attacker-' . wp_generate_password( 8, false, false ) ), null );
t( 'C2 issued reference is known', is_array( Paystack_Babe_Store::get( $ref ) ), true );

echo "\n=== D. AMOUNT RESOLUTION (blocker: full payment charged as deposit) ===\n";

$m = new ReflectionMethod( 'Paystack_Babe_Gateway', 'resolve_amount' );
$m->setAccessible( true );

// Build an order with a known total and deposit. BABE has no public setter for
// the total (update_order_amount() recalculates from line items), so the meta is
// written directly — these are the exact keys its getters read.
$order_id = BABE_Order::create_order_draft();
update_post_meta( $order_id, '_total_amount', 100000 );
BABE_Order::update_order_prepaid_amount( $order_id, 30000 );

t( 'D0 fixture total is 100000', (float) BABE_Order::get_order_total_amount( $order_id ), 100000.0 );
t( 'D0 fixture deposit is 30000', (float) BABE_Order::get_order_prepaid_amount( $order_id ), 30000.0 );

$full    = $m->invoke( null, $order_id, array( 'payment' => array( 'amount_to_pay' => 'full' ) ) );
$deposit = $m->invoke( null, $order_id, array( 'payment' => array( 'amount_to_pay' => 'deposit' ) ) );
$none    = $m->invoke( null, $order_id, array() );

t( 'D1 choice=full charges the total', (float) $full, 100000.0 );
t( 'D2 choice=deposit charges the deposit', (float) $deposit, 30000.0 );
t( 'D3 no choice falls back to deposit due', (float) $none, 30000.0 );

echo "\n=== E. SUBUNIT CONVERSION (float traps) ===\n";

t( 'E1 710.15 -> 71015', Paystack_Babe_Api::to_subunit( 710.15 ), 71015 );
t( 'E2 2.005 -> 201 (round-half-up)', Paystack_Babe_Api::to_subunit( 2.005 ), 201 );
t( 'E3 0.1+0.2 -> 30', Paystack_Babe_Api::to_subunit( 0.1 + 0.2 ), 30 );
t( 'E4 330000 -> 33000000', Paystack_Babe_Api::to_subunit( 330000 ), 33000000 );

echo "\n=== F. CURRENCY GUARD ===\n";

t( 'F1 NGN supported', Paystack_Babe_Api::supports_currency( 'NGN' ), true );
t( 'F2 lowercase accepted', Paystack_Babe_Api::supports_currency( 'ngn' ), true );
t( 'F3 EUR rejected', Paystack_Babe_Api::supports_currency( 'EUR' ), false );
t( 'F4 empty rejected', Paystack_Babe_Api::supports_currency( '' ), false );

echo "\n=== G. WEBHOOK SIGNATURE ===\n";

$key  = Paystack_Babe_Settings::secret_key();
$body = '{"event":"charge.success","data":{"reference":"X"}}';
$good = hash_hmac( 'sha512', $body, $key );

t( 'G1 valid signature accepted', Paystack_Babe_Api::verify_webhook_signature( $body, $good, $key ), true );
t( 'G2 wrong signature rejected', Paystack_Babe_Api::verify_webhook_signature( $body, 'deadbeef', $key ), false );
t( 'G3 empty signature rejected', Paystack_Babe_Api::verify_webhook_signature( $body, '', $key ), false );
t( 'G4 empty key fails closed', Paystack_Babe_Api::verify_webhook_signature( $body, $good, '' ), false );
t( 'G5 tampered body rejected', Paystack_Babe_Api::verify_webhook_signature( $body . ' ', $good, $key ), false );

echo "\n=== H. ERROR SURFACING (blocker: every failure silent) ===\n";

Paystack_Babe_Webhook::take_error( 4242 ); // clear
set_transient( 'paystack_babe_error_4242', 'Test failure message', 60 );
t( 'H1 error can be read back', Paystack_Babe_Webhook::take_error( 4242 ), 'Test failure message' );
t( 'H2 error is cleared once read', Paystack_Babe_Webhook::take_error( 4242 ), '' );

echo "\n=== I. UNINSTALL (should-fix: live keys left behind) ===\n";

$uninstall = WP_PLUGIN_DIR . '/booking-gateway-for-paystack/uninstall.php';
t( 'I1 uninstall.php exists', file_exists( $uninstall ), true );
$src = file_exists( $uninstall ) ? file_get_contents( $uninstall ) : '';
t( 'I2 removes secret keys', false !== strpos( $src, 'paystack_babe_live_secret_key' ), true );
t( 'I3 drops the payments table', false !== strpos( $src, 'DROP TABLE' ), true );
t( 'I4 guarded by WP_UNINSTALL_PLUGIN', false !== strpos( $src, 'WP_UNINSTALL_PLUGIN' ), true );

echo "\n=== J. DEPENDENCY GATE ===\n";

t( 'J1 dependency met', paystack_babe_dependency_met(), true );
t( 'J2 paystack registered with BABE', in_array( 'paystack', array_keys( BABE_Settings::get_active_payment_methods() ), true ), true );

printf( "\n---------------------------------------------------------------\n" );
printf( "  %d passed, %d failed\n", $GLOBALS["pbg_pass"], $GLOBALS["pbg_fail"] );
printf( "---------------------------------------------------------------\n\n" );
