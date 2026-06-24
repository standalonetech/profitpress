<?php
/**
 * Order-level profit CSV export.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Export;

use DateTimeImmutable;
use Profitly\Profit\OrderProfitCalculator;
use Profitly\Reports\ProfitAggregator;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Streams a CSV of per-order profit for the last 30 days.
 *
 * Its single responsibility is the export endpoint: it validates the request,
 * then streams a CSV straight to the browser without building the whole file in
 * memory. Orders are paged in batches (HPOS-aware via {@see wc_get_orders()}) and
 * each row's figures come from {@see OrderProfitCalculator} — the same source of
 * truth as the on-screen reports and the order metabox — so the export always
 * reconciles with what the merchant sees.
 */
final class CsvExporter {

	/**
	 * The admin-post action name.
	 */
	public const ACTION = 'profitly_export_csv';

	/**
	 * Capability required to export.
	 */
	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * Orders fetched per page while streaming.
	 */
	private const BATCH_SIZE = 200;

	/**
	 * Register WordPress hooks for this component.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Validate the request and stream the CSV.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to export Profitly reports.', 'profitly' ) );
		}

		check_admin_referer( self::ACTION );

		// Long exports should finish even if the user navigates away.
		ignore_user_abort( true );

		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 );
		}

		$this->stream();
		exit;
	}

	/**
	 * Stream the CSV to php://output.
	 *
	 * @return void
	 */
	private function stream(): void {
		$filename = 'profitly-report-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$output = fopen( 'php://output', 'w' );

		// Streaming to php://output is exactly what we want here; WP_Filesystem
		// would buffer the whole file in memory and defeat the purpose.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming the UTF-8 BOM to the output stream.
		fwrite( $output, "\xEF\xBB\xBF" );

		$columns = self::columns();
		fputcsv( $output, array_values( $columns ) );

		// Pro feature: custom date ranges. The free export is fixed to 30 days.
		$range_end   = new DateTimeImmutable( 'now', wp_timezone() );
		$range_start = $range_end->setTime( 0, 0, 0 )->modify( '-30 days' );

		$page = 1;

		do {
			$orders = $this->fetch_batch( $range_start, $range_end, $page );

			foreach ( $orders as $order ) {
				if ( ! $order instanceof WC_Order ) {
					continue;
				}

				$row = $this->build_row( $order, $columns );

				/**
				 * Filter a single CSV export row before it is written.
				 *
				 * @param array<string, string> $row   Column key => value.
				 * @param WC_Order               $order The order.
				 */
				$row = apply_filters( 'profitly_csv_export_row', $row, $order );

				fputcsv( $output, array_values( $row ) );
			}

			++$page;
			$fetched = count( $orders );
		} while ( self::BATCH_SIZE === $fetched );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the output stream opened above.
		fclose( $output );
	}

	/**
	 * The CSV column definitions (key => header label).
	 *
	 * @return array<string, string>
	 */
	private static function columns(): array {
		$columns = array(
			'order_id'       => __( 'Order ID', 'profitly' ),
			'order_date'     => __( 'Order Date', 'profitly' ),
			'status'         => __( 'Status', 'profitly' ),
			'customer_email' => __( 'Customer Email', 'profitly' ),
			'currency'       => __( 'Currency', 'profitly' ),
			'revenue'        => __( 'Revenue', 'profitly' ),
			'cogs'           => __( 'COGS', 'profitly' ),
			'gateway_fee'    => __( 'Gateway Fee', 'profitly' ),
			'shipping_cost'  => __( 'Shipping Cost (merchant)', 'profitly' ),
			'refund_loss'    => __( 'Refund Loss', 'profitly' ),
			'net_profit'     => __( 'Net Profit', 'profitly' ),
			'margin_percent' => __( 'Margin %', 'profitly' ),
		);

		/**
		 * Filter the CSV export columns.
		 *
		 * @param array<string, string> $columns Column key => header label.
		 */
		return apply_filters( 'profitly_csv_export_columns', $columns );
	}

	/**
	 * Build one CSV row for an order.
	 *
	 * @param WC_Order              $order   The order.
	 * @param array<string, string> $columns The active columns (keys define the row shape).
	 * @return array<string, string> Column key => value.
	 */
	private function build_row( WC_Order $order, array $columns ): array {
		$data = OrderProfitCalculator::calculate( $order );
		$date = $order->get_date_created();

		$values = array(
			'order_id'       => (string) $order->get_id(),
			'order_date'     => $date ? $date->date( 'Y-m-d H:i:s' ) : '',
			'status'         => $order->get_status(),
			'customer_email' => $order->get_billing_email(),
			'currency'       => $data['currency'],
			'revenue'        => $data['revenue'],
			'cogs'           => $data['cogs'],
			'gateway_fee'    => $data['gateway_fee'],
			'shipping_cost'  => $data['shipping_cost'],
			'refund_loss'    => $data['refund_loss'],
			'net_profit'     => $data['net_profit'],
			'margin_percent' => $data['margin_percent'],
		);

		// Honour any custom columns added via the filter: unknown keys get blanks.
		$row = array();

		foreach ( array_keys( $columns ) as $key ) {
			$row[ $key ] = isset( $values[ $key ] ) ? (string) $values[ $key ] : '';
		}

		return $row;
	}

	/**
	 * Fetch a page of orders in the date range (HPOS-aware).
	 *
	 * @param DateTimeImmutable $start Window start (site timezone).
	 * @param DateTimeImmutable $end   Window end (site timezone).
	 * @param int               $page  1-based page number.
	 * @return array<int, WC_Order> Orders for the page.
	 */
	private function fetch_batch( DateTimeImmutable $start, DateTimeImmutable $end, int $page ): array {
		$statuses = array_map(
			static fn( $status ) => 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status,
			ProfitAggregator::get_statuses()
		);

		$orders = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => $statuses,
				'date_created' => $start->getTimestamp() . '...' . $end->getTimestamp(),
				'limit'        => self::BATCH_SIZE,
				'page'         => $page,
				'paginate'     => false,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'return'       => 'objects',
			)
		);

		return is_array( $orders ) ? $orders : array();
	}
}
