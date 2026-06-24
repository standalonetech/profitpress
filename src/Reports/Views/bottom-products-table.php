<?php
/**
 * Top 10 loss-making products partial.
 *
 * Expected scope:
 *
 * @var array<int, array<string, mixed>> $loss_products Ranked product rows (worst first).
 * @var string                           $currency      Store currency code.
 *
 * @package Profitly
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="profitly-table-wrap woocommerce-card">
	<h2 class="profitly-table-title"><?php esc_html_e( 'Top 10 Loss-Making Products', 'profitly' ); ?></h2>

	<?php if ( empty( $loss_products ) ) : ?>
		<p class="profitly-table-empty"><?php esc_html_e( 'No loss-making products in this period. ', 'profitly' ); ?></p>
	<?php else : ?>
		<table class="widefat striped profitly-product-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Product', 'profitly' ); ?></th>
					<th class="profitly-num"><?php esc_html_e( 'Units Sold', 'profitly' ); ?></th>
					<th class="profitly-num"><?php esc_html_e( 'Revenue', 'profitly' ); ?></th>
					<th class="profitly-num"><?php esc_html_e( 'COGS', 'profitly' ); ?></th>
					<th class="profitly-num"><?php esc_html_e( 'Net Profit', 'profitly' ); ?></th>
					<th class="profitly-num"><?php esc_html_e( 'Margin %', 'profitly' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $loss_products as $profitly_row ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( get_edit_post_link( (int) $profitly_row['product_id'] ) ); ?>">
								<?php echo esc_html( (string) $profitly_row['product_name'] ); ?>
							</a>
						</td>
						<td class="profitly-num"><?php echo esc_html( number_format_i18n( (int) $profitly_row['units_sold'] ) ); ?></td>
						<td class="profitly-num"><?php echo wp_kses_post( wc_price( (float) $profitly_row['revenue'], array( 'currency' => $currency ) ) ); ?></td>
						<td class="profitly-num"><?php echo wp_kses_post( wc_price( (float) $profitly_row['cogs'], array( 'currency' => $currency ) ) ); ?></td>
						<td class="profitly-num profitly-loss"><?php echo wp_kses_post( wc_price( (float) $profitly_row['net_profit'], array( 'currency' => $currency ) ) ); ?></td>
						<td class="profitly-num profitly-loss"><?php echo esc_html( (string) $profitly_row['margin'] ); ?>%</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
