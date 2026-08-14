<?php
/**
 * Entries on the WooCommerce status tools screen.
 *
 * @package SalesByStateReportForWooCommerce
 */

namespace SBSR\Admin;

use SBSR\Data\Backfill;
use SBSR\Data\OrderSource;
use SBSR\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Adds build, rebuild and inspect tools.
 *
 * WooCommerce's own tools screen handles capability checks and nonces.
 */
class Tools {

	/**
	 * Orders processed per click.
	 */
	const BATCH = 1000;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_debug_tools', array( $this, 'add_tools' ) );
	}

	/**
	 * Add the tool entries.
	 *
	 * @param array $tools Existing tools.
	 * @return array
	 */
	public function add_tools( $tools ) {
		$counts    = Schema::counts();
		$remaining = Backfill::remaining();

		$tools['sbsr_backfill'] = array(
			'name'     => __( 'Sales by State Report: build report table', 'sales-by-state-report-for-woocommerce' ),
			'button'   => $remaining > 0
				/* translators: %s: number of orders left to process. */
				? sprintf( __( 'Process %s remaining', 'sales-by-state-report-for-woocommerce' ), number_format_i18n( $remaining ) )
				: __( 'Run', 'sales-by-state-report-for-woocommerce' ),
			'desc'     => sprintf(
				/* translators: 1: rows in the report table, 2: total orders. */
				__( 'Reads existing orders into the report table. %1$s of %2$s done. Run again until nothing remains.', 'sales-by-state-report-for-woocommerce' ),
				number_format_i18n( $counts['rows'] ),
				number_format_i18n( $counts['orders'] )
			),
			'callback' => array( $this, 'run' ),
		);

		$tools['sbsr_rebuild'] = array(
			'name'     => __( 'Sales by State Report: rebuild from scratch', 'sales-by-state-report-for-woocommerce' ),
			'button'   => __( 'Rebuild', 'sales-by-state-report-for-woocommerce' ),
			'desc'     => __( 'Empties the report table and starts again from the first order. Use this if the figures look wrong rather than merely incomplete.', 'sales-by-state-report-for-woocommerce' ),
			'callback' => array( $this, 'rebuild' ),
		);

		$tools['sbsr_data_check'] = array(
			'name'     => __( 'Sales by State Report: data check', 'sales-by-state-report-for-woocommerce' ),
			'button'   => __( 'Run check', 'sales-by-state-report-for-woocommerce' ),
			'desc'     => __( 'Reports row counts by status and the countries present in the report table. Read-only.', 'sales-by-state-report-for-woocommerce' ),
			'callback' => array( $this, 'data_check' ),
		);

		return $tools;
	}

	/**
	 * Process one batch.
	 *
	 * @return string
	 */
	public function run() {
		$result = Backfill::run_batch( self::BATCH );

		if ( ! $result['complete'] ) {
			return sprintf(
				/* translators: 1: orders processed, 2: orders remaining. */
				__( 'Processed %1$s orders. %2$s still to go — run the tool again.', 'sales-by-state-report-for-woocommerce' ),
				number_format_i18n( $result['processed'] ),
				number_format_i18n( $result['remaining'] )
			);
		}

		return sprintf(
			/* translators: %s: orders processed. */
			__( 'Processed %s orders. The report table is complete.', 'sales-by-state-report-for-woocommerce' ),
			number_format_i18n( $result['processed'] )
		);
	}

	/**
	 * Empty the table and start again.
	 *
	 * @return string
	 */
	public function rebuild() {
		Backfill::reset();

		$result = Backfill::run_batch( self::BATCH );

		return sprintf(
			/* translators: 1: orders processed, 2: orders remaining. */
			__( 'Table emptied. Processed %1$s orders, %2$s remaining.', 'sales-by-state-report-for-woocommerce' ),
			number_format_i18n( $result['processed'] ),
			number_format_i18n( $result['remaining'] )
		);
	}

	/**
	 * Report what the table contains.
	 *
	 * @return string
	 */
	public function data_check() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$by_status = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$wpdb->prefix}sbsr_order_state GROUP BY status ORDER BY total DESC", ARRAY_A );
		$countries = $wpdb->get_results( "SELECT shipping_country AS country, COUNT(*) AS total FROM {$wpdb->prefix}sbsr_order_state GROUP BY shipping_country ORDER BY total DESC", ARRAY_A );
		$no_state  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sbsr_order_state WHERE shipping_state = '' AND billing_state = ''" );
		// phpcs:enable

		$counts = Schema::counts();
		$lines  = array();

		$lines[] = sprintf(
			/* translators: 1: rows in the report table, 2: total orders, 3: storage backend name. */
			__( 'Report table: %1$s rows against %2$s orders (%3$s).', 'sales-by-state-report-for-woocommerce' ),
			number_format_i18n( $counts['rows'] ),
			number_format_i18n( $counts['orders'] ),
			OrderSource::hpos_enabled() ? __( 'HPOS', 'sales-by-state-report-for-woocommerce' ) : __( 'legacy posts', 'sales-by-state-report-for-woocommerce' )
		);

		if ( $no_state > 0 ) {
			$lines[] = sprintf(
				/* translators: %s: number of rows. */
				__( '%s rows carry no state and will never appear in the report.', 'sales-by-state-report-for-woocommerce' ),
				number_format_i18n( $no_state )
			);
		}

		$bits = array();

		foreach ( (array) $by_status as $row ) {
			$bits[] = $row['status'] . ': ' . number_format_i18n( (int) $row['total'] );
		}

		if ( $bits ) {
			$lines[] = __( 'By status:', 'sales-by-state-report-for-woocommerce' ) . ' ' . implode( ', ', $bits );
		}

		$bits = array();

		foreach ( (array) $countries as $row ) {
			$code   = '' === $row['country'] ? __( '(blank)', 'sales-by-state-report-for-woocommerce' ) : $row['country'];
			$bits[] = $code . ': ' . number_format_i18n( (int) $row['total'] );
		}

		if ( $bits ) {
			$lines[] = __( 'Countries:', 'sales-by-state-report-for-woocommerce' ) . ' ' . implode( ', ', $bits );
		}

		return implode( ' — ', $lines );
	}
}
