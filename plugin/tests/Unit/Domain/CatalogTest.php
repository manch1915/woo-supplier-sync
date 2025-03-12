<?php
/**
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SupplierSync\Domain\Availability;
use SupplierSync\Domain\Catalog\AggregatedUnit;
use SupplierSync\Domain\Catalog\ChangeSet;
use SupplierSync\Domain\Catalog\Differ;
use SupplierSync\Domain\Catalog\DraftBuilder;
use SupplierSync\Domain\Catalog\DraftException;
use SupplierSync\Domain\Catalog\OfferAggregator;
use SupplierSync\Domain\Catalog\ProductDraft;
use SupplierSync\Domain\Catalog\StockPolicy;
use SupplierSync\Domain\Offer;
use SupplierSync\Domain\Pricing\ExchangeRates;
use SupplierSync\Domain\Pricing\PriceCalculator;
use SupplierSync\Domain\Pricing\PriceRule;

final class CatalogTest extends TestCase {

	private OfferAggregator $aggregator;
	private DraftBuilder $builder;

	protected function setUp(): void {
		$policy           = new StockPolicy( StockPolicy::ZERO_HIDE, true );
		$this->aggregator = new OfferAggregator(
			new PriceCalculator( [], new PriceRule( 'default', 'Default', markup_percent: 50 ), new ExchangeRates( 'USD', [ 'EUR' => 1.0 ] ) ),
			$policy
		);
		$this->builder    = new DraftBuilder( $policy );
	}

	public function test_offer_keys_prefer_ean_then_sku(): void {
		$this->assertSame( 'ean:4006381333931', $this->offer( 1, ean: '4006381333931' )->unit_key() );
		$this->assertSame( 'sku:ab12', $this->offer( 1, sku: ' AB-12 ' )->unit_key() );
		$this->assertSame( 'ext:1:x1', $this->offer( 1, ean: '123' )->unit_key() );
		$this->assertSame( 'grp:2:77', $this->offer( 2, group: '77' )->default_group_key() );
	}

	public function test_cheapest_in_stock_supplier_wins_and_stock_is_summed(): void {
		$unit = $this->aggregator->aggregate(
			[
				$this->offer( 1, price: 10.0, qty: 3 ),
				$this->offer( 2, price: 8.0, qty: 5 ),
				$this->offer( 3, price: 5.0, qty: 0 ),
			]
		);

		$this->assertSame( 2, $unit->primary->supplier_id );
		$this->assertSame( 12.0, $unit->price?->regular_price );
		$this->assertSame( 8, $unit->quantity );
		$this->assertSame( AggregatedUnit::IN_STOCK, $unit->stock_status );
		$this->assertCount( 3, $unit->sources );
	}

	public function test_unknown_quantity_keeps_stock_unmanaged(): void {
		$unit = $this->aggregator->aggregate( [ $this->offer( 1, qty: 4 ), $this->offer( 2, qty: null ) ] );

		$this->assertNull( $unit->quantity );
		$this->assertFalse( $unit->manage_stock() );
	}

	public function test_on_order_offer_becomes_backorder(): void {
		$unit = $this->aggregator->aggregate( [ $this->offer( 1, qty: null, available: false ), $this->offer( 2, qty: 0 ) ] );

		$this->assertSame( AggregatedUnit::ON_BACKORDER, $unit->stock_status );
		$this->assertSame( 0, $unit->quantity );
	}

	public function test_offer_without_price_is_reported(): void {
		$unit = $this->aggregator->aggregate( [ $this->offer( 1, price: null ) ] );

		$this->assertNull( $unit->price );
		$this->assertSame( 'Missing purchase price.', $unit->error );

		$this->expectException( DraftException::class );
		$this->builder->build( 'k', [ $unit ], [] );
	}

	public function test_simple_draft_adds_brand_and_hides_when_out_of_stock(): void {
		$unit  = $this->aggregator->aggregate( [ $this->offer( 1, qty: 0, attributes: [ 'Color' => 'Black' ] ) ] );
		$draft = $this->builder->build( 'ean:4006381333931', [ $unit ], [ 7 ] );

		$this->assertSame( ProductDraft::SIMPLE, $draft->type );
		$this->assertSame( 'hidden', $draft->visibility );
		$this->assertSame( [ 'Brand', 'Color' ], array_column( $draft->attributes, 'name' ) );
	}

	public function test_variable_draft_detects_variation_attributes(): void {
		$units = [
			$this->aggregator->aggregate(
				[
					$this->offer(
						1,
						sku: 'AUR-BLK',
						name: 'Aurora ANC headphones, black',
						attributes: [
							'Color'  => 'Black',
							'Driver' => '40 mm',
						]
					),
				]
			),
			$this->aggregator->aggregate(
				[
					$this->offer(
						1,
						sku: 'AUR-WHT',
						name: 'Aurora ANC headphones, white',
						price: 12.0,
						attributes: [
							'Color'  => 'White',
							'Driver' => '40 mm',
						]
					),
				]
			),
		];

		$draft = $this->builder->build( 'grp:1:aurora', $units, [] );

		$this->assertTrue( $draft->is_variable() );
		$this->assertSame( 'Aurora ANC headphones', $draft->name );
		$this->assertCount( 2, $draft->variations );
		$this->assertSame( [ 'Color' => 'Black' ], $draft->variations[0]->attributes );
		$this->assertSame( 18.0, $draft->variations[1]->regular_price );

		$by_name = array_column( $draft->attributes, null, 'name' );
		$this->assertTrue( $by_name['Color']['variation'] );
		$this->assertFalse( $by_name['Driver']['variation'] );
	}

	public function test_differ_reports_create_update_and_unchanged(): void {
		$differ = new Differ();
		$draft  = $this->builder->build( 'k', [ $this->aggregator->aggregate( [ $this->offer( 1, qty: 5 ) ] ) ], [ 3 ] );

		$this->assertSame( ChangeSet::CREATE, $differ->diff( null, $draft )->action );

		$current = $draft->to_comparable();
		$this->assertTrue( $differ->diff( $current, $draft )->is_noop() );

		$current['regular_price'] = '14.00';
		$current['quantity']      = '2';
		$changes                  = $differ->diff( $current, $draft );
		$this->assertSame( ChangeSet::UPDATE, $changes->action );
		$this->assertSame( [ 'regular_price', 'quantity' ], $changes->changed_fields() );

		$this->assertTrue( $differ->diff( $current, $draft, [ 'regular_price', 'quantity' ] )->is_noop() );
	}

	public function test_differ_flags_unpublish_and_removed_variations(): void {
		$differ = new Differ();
		$draft  = new ProductDraft( 'k', ProductDraft::SIMPLE, 'Lamp', '', 'L1', [], [], [], 10.0, null, 0, 'outofstock', 'draft', 'visible' );

		$current           = $draft->to_comparable();
		$current['status'] = 'publish';
		$this->assertSame( ChangeSet::UNPUBLISH, $differ->diff( $current, $draft )->action );

		$units    = [
			$this->aggregator->aggregate( [ $this->offer( 1, sku: 'A', name: 'Tee S', attributes: [ 'Size' => 'S' ] ) ] ),
			$this->aggregator->aggregate( [ $this->offer( 1, sku: 'B', name: 'Tee M', attributes: [ 'Size' => 'M' ] ) ] ),
		];
		$variable = $this->builder->build( 'g', $units, [] );
		$current  = $variable->to_comparable();

		$current['variations']['C'] = [ 'regular_price' => 15.0 ];
		$this->assertArrayHasKey( 'variations.C.regular_price', $differ->diff( $current, $variable )->changes );
	}

	/**
	 * @param array<string, string> $attributes
	 */
	private function offer(
		int $supplier,
		?string $sku = null,
		?string $ean = '4006381333931',
		?float $price = 10.0,
		?int $qty = 5,
		bool $available = true,
		string $name = 'Pulse mini speaker',
		array $attributes = [],
		?string $group = null,
	): Offer {
		if ( null !== $sku && '4006381333931' === $ean ) {
			$ean = null;
		}
		return new Offer(
			supplier_id: $supplier,
			external_id: 'x1',
			name: $name,
			sku: $sku,
			ean: $ean,
			price: $price,
			currency: 'EUR',
			quantity: $qty,
			availability: Availability::from_stock( $qty, $available ),
			attributes: $attributes,
			group_id: $group,
			vendor: 'Pulse',
		);
	}
}
