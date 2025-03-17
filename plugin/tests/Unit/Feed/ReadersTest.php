<?php
/**
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Tests\Unit\Feed;

use PHPUnit\Framework\TestCase;
use SupplierSync\Domain\Availability;
use SupplierSync\Domain\Offer;
use SupplierSync\Feed\FeedException;
use SupplierSync\Feed\FeedReader;
use SupplierSync\Feed\Number;
use SupplierSync\Feed\ReaderFactory;
use SupplierSync\Feed\RowError;

final class ReadersTest extends TestCase {

	private const FIXTURES = __DIR__ . '/../../fixtures/';

	public function test_yml_reads_offers_categories_params_and_stock(): void {
		$items = $this->collect( ( new ReaderFactory() )->create( 'yml', 1, [] ), 'catalog.yml.xml' );

		$this->assertCount( 3, $items );

		$first = $items[0];
		$this->assertInstanceOf( Offer::class, $first );
		$this->assertSame( 'AUR-ANC-BLK', $first->sku );
		$this->assertSame( '4600000000017', $first->ean );
		$this->assertSame( 8490.0, $first->price );
		$this->assertSame( 9990.0, $first->old_price );
		$this->assertSame( 'RUR', $first->currency );
		$this->assertSame( 12, $first->quantity );
		$this->assertSame( 'Audio > Headphones', $first->category_path );
		$this->assertSame( '77', $first->group_id );
		$this->assertSame(
			[
				'Color'        => 'Black',
				'Battery life' => '38 h',
			],
			$first->attributes
		);
		$this->assertCount( 2, $first->images );
		$this->assertStringContainsString( '<p>Closed-back', $first->description );

		$second = $items[1];
		$this->assertInstanceOf( Offer::class, $second );
		$this->assertSame( 'Portable speaker Pulse Mini 2', $second->name );
		$this->assertSame( Availability::ON_ORDER, $second->availability );
		$this->assertSame( 'RUB', $second->currency );

		$this->assertInstanceOf( RowError::class, $items[2] );
		$this->assertSame( 'offer NL-1003', $items[2]->reference );
	}

	public function test_yml_resumes_from_offset(): void {
		$items = $this->collect( ( new ReaderFactory() )->create( 'yml', 1, [] ), 'catalog.yml.xml', 1 );

		$this->assertSame( [ 1, 2 ], array_keys( $items ) );
		$this->assertInstanceOf( Offer::class, $items[1] );
		$this->assertSame( 'Audio', $items[1]->category_path );
	}

	public function test_non_yml_xml_is_rejected(): void {
		$this->expectException( FeedException::class );
		$this->collect( ( new ReaderFactory() )->create( 'yml', 1, [] ), 'products.xml' );
	}

	public function test_csv_with_semicolons_and_eu_numbers(): void {
		$reader = ( new ReaderFactory() )->create(
			'csv',
			2,
			[
				'currency' => 'EUR',
				'mapping'  => [
					'columns'           => [
						'sku'      => 'Артикул',
						'ean'      => 'EAN',
						'name'     => 'Наименование',
						'category' => 'Категория',
						'price'    => 'Цена',
						'quantity' => 'Остаток',
						'images'   => 'Фото',
						'group_id' => 'Модель',
					],
					'attribute_columns' => [ 'Цвет', 'Размер' ],
				],
			]
		);

		$items = $this->collect( $reader, 'pricelist.csv' );

		$this->assertCount( 3, $items, 'Blank lines are skipped.' );
		$this->assertInstanceOf( Offer::class, $items[0] );
		$this->assertSame( 'Ridge shell jacket; red, M', $items[0]->name );
		$this->assertSame( 1249.5, $items[0]->price );
		$this->assertSame( 'EUR', $items[0]->currency );
		$this->assertSame( 'Outdoor > Jackets', $items[0]->category_path );
		$this->assertSame(
			[
				'Цвет'   => 'Red',
				'Размер' => 'M',
			],
			$items[0]->attributes
		);
		$this->assertCount( 2, $items[0]->images );
		$this->assertSame( 'RIDGE', $items[0]->group_id );

		$this->assertInstanceOf( Offer::class, $items[1] );
		$this->assertSame( Availability::OUT_OF_STOCK, $items[1]->availability );

		$this->assertInstanceOf( RowError::class, $items[2] );
		$this->assertSame( 'row 4', $items[2]->reference );
	}

	public function test_xlsx_reads_shared_strings_numbers_and_gaps(): void {
		$reader = ( new ReaderFactory() )->create(
			'xlsx',
			3,
			[
				'currency' => 'EUR',
				'mapping'  => [
					'columns'           => [
						'sku'      => 'SKU',
						'name'     => 'Name',
						'price'    => 'Price EUR',
						'quantity' => 'Qty',
					],
					'attribute_columns' => [ 'Material' ],
				],
			]
		);

		$items = $this->collect( $reader, 'stock.xlsx' );

		$this->assertCount( 3, $items );
		$this->assertInstanceOf( Offer::class, $items[0] );
		$this->assertSame( 'PC-PACK-28', $items[0]->sku );
		$this->assertSame( 54.9, $items[0]->price );
		$this->assertSame( 6, $items[0]->quantity );
		$this->assertSame( [ 'Material' => 'Ripstop nylon' ], $items[0]->attributes );

		$this->assertInstanceOf( Offer::class, $items[1] );
		$this->assertNull( $items[1]->quantity );

		$this->assertInstanceOf( Offer::class, $items[2] );
		$this->assertSame( 8.5, $items[2]->price );
	}

	public function test_json_api_with_nested_fields(): void {
		$reader = ( new ReaderFactory() )->create(
			'json',
			4,
			[
				'currency' => 'USD',
				'mapping'  => [
					'items_path'       => 'data.items',
					'attribute_prefix' => 'specs',
					'columns'          => [
						'external_id' => 'id',
						'name'        => 'title',
						'ean'         => 'gtin',
						'price'       => 'pricing.net',
						'old_price'   => 'pricing.rrp',
						'quantity'    => 'stock.qty',
						'images'      => 'images',
					],
				],
			]
		);

		$items = $this->collect( $reader, 'api.json' );

		$this->assertCount( 2, $items );
		$this->assertInstanceOf( Offer::class, $items[0] );
		$this->assertSame( '501', $items[0]->external_id );
		$this->assertSame( 21.4, $items[0]->price );
		$this->assertSame( 39.0, $items[0]->old_price );
		$this->assertSame(
			[
				'Power' => '65 W',
				'Ports' => '2 × USB-C',
			],
			$items[0]->attributes
		);
		$this->assertCount( 2, $items[0]->images );
		$this->assertSame( 'ext:4:502', $items[1] instanceof Offer ? $items[1]->unit_key() : '' );
	}

	public function test_generic_xml_with_xpath_fields(): void {
		$reader = ( new ReaderFactory() )->create(
			'xml',
			5,
			[
				'mapping' => [
					'item_tag'        => 'product',
					'fields'          => [
						'external_id' => '@code',
						'sku'         => '@code',
						'name'        => 'title',
						'ean'         => 'ean',
						'price'       => 'price',
						'currency'    => 'price/@currency',
						'quantity'    => 'stock',
						'images'      => 'images/image',
					],
					'attributes_path' => 'specs/spec',
				],
			]
		);

		$items = $this->collect( $reader, 'products.xml' );

		$this->assertCount( 2, $items );
		$this->assertInstanceOf( Offer::class, $items[0] );
		$this->assertSame( 'KT-100', $items[0]->sku );
		$this->assertSame( 'EUR', $items[0]->currency );
		$this->assertSame(
			[
				'Volume' => '1.7 l',
				'Power'  => '2200 W',
			],
			$items[0]->attributes
		);
		$this->assertSame( [ 'https://feeds.test/img/kettle-1.jpg', 'https://feeds.test/img/kettle-2.jpg' ], $items[0]->images );
	}

	/**
	 * @dataProvider numbers
	 */
	public function test_number_parsing( string $raw, ?float $expected ): void {
		$this->assertSame( $expected, Number::parse( $raw ) );
	}

	/**
	 * @return array<string, array{string, float|null}>
	 */
	public static function numbers(): array {
		return [
			'plain'          => [ '12.5', 12.5 ],
			'eu decimal'     => [ '12,50', 12.5 ],
			'eu thousands'   => [ '1.234,50 €', 1234.5 ],
			'us thousands'   => [ '$1,234.50', 1234.5 ],
			'space thousand' => [ "8\u{00A0}490", 8490.0 ],
			'comma thousand' => [ '1,234', 1234.0 ],
			'garbage'        => [ 'n/a', null ],
		];
	}

	/**
	 * @return array<int, Offer|RowError>
	 */
	private function collect( FeedReader $reader, string $fixture, int $offset = 0 ): array {
		return iterator_to_array( $reader->read( self::FIXTURES . $fixture, $offset ) );
	}
}
