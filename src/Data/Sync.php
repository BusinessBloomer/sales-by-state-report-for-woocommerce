<?php
/**
 * Keeps the report table in step with the site's orders.
 *
 * @package SalesByStateReportForWooCommerce
 */

namespace SBSR\Data;

use SBSR\Filters;
use SBSR\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Writes one row per order.
 *
 * Refunds are not modelled: an order that has been refunded carries the
 * Refunded status and is included or excluded by the status filter like any
 * other order.
 */
class Sync {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_after_order_object_save', array( $this, 'on_save' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_change' ), 10, 4 );
		add_action( 'woocommerce_new_order', array( $this, 'upsert' ), 10, 1 );
		add_action( 'woocommerce_update_order', array( $this, 'upsert' ), 10, 1 );
		add_action( 'woocommerce_untrash_order', array( $this, 'upsert' ), 10, 1 );
		add_action( 'woocommerce_trash_order', array( $this, 'delete' ), 10, 1 );
		add_action( 'woocommerce_delete_order', array( $this, 'delete' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'maybe_delete_post' ), 10, 1 );
	}

	/**
	 * Handle an order object save.
	 *
	 * @param \WC_Abstract_Order $order Order.
	 * @return void
	 */
	public function on_save( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return;
		}

		$this->upsert( $order->get_id(), $order );
	}

	/**
	 * Handle a status transition.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $from     Previous status.
	 * @param string $to       New status.
	 * @param object $order    Order object.
	 * @return void
	 */
	public function on_status_change( $order_id, $from, $to, $order = null ) {
		$this->upsert( $order_id, $order );
	}

	/**
	 * Delete the row when an order post is removed on non-HPOS installs.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function maybe_delete_post( $post_id ) {
		if ( 'shop_order' !== get_post_type( $post_id ) ) {
			return;
		}

		$this->delete( $post_id );
	}

	/**
	 * Insert or update the row for one order.
	 *
	 * @param int                     $order_id Order ID.
	 * @param \WC_Abstract_Order|null $order    Order, if already loaded.
	 * @return bool
	 */
	public function upsert( $order_id, $order = null ) {
		global $wpdb;

		$order_id = (int) $order_id;

		if ( ! $order_id ) {
			return false;
		}

		if ( ! is_object( $order ) || ! method_exists( $order, 'get_total' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order ) {
			return false;
		}

		if ( method_exists( $order, 'get_type' ) && 'shop_order' !== $order->get_type() ) {
			return false;
		}

		$row = self::build_row( $order );

		if ( ! $row ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->replace(
			Schema::table(),
			$row,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f' )
		);
	}

	/**
	 * Remove the row for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function delete( $order_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::table(), array( 'order_id' => (int) $order_id ), array( '%d' ) );
	}

	/**
	 * Build the row for an order.
	 *
	 * Totals come from WooCommerce's own accessors so the figures reconcile
	 * with core reporting.
	 *
	 * @param \WC_Abstract_Order $order Order.
	 * @return array<string,mixed>|false
	 */
	public static function build_row( $order ) {
		if ( ! is_callable( array( $order, 'get_billing_country' ) ) ) {
			return false;
		}

		$billing_country  = (string) $order->get_billing_country();
		$billing_state    = (string) $order->get_billing_state();
		$shipping_country = (string) $order->get_shipping_country();
		$shipping_state   = (string) $order->get_shipping_state();

		// Virtual orders often carry no shipping address, so they would vanish
		// from a ship-to report without this fallback.
		if ( '' === $shipping_country ) {
			$shipping_country = $billing_country;
			$shipping_state   = $billing_state;
		}

		$total    = (float) $order->get_total();
		$tax      = (float) $order->get_total_tax();
		$shipping = (float) $order->get_shipping_total();

		$created = $order->get_date_created();
		$paid    = $order->get_date_paid();

		return array(
			'order_id'         => (int) $order->get_id(),
			'status'           => substr( Filters::prefix_status( $order->get_status() ), 0, 20 ),
			'date_created'     => $created ? gmdate( 'Y-m-d H:i:s', $created->getOffsetTimestamp() ) : '0000-00-00 00:00:00',
			'date_paid'        => $paid ? gmdate( 'Y-m-d H:i:s', $paid->getOffsetTimestamp() ) : null,
			'billing_country'  => substr( $billing_country, 0, 2 ),
			'billing_state'    => substr( $billing_state, 0, 20 ),
			'shipping_country' => substr( $shipping_country, 0, 2 ),
			'shipping_state'   => substr( $shipping_state, 0, 20 ),
			'currency'         => substr( (string) $order->get_currency(), 0, 3 ),
			'total_sales'      => $total,
			'tax_total'        => $tax,
			'shipping_total'   => $shipping,
			'net_total'        => $total - $tax - $shipping,
		);
	}
}
