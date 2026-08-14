<?php
/**
 * The report query.
 *
 * @package SalesByStateReportForWooCommerce
 */

namespace SBSR\Data;

use SBSR\Filters;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a country, a year and a set of order statuses into one row per state.
 *
 * Sorting and pagination are deliberately absent. A country has at most a few
 * dozen states, so the whole result is returned at once and the browser handles
 * both without a further request.
 */
class Report {

	/**
	 * Run the report.
	 *
	 * @param int          $year     Calendar year.
	 * @param string       $country  Two-letter country code.
	 * @param string|array $statuses Order statuses to include.
	 * @return array{rows:array,totals:array,total:int}
	 */
	public function get( $year, $country, $statuses ) {
		$year     = Filters::normalize_year( $year );
		$country  = Filters::normalize_country( $country );
		$statuses = Filters::normalize_statuses( $statuses );

		if ( ! $statuses ) {
			$statuses = Filters::default_statuses();
		}

		$totals_by_state = $statuses
			? $this->total_by_state( $this->query( $year, $country ), $statuses )
			: array();

		$rows = $this->add_states_with_no_sales( $totals_by_state, $country );
		$rows = $this->add_labels_and_currency( $rows, $country );

		return array(
			'rows'   => $rows,
			'totals' => $this->grand_totals( $rows ),
			'total'  => count( $rows ),
		);
	}

	/**
	 * Fetch sales grouped by state and status for one country and year.
	 *
	 * The status filter is applied afterwards rather than in the query, which
	 * keeps every identifier here a literal and leaves only values bound. The
	 * result is at most one row per state per status.
	 *
	 * @param int    $year    Calendar year.
	 * @param string $country Two-letter country code.
	 * @return array
	 */
	private function query( $year, $country ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT shipping_state AS state,
				        status,
				        SUM( net_total ) AS net_revenue,
				        SUM( total_sales ) AS gross_revenue
				 FROM {$wpdb->prefix}sbsr_order_state
				 WHERE shipping_country = %s
				   AND COALESCE( date_paid, date_created ) >= %s
				   AND COALESCE( date_paid, date_created ) <= %s
				 GROUP BY shipping_state, status",
				$country,
				$year . '-01-01 00:00:00',
				$year . '-12-31 23:59:59'
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Add up the per-status rows for the statuses that were ticked.
	 *
	 * @param array    $rows     Rows from the query, one per state per status.
	 * @param string[] $statuses Selected status keys.
	 * @return array One row per state.
	 */
	private function total_by_state( array $rows, array $statuses ) {
		$states = array();

		foreach ( $rows as $row ) {
			if ( ! in_array( (string) $row['status'], $statuses, true ) ) {
				continue;
			}

			$state = (string) $row['state'];

			if ( ! isset( $states[ $state ] ) ) {
				$states[ $state ] = array(
					'state'         => $state,
					'net_revenue'   => 0,
					'gross_revenue' => 0,
				);
			}

			$states[ $state ]['net_revenue']   += (float) $row['net_revenue'];
			$states[ $state ]['gross_revenue'] += (float) $row['gross_revenue'];
		}

		return array_values( $states );
	}

	/**
	 * Add a zero row for every state of the country that sold nothing.
	 *
	 * A state missing from the table is not the same as a state with no sales,
	 * and only one of those is worth hiding.
	 *
	 * @param array  $rows    One row per state with sales.
	 * @param string $country Two-letter country code.
	 * @return array
	 */
	private function add_states_with_no_sales( array $rows, $country ) {
		$all_states = WC()->countries->get_states( $country );

		if ( empty( $all_states ) ) {
			return $rows;
		}

		$with_sales = array();

		foreach ( $rows as $row ) {
			$with_sales[ (string) $row['state'] ] = $row;
		}

		$complete = array();

		foreach ( array_keys( $all_states ) as $code ) {
			if ( isset( $with_sales[ $code ] ) ) {
				$complete[] = $with_sales[ $code ];
				unset( $with_sales[ $code ] );
				continue;
			}

			$complete[] = array(
				'state'         => $code,
				'net_revenue'   => 0,
				'gross_revenue' => 0,
			);
		}

		// Whatever is left had a state code the country does not list. Keep it,
		// so no sales disappear from the totals.
		foreach ( $with_sales as $row ) {
			$complete[] = $row;
		}

		return $complete;
	}

	/**
	 * Attach the state name and a formatted currency string to each row.
	 *
	 * Currency is formatted here rather than in the browser so the report never
	 * has to reimplement WooCommerce's currency settings.
	 *
	 * @param array  $rows    Rows.
	 * @param string $country Two-letter country code.
	 * @return array
	 */
	private function add_labels_and_currency( array $rows, $country ) {
		$state_names = WC()->countries->get_states( $country );

		foreach ( $rows as $index => $row ) {
			$code = (string) $row['state'];

			$rows[ $index ]['state_code'] = $code;
			$rows[ $index ]['state_name'] = isset( $state_names[ $code ] ) && $state_names[ $code ]
				? $state_names[ $code ]
				: ( '' === $code ? __( 'Unknown', 'sales-by-state-report-for-woocommerce' ) : $code );

			foreach ( Filters::measure_keys() as $key ) {
				$amount                            = round( (float) $row[ $key ], 2 );
				$rows[ $index ][ $key ]            = $amount;
				$rows[ $index ][ $key . '_formatted' ] = $this->money( $amount );
			}
		}

		return $rows;
	}

	/**
	 * Totals across every state.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	private function grand_totals( array $rows ) {
		$totals = array();

		foreach ( Filters::measure_keys() as $key ) {
			$sum = 0;

			foreach ( $rows as $row ) {
				$sum += (float) $row[ $key ];
			}

			$totals[ $key ]                  = round( $sum, 2 );
			$totals[ $key . '_formatted' ]   = $this->money( $totals[ $key ] );
		}

		return $totals;
	}

	/**
	 * Format an amount as currency, without markup.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private function money( $amount ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES, 'UTF-8' );
	}
}
