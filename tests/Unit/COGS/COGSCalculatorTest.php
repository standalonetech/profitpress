<?php
/**
 * Unit tests for the decimal-safe cost-of-goods calculator.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Tests\Unit\COGS;

use Profitly\COGS\COGSCalculator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Profitly\COGS\COGSCalculator
 */
final class COGSCalculatorTest extends TestCase {

	/**
	 * @dataProvider line_total_provider
	 *
	 * @param string $unit_cost Per-unit cost.
	 * @param int    $quantity  Quantity.
	 * @param string $expected  Expected line total.
	 */
	public function test_calculate_line_total( string $unit_cost, int $quantity, string $expected ): void {
		$this->assertSame( $expected, COGSCalculator::calculate_line_total( $unit_cost, $quantity ) );
	}

	/**
	 * @return array<string, array{string, int, string}>
	 */
	public function line_total_provider(): array {
		return array(
			'unit times quantity'      => array( '2.50', 3, '7.50' ),
			'negative quantity clamps' => array( '2.50', -3, '0.00' ),
			'zero quantity'            => array( '9.99', 0, '0.00' ),
			'non-numeric cost is zero' => array( 'abc', 4, '0.00' ),
			'empty cost is zero'       => array( '', 4, '0.00' ),
		);
	}

	public function test_sum_adds_decimal_strings(): void {
		$this->assertSame( '6.60', COGSCalculator::sum( array( '1.10', '2.20', '3.30' ) ) );
	}

	public function test_sum_of_empty_array_is_zero(): void {
		$this->assertSame( '0.00', COGSCalculator::sum( array() ) );
	}

	public function test_sum_ignores_non_numeric_entries(): void {
		$this->assertSame( '3.00', COGSCalculator::sum( array( '1.00', 'oops', '2.00' ) ) );
	}

	/**
	 * @dataProvider valid_cost_provider
	 *
	 * @param mixed $value    Candidate value.
	 * @param bool  $expected Expected validity.
	 */
	public function test_is_valid_cost( $value, bool $expected ): void {
		$this->assertSame( $expected, COGSCalculator::is_valid_cost( $value ) );
	}

	/**
	 * @return array<string, array{mixed, bool}>
	 */
	public function valid_cost_provider(): array {
		return array(
			'positive decimal'  => array( '10.50', true ),
			'zero'              => array( '0', true ),
			'numeric float'     => array( 12.34, true ),
			'upper boundary'    => array( '999999.99', true ),
			'negative rejected' => array( '-1', false ),
			'over maximum'      => array( '1000000', false ),
			'non-numeric'       => array( 'abc', false ),
			'empty string'      => array( '', false ),
			'null'              => array( null, false ),
			'boolean true'      => array( true, false ),
		);
	}

	public function test_add(): void {
		$this->assertSame( '15.50', COGSCalculator::add( '10.00', '5.50' ) );
	}

	public function test_subtract(): void {
		$this->assertSame( '6.75', COGSCalculator::subtract( '10.00', '3.25' ) );
	}

	public function test_subtract_can_go_negative(): void {
		$this->assertSame( '-3.00', COGSCalculator::subtract( '5.00', '8.00' ) );
	}

	public function test_multiply(): void {
		$this->assertSame( '10.00', COGSCalculator::multiply( '4.00', '2.50' ) );
	}

	public function test_divide(): void {
		$this->assertSame( '2.50', COGSCalculator::divide( '10.00', '4.00' ) );
	}

	public function test_divide_by_zero_returns_zero(): void {
		$this->assertSame( '0.00', COGSCalculator::divide( '10.00', '0' ) );
	}

	public function test_divide_truncates_to_scale(): void {
		$this->assertSame( '3.33', COGSCalculator::divide( '10.00', '3.00' ) );
	}
}
