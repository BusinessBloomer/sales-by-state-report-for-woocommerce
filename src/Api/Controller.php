<?php
/**
 * REST controller.
 *
 * @package SalesByStateReportForWooCommerce
 */

namespace SBSR\Api;

use SBSR\Data\Backfill;
use SBSR\Data\OrderSource;
use SBSR\Data\Report;
use SBSR\Filters;
use SBSR\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the report under the plugin's own REST namespace.
 */
class Controller extends \WP_REST_Controller {

	/**
	 * Route namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'sbsr/v1';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/report',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_report' ),
					'permission_callback' => array( $this, 'read_permission' ),
					'args'                => $this->report_args(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/diagnostics',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_diagnostics' ),
					'permission_callback' => array( $this, 'read_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/backfill',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run_backfill' ),
					'permission_callback' => array( $this, 'write_permission' ),
					'args'                => array(
						'limit' => array(
							'type'    => 'integer',
							'default' => 500,
							'minimum' => 1,
							'maximum' => 2000,
						),
						'reset' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Whether the current user may read the report.
	 *
	 * @return bool
	 */
	public function read_permission() {
		return current_user_can( 'view_woocommerce_reports' ) || current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Whether the current user may rebuild the report table.
	 *
	 * @return bool
	 */
	public function write_permission() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Query parameters for the report.
	 *
	 * Sorting and paging are handled in the browser; a country has few enough
	 * states that the whole result is returned at once.
	 *
	 * @return array
	 */
	private function report_args() {
		return array(
			'year'     => array(
				'description' => __( 'Calendar year to report on.', 'sales-by-state-report-for-woocommerce' ),
				'type'        => 'integer',
				'default'     => Filters::default_year(),
			),
			'country'  => array(
				'description'       => __( 'Two-letter country code.', 'sales-by-state-report-for-woocommerce' ),
				'type'              => 'string',
				'default'           => Filters::default_country(),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'statuses' => array(
				'description'       => __( 'Comma-separated order statuses to include.', 'sales-by-state-report-for-woocommerce' ),
				'type'              => 'string',
				'default'           => implode( ',', Filters::default_statuses() ),
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Return the report.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_report( $request ) {
		Schema::maybe_install();

		if ( ! Schema::table_exists() ) {
			return new \WP_Error(
				'sbsr_no_table',
				__( 'The report table could not be created. Check the database user has permission to create tables.', 'sales-by-state-report-for-woocommerce' ),
				array( 'status' => 500 )
			);
		}

		$report = new Report();

		return rest_ensure_response(
			$report->get(
				(int) $request->get_param( 'year' ),
				(string) $request->get_param( 'country' ),
				(string) $request->get_param( 'statuses' )
			)
		);
	}

	/**
	 * Report on the state of the data rather than the sales.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_diagnostics() {
		Schema::maybe_install();

		$counts = Schema::counts();

		return rest_ensure_response(
			array(
				'table_exists' => Schema::table_exists(),
				'rows'         => $counts['rows'],
				'orders'       => $counts['orders'],
				'remaining'    => Backfill::remaining(),
				'hpos'         => OrderSource::hpos_enabled(),
			)
		);
	}

	/**
	 * Run one backfill batch.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function run_backfill( $request ) {
		if ( $request->get_param( 'reset' ) ) {
			Backfill::reset();
		}

		$result = Backfill::run_batch( (int) $request->get_param( 'limit' ) );
		$counts = Schema::counts();

		return rest_ensure_response(
			array(
				'processed' => $result['processed'],
				'remaining' => $result['remaining'],
				'complete'  => $result['complete'],
				'rows'      => $counts['rows'],
				'orders'    => $counts['orders'],
			)
		);
	}

	/**
	 * Response schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$row = array(
			'state'      => array(
				'description' => __( 'State code.', 'sales-by-state-report-for-woocommerce' ),
				'type'        => 'string',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'state_code' => array(
				'description' => __( 'State code.', 'sales-by-state-report-for-woocommerce' ),
				'type'        => 'string',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'state_name' => array(
				'description' => __( 'State name.', 'sales-by-state-report-for-woocommerce' ),
				'type'        => 'string',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
		);

		foreach ( Filters::measures() as $key => $measure ) {
			$row[ $key ] = array(
				'description' => $measure['label'],
				'type'        => 'number',
				'context'     => array( 'view' ),
				'readonly'    => true,
			);

			$row[ $key . '_formatted' ] = array(
				'description' => $measure['label'],
				'type'        => 'string',
				'context'     => array( 'view' ),
				'readonly'    => true,
			);
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'sbsr_report',
			'type'       => 'object',
			'properties' => array(
				'rows'   => array(
					'description' => __( 'One entry per state.', 'sales-by-state-report-for-woocommerce' ),
					'type'        => 'array',
					'context'     => array( 'view' ),
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => $row,
					),
				),
				'totals' => array(
					'description' => __( 'Totals across every state.', 'sales-by-state-report-for-woocommerce' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'total'  => array(
					'description' => __( 'Number of states in the result.', 'sales-by-state-report-for-woocommerce' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
