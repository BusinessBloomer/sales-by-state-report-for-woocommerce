<?php
/**
 * Populates the report table from existing orders.
 *
 * @package SalesByStateReportForWooCommerce
 */

namespace SBSR\Data;

use SBSR\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Walks every order once, in ascending ID order.
 */
class Backfill {

	/**
	 * Option holding the highest order ID processed so far.
	 */
	const CURSOR_OPTION = 'sbsr_backfill_cursor';

	/**
	 * Process one batch.
	 *
	 * @param int $limit Orders per batch.
	 * @return array{processed:int,remaining:int,complete:bool,cursor:int}
	 */
	public static function run_batch( $limit = 500 ) {
		Schema::maybe_install();

		$cursor = (int) get_option( self::CURSOR_OPTION, 0 );
		$ids    = OrderSource::ids_after( $cursor, max( 1, (int) $limit ) );

		if ( ! $ids ) {
			return array(
				'processed' => 0,
				'remaining' => 0,
				'complete'  => true,
				'cursor'    => $cursor,
			);
		}

		$sync      = new Sync();
		$processed = 0;

		foreach ( $ids as $id ) {
			if ( $sync->upsert( $id ) ) {
				++$processed;
			}

			// The cursor advances whether or not the order resolved, so one
			// unreadable order cannot stall the backfill.
			$cursor = max( $cursor, (int) $id );
		}

		update_option( self::CURSOR_OPTION, $cursor, false );

		$remaining = OrderSource::count_after( $cursor );

		return array(
			'processed' => $processed,
			'remaining' => $remaining,
			'complete'  => 0 === $remaining,
			'cursor'    => $cursor,
		);
	}

	/**
	 * Number of orders still to be walked.
	 *
	 * @return int
	 */
	public static function remaining() {
		return OrderSource::count_after( (int) get_option( self::CURSOR_OPTION, 0 ) );
	}

	/**
	 * Whether the table has been built.
	 *
	 * @return bool
	 */
	public static function is_complete() {
		return 0 === self::remaining();
	}

	/**
	 * Empty the table and start again.
	 *
	 * Truncating rather than re-syncing means orders deleted since the last run
	 * stop contributing.
	 *
	 * @return void
	 */
	public static function reset() {
		Schema::maybe_install();
		Schema::truncate();
		delete_option( self::CURSOR_OPTION );
	}
}
