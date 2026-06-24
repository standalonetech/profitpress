<?php
/**
 * Dashboard widget template.
 *
 * Expected scope (provided by {@see \Profitly\Dashboard\DashboardWidget::render()}):
 *
 * @var array<string, mixed>                       $current     Current 7-day totals.
 * @var array<string, mixed>|null                  $top_product Top product row, or null.
 * @var string                                     $currency    Store currency code.
 * @var array<string, array<string, mixed>>        $deltas      Delta descriptors per stat.
 *
 * @package Profitly
 */

declare( strict_types=1 );

use Profitly\Admin\Menu;
use Profitly\Reports\ReportsPage;

defined( 'ABSPATH' ) || exit;

$profitly_previous_label = __( 'vs previous 7 days', 'profitly' );
?>
<div class="profitly-widget">
	<?php if ( 0 === (int) $current['order_count'] ) : ?>

		<p><?php esc_html_e( 'No orders in the last 7 days yet.', 'profitly' ); ?></p>

	<?php else : ?>

		<ul class="profitly-widget__stats">
			<li>
				<span class="profitly-widget__label"><?php esc_html_e( 'Net Profit', 'profitly' ); ?></span>
				<span class="profitly-widget__value"><?php echo wp_kses_post( wc_price( (float) $current['net_profit'], array( 'currency' => $currency ) ) ); ?></span>
				<span class="profitly-widget__delta"><?php echo wp_kses_post( ReportsPage::render_delta( $deltas['net_profit'], $profitly_previous_label ) ); ?></span>
			</li>
			<li>
				<span class="profitly-widget__label"><?php esc_html_e( 'Revenue', 'profitly' ); ?></span>
				<span class="profitly-widget__value"><?php echo wp_kses_post( wc_price( (float) $current['revenue'], array( 'currency' => $currency ) ) ); ?></span>
				<span class="profitly-widget__delta"><?php echo wp_kses_post( ReportsPage::render_delta( $deltas['revenue'], $profitly_previous_label ) ); ?></span>
			</li>
			<li>
				<span class="profitly-widget__label"><?php esc_html_e( 'Margin %', 'profitly' ); ?></span>
				<span class="profitly-widget__value"><?php echo esc_html( (string) $current['avg_margin'] ); ?>%</span>
				<span class="profitly-widget__delta"><?php echo wp_kses_post( ReportsPage::render_delta( $deltas['avg_margin'], $profitly_previous_label ) ); ?></span>
			</li>
		</ul>

		<?php if ( null !== $top_product ) : ?>
			<p class="profitly-widget__top">
				<?php
				printf(
					/* translators: 1: product name, 2: profit amount */
					esc_html__( 'Top product: %1$s (%2$s profit)', 'profitly' ),
					esc_html( (string) $top_product['product_name'] ),
					wp_kses_post( wc_price( (float) $top_product['net_profit'], array( 'currency' => $currency ) ) )
				);
				?>
			</p>
		<?php endif; ?>

	<?php endif; ?>

	<p class="profitly-widget__link">
		<a href="<?php echo esc_url( Menu::reports_url() ); ?>"><?php esc_html_e( 'View full report →', 'profitly' ); ?></a>
	</p>
</div>
