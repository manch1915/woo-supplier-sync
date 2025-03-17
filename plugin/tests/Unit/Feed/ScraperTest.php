<?php
/**
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Tests\Unit\Feed;

use PHPUnit\Framework\TestCase;
use SupplierSync\Feed\Scraper\CssSelector;
use SupplierSync\Feed\Scraper\PageParser;

final class ScraperTest extends TestCase {

	private const FIXTURES = __DIR__ . '/../../fixtures/';

	/**
	 * @dataProvider selectors
	 */
	public function test_css_to_xpath( string $css, string $xpath ): void {
		$this->assertSame( $xpath, CssSelector::to_xpath( $css ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function selectors(): array {
		return [
			'tag'        => [ 'h1', './/h1' ],
			'class'      => [ '.price', ".//*[contains(concat(' ', normalize-space(@class), ' '), ' price ')]" ],
			'id and tag' => [ 'div#main', ".//div[@id='main']" ],
			'child'      => [ 'ul > li', './/ul/li' ],
			'descendant' => [ 'table tr', './/table//tr' ],
			'attribute'  => [ '[itemprop="gtin13"]', ".//*[@itemprop='gtin13']" ],
			'contains'   => [ 'a[href*=page]', ".//a[contains(@href, 'page')]" ],
			'nth'        => [ 'li:nth-child(2)', './/li[count(preceding-sibling::*) = 1]' ],
			'list'       => [ 'h1, h2', './/h1 | .//h2' ],
		];
	}

	public function test_unsupported_syntax_is_reported(): void {
		$this->expectException( \InvalidArgumentException::class );
		CssSelector::to_xpath( 'a:hover' );
	}

	public function test_product_page_is_parsed_into_a_flat_record(): void {
		$parser = new PageParser(
			[
				'name'            => 'h1.product__title',
				'sku'             => 'main.product@data-sku',
				'ean'             => '[itemprop=gtin13]',
				'price'           => '.price__now',
				'old_price'       => '.price__was',
				'quantity'        => '.stock',
				'category'        => '.breadcrumbs > *:nth-child(2), .breadcrumbs > span',
				'images'          => '.gallery img@src',
				'description'     => '.description',
				'attribute_rows'  => 'table.specs tr',
				'attribute_name'  => 'th',
				'attribute_value' => 'td',
			]
		);

		$record = $parser->product( (string) file_get_contents( self::FIXTURES . 'product-page.html' ), 'https://volta.test/p/power-bank-20000.html' );

		$this->assertSame( 'Volta Power Bank 20000', $record['name'] );
		$this->assertSame( 'VT-PB-20K', $record['sku'] );
		$this->assertSame( '5901234123464', $record['ean'] );
		$this->assertSame( '$34.90', $record['price'] );
		$this->assertSame( 'In stock: 14 pcs', $record['quantity'] );
		$this->assertSame( 'Power > Power banks', $record['category'] );
		$this->assertSame( [ 'https://volta.test/img/powerbank-1.jpg', 'https://volta.test/img/powerbank-2.jpg' ], $record['images'] );
		$this->assertSame( '<p>Charges a laptop <strong>twice</strong>.</p>', $record['description'] );
		$this->assertSame(
			[
				'Capacity' => '20 000 mAh',
				'Weight'   => '410 g',
			],
			$record['@attributes']
		);
	}

	public function test_listing_page_gives_unique_absolute_links_and_next_page(): void {
		$parser  = new PageParser(
			[
				'product_links' => '.card__link',
				'next_page'     => 'a[rel=next]',
			]
		);
		$listing = $parser->listing( (string) file_get_contents( self::FIXTURES . 'listing-page.html' ), 'https://volta.test/c/power/index.html' );

		$this->assertSame(
			[ 'https://volta.test/p/power-bank-20000.html', 'https://volta.test/c/power/power-bank-10000.html' ],
			$listing['products']
		);
		$this->assertSame( 'https://volta.test/c/power/?page=2', $listing['next'] );
	}
}
