<?php
/**
 * The values the report's three filters can take.
 *
 * @package SalesByStateReportForWooCommerce
 */

namespace SBSR;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the filter options and validates what comes back from the browser.
 *
 * The admin page uses these lists to draw its controls and the REST controller
 * uses the same lists to check the request, so the two can never disagree about
 * what is allowed.
 */
class Filters {

	/**
	 * Option holding the first year offered by the year filter.
	 */
	const YEAR_START_OPTION = 'sbsr_year_start';

	/**
	 * Number of years offered when the list is first created.
	 */
	const YEAR_WINDOW = 10;

	/**
	 * The columns shown in the report table and summary.
	 *
	 * @return array<string,array{label:string,type:string}>
	 */
	public static function measures() {
		return array(
			'net_revenue'   => array(
				'label' => __( 'Net Sales', 'sales-by-state-report-for-woocommerce' ),
				'type'  => 'currency',
			),
			'gross_revenue' => array(
				'label' => __( 'Gross Sales', 'sales-by-state-report-for-woocommerce' ),
				'type'  => 'currency',
			),
		);
	}

	/**
	 * Measure keys.
	 *
	 * @return string[]
	 */
	public static function measure_keys() {
		return array_keys( self::measures() );
	}

	/**
	 * Order statuses the filter offers.
	 *
	 * @return array<string,string> Status key with the wc- prefix, mapped to its label.
	 */
	public static function order_statuses() {
		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$drafts   = array( 'wc-checkout-draft', 'wc-auto-draft', 'wc-trash' );
		$offered  = array();

		foreach ( (array) $statuses as $key => $label ) {
			$key = self::prefix_status( $key );

			if ( in_array( $key, $drafts, true ) ) {
				continue;
			}

			$offered[ $key ] = html_entity_decode( (string) $label, ENT_QUOTES, 'UTF-8' );
		}

		return $offered;
	}

	/**
	 * The statuses ticked when the report is opened with no explicit filter.
	 *
	 * @return string[]
	 */
	public static function default_statuses() {
		/**
		 * Filters the order statuses the report starts on.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $statuses Status keys, with the wc- prefix.
		 */
		$statuses = (array) apply_filters( 'sbsr_default_statuses', array( 'wc-completed' ) );

		return array_values( array_intersect( $statuses, array_keys( self::order_statuses() ) ) );
	}

	/**
	 * Reduce a request value to statuses this report recognises.
	 *
	 * Anything unrecognised is dropped rather than passed along, so the values
	 * that reach the report are always ones this class produced.
	 *
	 * @param string|array $value Comma-separated list or array of statuses.
	 * @return string[]
	 */
	public static function normalize_statuses( $value ) {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$offered  = array_keys( self::order_statuses() );
		$accepted = array();

		foreach ( $value as $status ) {
			$status = self::prefix_status( trim( (string) $status ) );

			if ( in_array( $status, $offered, true ) ) {
				$accepted[] = $status;
			}
		}

		return array_values( array_unique( $accepted ) );
	}

	/**
	 * Add the wc- prefix WooCommerce stores statuses with.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function prefix_status( $status ) {
		$status = (string) $status;

		return 0 === strpos( $status, 'wc-' ) ? $status : 'wc-' . $status;
	}

	/**
	 * Years the filter offers, newest first.
	 *
	 * The earliest year is recorded once and never moved, so the list grows by
	 * one entry each January rather than dropping the oldest year.
	 *
	 * @return int[]
	 */
	public static function years() {
		$current  = self::default_year();
		$earliest = (int) get_option( self::YEAR_START_OPTION, 0 );

		if ( $earliest <= 0 ) {
			$earliest = $current - ( self::YEAR_WINDOW - 1 );
			update_option( self::YEAR_START_OPTION, $earliest, false );
		}

		if ( $earliest > $current ) {
			$earliest = $current;
		}

		return array_map( 'intval', range( $current, $earliest ) );
	}

	/**
	 * The year the report opens on.
	 *
	 * @return int
	 */
	public static function default_year() {
		return (int) current_time( 'Y' );
	}

	/**
	 * Reduce a request value to a year the filter offers.
	 *
	 * @param mixed $year Requested year.
	 * @return int
	 */
	public static function normalize_year( $year ) {
		$year = (int) $year;

		return in_array( $year, self::years(), true ) ? $year : self::default_year();
	}

	/**
	 * The country the report opens on.
	 *
	 * Taken from WooCommerce → Settings → General → Default customer location
	 * (`woocommerce_default_country`). That option is stored as `CC` or
	 * `CC:STATE`, so only the country code is used.
	 *
	 * If the store country has no states or provinces, the first country that
	 * does is used instead, so the dropdown always opens on a valid option.
	 *
	 * @return string Two-letter country code.
	 */
	public static function default_country() {
		$code = self::country_code_from_base( get_option( 'woocommerce_default_country', '' ) );

		if ( ! $code ) {
			$code = 'US';
		}

		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->countries ) {
			return $code;
		}

		if ( WC()->countries->get_states( $code ) ) {
			return $code;
		}

		foreach ( array_keys( WC()->countries->get_countries() ) as $candidate ) {
			if ( WC()->countries->get_states( $candidate ) ) {
				return $candidate;
			}
		}

		return $code;
	}

	/**
	 * Reduce a request value to a two-letter country code.
	 *
	 * @param mixed $country Requested country.
	 * @return string
	 */
	public static function normalize_country( $country ) {
		$country = strtoupper( (string) $country );

		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : self::default_country();
	}

	/**
	 * Pull the country code out of WooCommerce's base-location option.
	 *
	 * @param mixed $base Option value, e.g. `US` or `US:CA`.
	 * @return string Empty string when the value is not a country code.
	 */
	private static function country_code_from_base( $base ) {
		$base = strtoupper( (string) $base );

		if ( false !== strpos( $base, ':' ) ) {
			$base = strtok( $base, ':' );
		}

		return preg_match( '/^[A-Z]{2}$/', $base ) ? $base : '';
	}
}
