<?php
/**
 * Removes the plugin's data when it is deleted.
 *
 * @package SalesByStateReportForWooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$sbsr_options = array(
	'sbsr_db_version',
	'sbsr_backfill_cursor',
	'sbsr_year_start',
);

foreach ( $sbsr_options as $sbsr_option ) {
	delete_option( $sbsr_option );
}

if ( is_multisite() ) {
	foreach ( $sbsr_options as $sbsr_option ) {
		delete_site_option( $sbsr_option );
	}
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sbsr_order_state" );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'sbsr_backfill_batch', array(), 'sales-by-state-report-for-woocommerce' );
}
