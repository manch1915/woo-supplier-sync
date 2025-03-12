<?php
/**
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SupplierSync\Domain\Pricing\ExchangeRates;
use SupplierSync\Domain\Pricing\PriceCalculator;
use SupplierSync\Domain\Pricing\PriceRule;
use SupplierSync\Domain\Pricing\PricingException;
use SupplierSync\Domain\Pricing\Rounding;

final class PricingTest extends TestCase {

	/**
	 * @dataProvider rounding_cases
	 */
	public function test_rounding_always_goes_up( Rounding $mode, float $in, float $out ): void {
		$this->assertSame( $out, $mode->apply( $in ) );
	}

	/**
	 * @return array<string, array{Rounding, float, float}>
	 */
	public static function rounding_cases(): array {
		return [
			'none keeps cents'        => [ Rounding::NONE, 12.345, 12.35 ],
			'whole'                   => [ Rounding::WHOLE, 12.01, 13.0 ],
			'tens'                    => [ Rounding::TENS, 1201.0, 1210.0 ],
			'ending 90'               => [ Rounding::ENDING_90, 1234.0, 1290.0 ],
			'ending 90 exact'         => [ Rounding::ENDING_90, 1290.0, 1290.0 ],
			'ending 90 next hundred'  => [ Rounding::ENDING_90, 1291.0, 1390.0 ],
			'ending 99'               => [ Rounding::ENDING_99, 12.10, 12.99 ],
			'ending 99 exact'         => [ Rounding::ENDING_99, 12.99, 12.99 ],
			'ending 99 whole'         => [ Rounding::ENDING_99, 13.0, 13.99 ],
			'ending 90 cents'         => [ Rounding::ENDING_90_CTS, 12.95, 13.9 ],
			'ending 90 cents exact'   => [ Rounding::ENDING_90_CTS, 12.9, 12.9 ],
			'float noise is absorbed' => [ Rounding::ENDING_90, 1289.9999999, 1290.0 ],
		];
	}

	public function test_most_specific_rule_wins(): void {
		$calculator = new PriceCalculator(
			[
				new PriceRule( 'cheap', 'Cheap items', max_cost: 20.0, markup_percent: 60 ),
				new PriceRule( 'audio', 'Audio', category_id: 15, markup_percent: 35 ),
				new PriceRule( 'nordline-audio', 'Nordline audio', supplier_id: 1, category_id: 15, markup_percent: 25 ),
			],
			new PriceRule( 'default', 'Default', markup_percent: 30 ),
			new ExchangeRates( 'USD', [] )
		);

		$this->assertSame( 'nordline-audio', $calculator->calculate( 10.0, 'USD', 1, [ 15, 3 ] )->rule_id );
		$this->assertSame( 'audio', $calculator->calculate( 10.0, 'USD', 2, [ 15 ] )->rule_id );
		$this->assertSame( 'cheap', $calculator->calculate( 10.0, 'USD', 2, [ 40 ] )->rule_id );
		$this->assertSame( 'default', $calculator->calculate( 50.0, 'USD', 2, [ 40 ] )->rule_id );
	}

	public function test_converts_currency_and_applies_markup_and_rounding(): void {
		$calculator = new PriceCalculator(
			[],
			new PriceRule( 'default', 'Default', markup_percent: 40, markup_fixed: 2, rounding: Rounding::ENDING_99 ),
			new ExchangeRates( 'USD', [ 'RUB' => 0.0125 ] )
		);

		// 2000 RUB = 25 USD, +40% = 35, +2 = 37, rounded up to 37.99.
		$result = $calculator->calculate( 2000.0, 'RUR', 1 );

		$this->assertSame( 25.0, $result->cost );
		$this->assertSame( 37.99, $result->regular_price );
		$this->assertNull( $result->sale_price );
	}

	public function test_minimum_margin_lifts_a_low_markup(): void {
		$calculator = new PriceCalculator(
			[ new PriceRule( 'promo', 'Promo', markup_percent: 2, min_margin_percent: 12 ) ],
			new PriceRule( 'default', 'Default' ),
			new ExchangeRates( 'USD', [] )
		);

		$result = $calculator->calculate( 100.0, 'USD', 1 );

		$this->assertSame( 112.0, $result->regular_price );
		$this->assertTrue( $result->margin_floor_applied );
	}

	public function test_supplier_rrp_becomes_regular_price_when_higher(): void {
		$calculator = new PriceCalculator(
			[],
			new PriceRule( 'default', 'Default', markup_percent: 20, rounding: Rounding::WHOLE ),
			new ExchangeRates( 'USD', [ 'EUR' => 1.1 ] )
		);

		$result = $calculator->calculate( 50.0, 'EUR', 1, [], 80.0 );

		$this->assertSame( 88.0, $result->regular_price );
		$this->assertSame( 66.0, $result->sale_price );
		$this->assertSame( 66.0, $result->final_price() );
	}

	public function test_unknown_currency_is_an_error(): void {
		$this->expectException( PricingException::class );
		( new ExchangeRates( 'USD', [ 'EUR' => 1.1 ] ) )->convert( 10.0, 'GBP' );
	}
}
