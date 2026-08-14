<?php
/**
 * Unattended backfill via Action Scheduler.
 *
 * @package SalesByStateReportForWooCommerce
 */

namespace SBSR\Data;

use SBSR\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Fills the report table in the background.
 *
 * Action Scheduler ships with WooCommerce, so no additional dependency is
 * introduced. The manual tool under WooCommerce → Status → Tools remains as a
 * fallback for sites where scheduled actions are not running.
 */
class Scheduler {

	/**
	 * Action Scheduler hook name.
	 */
	const HOOK = 'sbsr_backfill_batch';

	/**
	 * Action Scheduler group.
	 */
	const GROUP = 'sales-by-state-report-for-woocommerce';

	/**
	 * Orders per batch.
	 */
	const BATCH = 500;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::HOOK, array( $this, 'run_batch' ) );
		add_action( 'admin_init', array( $this, 'maybe_schedule' ) );
	}

	/**
	 * Queue a batch if work is outstanding and none is queued.
	 *
	 * @return void
	 */
	public function maybe_schedule() {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		if ( ! Schema::table_exists() || Backfill::is_complete() ) {
			return;
		}

		if ( as_next_scheduled_action( self::HOOK, null, self::GROUP ) ) {
			return;
		}

		as_schedule_single_action( time(), self::HOOK, array(), self::GROUP );
	}

	/**
	 * Process one batch and requeue if more remains.
	 *
	 * @return void
	 */
	public function run_batch() {
		$result = Backfill::run_batch( self::BATCH );

		if ( $result['complete'] ) {
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 5, self::HOOK, array(), self::GROUP );
		}
	}
}
