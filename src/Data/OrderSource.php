<?php
/**
 * Locates orders on either storage backend.
 *
 * @package SalesByStateReportForWooCommerce
 */

namespace SBSR\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Abstracts High-Performance Order Storage from the legacy posts table.
 *
 * Only order IDs are read here. Order data is always loaded through
 * wc_get_order(), so whichever storage is authoritative is the one that
 * answers.
 *
 * Each method spells out both queries in full rather than assembling table and
 * column names from variables, so every identifier in the SQL is a literal.
 */
class OrderSource {

	/**
	 * Whether HPOS is the authoritative store.
	 *
	 * @return bool
	 */
	public static function hpos_enabled() {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return false;
		}

		if ( ! method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) {
			return false;
		}

		return (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Total number of orders on the site.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;

		if ( self::hpos_enabled() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = %s", 'shop_order' )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'shop_order' )
		);
	}

	/**
	 * Number of orders above a cursor.
	 *
	 * @param int $cursor Highest order ID already processed.
	 * @return int
	 */
	public static function count_after( $cursor ) {
		global $wpdb;

		$cursor = max( 0, (int) $cursor );

		if ( self::hpos_enabled() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = %s AND id > %d", 'shop_order', $cursor )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d", 'shop_order', $cursor )
		);
	}

	/**
	 * The next batch of order IDs after a cursor.
	 *
	 * Walking by ascending ID keeps each batch a constant cost, rather than
	 * re-scanning the orders table to diff against the report table.
	 *
	 * @param int $cursor Highest order ID already processed.
	 * @param int $limit  Batch size.
	 * @return int[]
	 */
	public static function ids_after( $cursor, $limit ) {
		global $wpdb;

		$cursor = max( 0, (int) $cursor );
		$limit  = max( 1, (int) $limit );

		if ( self::hpos_enabled() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}wc_orders
					 WHERE type = %s AND id > %d
					 ORDER BY id ASC
					 LIMIT %d",
					'shop_order',
					$cursor,
					$limit
				)
			);

			return array_map( 'intval', (array) $ids );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = %s AND ID > %d
				 ORDER BY ID ASC
				 LIMIT %d",
				'shop_order',
				$cursor,
				$limit
			)
		);

		return array_map( 'intval', (array) $ids );
	}
}
