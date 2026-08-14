<?php
/**
 * Report table schema.
 *
 * @package SalesByStateReportForWooCommerce
 */

namespace SBSR\Install;

use SBSR\Data\OrderSource;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and versions the report table.
 *
 * A dedicated aggregate table is used so a report over any number of orders
 * costs one indexed query. It stores no personal data: only order totals, the
 * state and country codes, the status and the dates.
 */
class Schema {

	/**
	 * Schema version. Bump when the table definition changes.
	 */
	const DB_VERSION = 1;

	/**
	 * Option holding the installed schema version.
	 */
	const VERSION_OPTION = 'sbsr_db_version';

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'sbsr_order_state';
	}

	/**
	 * Install the table if the schema version has moved.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( (int) get_option( self::VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Create or migrate the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->has_cap( 'collation' ) ? $wpdb->get_charset_collate() : '';

		$sql = "CREATE TABLE {$table} (
			order_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT '',
			date_created DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			date_paid DATETIME NULL DEFAULT NULL,
			billing_country CHAR(2) NOT NULL DEFAULT '',
			billing_state VARCHAR(20) NOT NULL DEFAULT '',
			shipping_country CHAR(2) NOT NULL DEFAULT '',
			shipping_state VARCHAR(20) NOT NULL DEFAULT '',
			currency CHAR(3) NOT NULL DEFAULT '',
			total_sales DECIMAL(26,8) NOT NULL DEFAULT 0,
			tax_total DECIMAL(26,8) NOT NULL DEFAULT 0,
			shipping_total DECIMAL(26,8) NOT NULL DEFAULT 0,
			net_total DECIMAL(26,8) NOT NULL DEFAULT 0,
			PRIMARY KEY (order_id),
			KEY shipping (shipping_country, shipping_state, status),
			KEY billing (billing_country, billing_state, status),
			KEY paid (date_paid),
			KEY created (date_created)
		) {$collate};";

		dbDelta( $sql );

		if ( ! self::table_exists() ) {
			return;
		}

		// An empty table with a cursor left over from a previous build would
		// never refill, because the backfill would consider itself finished.
		$counts = self::counts();

		if ( 0 === $counts['rows'] ) {
			delete_option( 'sbsr_backfill_cursor' );
		}

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Whether the report table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) );
	}

	/**
	 * Row count alongside the number of orders on the site.
	 *
	 * @return array{rows:int,orders:int}
	 */
	public static function counts() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sbsr_order_state" );

		return array(
			'rows'   => $rows,
			'orders' => OrderSource::count(),
		);
	}

	/**
	 * Empty the table.
	 *
	 * @return void
	 */
	public static function truncate() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}sbsr_order_state" );
	}
}
