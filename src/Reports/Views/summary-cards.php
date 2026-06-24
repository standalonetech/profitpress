<?php
/**
 * Summary KPI cards partial.
 *
 * Expected scope:
 *
 * @var array<int, array<string, mixed>> $cards Card definitions.
 *
 * @package Profitly
 */

declare( strict_types=1 );

use Profitly\Reports\ReportsPage;

defined( 'ABSPATH' ) || exit;
?>
<div class="profitly-cards">
	<?php foreach ( $cards as $profitly_card ) : ?>
		<div class="profitly-card woocommerce-card components-card">
			<div class="profitly-card__label"><?php echo esc_html( (string) $profitly_card['label'] ); ?></div>
			<div class="profitly-card__value">
				<?php
				// Money values come from wc_price() (trusted HTML); others are plain text.
				echo ! empty( $profitly_card['is_money'] )
					? wp_kses_post( (string) $profitly_card['value'] )
					: wp_kses_post( (string) $profitly_card['value'] );
				?>
			</div>
			<div class="profitly-card__period"><?php echo esc_html( (string) $profitly_card['period_label'] ); ?></div>
			<div class="profitly-card__delta">
				<?php
				echo wp_kses_post(
					ReportsPage::render_delta(
						(array) $profitly_card['delta'],
						(string) $profitly_card['previous_label']
					)
				);
				?>
			</div>
		</div>
	<?php endforeach; ?>
</div>
