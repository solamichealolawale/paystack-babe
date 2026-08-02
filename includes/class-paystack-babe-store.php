<?php
/**
 * Durable record of every payment this plugin issues.
 *
 * This exists because the previous design leaned on transients and an option
 * array, and an independent review showed that combination could not carry the
 * guarantees the plugin claimed:
 *
 *  - `get_transient()` then `set_transient()` is not a compare-and-swap, so the
 *    browser callback and the webhook — which arrive together by design — could
 *    both pass the guard and double-credit an order, because BABE's
 *    `update_order_prepaid_received()` is additive.
 *  - The expected amount lived in a 24-hour transient while Paystack retries
 *    webhooks for 72 hours, so the amount/currency check silently disabled
 *    itself on late delivery or any object-cache eviction.
 *  - The fulfilled ledger was an option capped at 500 entries, so a busy site
 *    could prune a reference that Paystack was still retrying.
 *  - Nothing recorded which references this plugin had actually issued, so the
 *    unauthenticated callback would call the Paystack API for *any* string
 *    shaped like a reference — usable to exhaust the merchant's rate limit.
 *
 * A single table with `reference` as the primary key fixes all four: the claim
 * is a conditional UPDATE whose affected-row count is decided by the database,
 * the expected amount is durable, there is no cap, and an unknown reference is
 * rejected before any outbound call.
 *
 * @package PaystackBabe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Paystack_Babe_Store
 */
class Paystack_Babe_Store {

	/**
	 * Bumped whenever the schema changes.
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * Option holding the installed schema version.
	 */
	const SCHEMA_OPTION = 'paystack_babe_schema';

	/**
	 * Payment issued, not yet fulfilled.
	 */
	const STATUS_ISSUED = 'issued';

	/**
	 * Payment fulfilled — the booking has been credited.
	 */
	const STATUS_FULFILLED = 'fulfilled';

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'paystack_babe_payments';
	}

	/**
	 * Create or update the table.
	 */
	public static function install() {
		global $wpdb;

		if ( (int) get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// `reference` is the primary key: the uniqueness guarantee the atomic
		// claim depends on is enforced by the database, not by PHP.
		$sql = "CREATE TABLE {$table} (
			reference varchar(191) NOT NULL,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			expected_amount decimal(20,4) NOT NULL DEFAULT 0,
			expected_currency varchar(8) NOT NULL DEFAULT '',
			paid_amount decimal(20,4) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'issued',
			created_at datetime NOT NULL,
			fulfilled_at datetime DEFAULT NULL,
			PRIMARY KEY  (reference),
			KEY order_id (order_id),
			KEY status_created (status, created_at)
		) {$collate};";

		dbDelta( $sql );

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Record a payment we are about to send the guest to Paystack for.
	 *
	 * @param string $reference Transaction reference.
	 * @param int    $order_id  BABE order id.
	 * @param float  $amount    Amount we asked Paystack to charge, in major units.
	 * @param string $currency  Currency we asked for.
	 * @return bool
	 */
	public static function record_issued( $reference, $order_id, $amount, $currency ) {
		global $wpdb;

		return (bool) $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'reference'         => $reference,
				'order_id'          => absint( $order_id ),
				'expected_amount'   => (float) $amount,
				'expected_currency' => strtoupper( $currency ),
				'status'            => self::STATUS_ISSUED,
				'created_at'        => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%f', '%s', '%s', '%s' )
		);
	}

	/**
	 * Fetch a payment row.
	 *
	 * @param string $reference Transaction reference.
	 * @return array|null
	 */
	public static function get( $reference ) {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE reference = %s', $reference ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Atomically claim a payment for fulfilment.
	 *
	 * This is the whole point of the table. The UPDATE only matches rows still
	 * in `issued`, so exactly one concurrent request can ever see a non-zero
	 * affected-row count — the database decides the winner, not a read followed
	 * by a write. The loser is told so explicitly rather than being handed
	 * something it could mistake for success.
	 *
	 * @param string $reference   Transaction reference.
	 * @param float  $paid_amount Amount actually paid, in major units.
	 * @return bool True if this caller now owns fulfilment.
	 */
	public static function claim( $reference, $paid_amount ) {
		global $wpdb;

		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'UPDATE ' . self::table() . '
				 SET status = %s, paid_amount = %f, fulfilled_at = %s
				 WHERE reference = %s AND status = %s',
				self::STATUS_FULFILLED,
				(float) $paid_amount,
				current_time( 'mysql', true ),
				$reference,
				self::STATUS_ISSUED
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Release a claim so a later retry can try again.
	 *
	 * Used when fulfilment fails *after* the claim was taken — without this the
	 * reference would be stuck in `fulfilled` while the booking was never
	 * credited, and Paystack's retries would all no-op.
	 *
	 * @param string $reference Transaction reference.
	 */
	public static function release( $reference ) {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'UPDATE ' . self::table() . '
				 SET status = %s, fulfilled_at = NULL
				 WHERE reference = %s AND status = %s',
				self::STATUS_ISSUED,
				$reference,
				self::STATUS_FULFILLED
			)
		);
	}

	/**
	 * Drop the table and its schema marker.
	 */
	public static function uninstall() {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB
		delete_option( self::SCHEMA_OPTION );
	}
}
