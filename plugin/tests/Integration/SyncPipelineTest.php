<?php
/**
 * End-to-end runs against a real WordPress + WooCommerce test install.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Tests\Integration;

use SupplierSync\Domain\Supplier;
use SupplierSync\Infrastructure\Persistence\RunRepository;
use SupplierSync\Infrastructure\Persistence\SupplierRepository;
use SupplierSync\Infrastructure\Settings;
use SupplierSync\Plugin;
use SupplierSync\Sync\Run;
use SupplierSync\Sync\RunService;

final class SyncPipelineTest extends \WP_UnitTestCase {

	private const FIXTURES = __DIR__ . '/../fixtures/integration/';

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'USD' );
		update_option(
			Settings::OPTION,
			[
				'rates'        => [ 'EUR' => 1.0 ],
				'default_rule' => [
					'markup_percent'     => 50,
					'min_margin_percent' => 0,
					'rounding'           => 'none',
				],
				'rules'        => [],
				'stock'        => [
					'when_zero'      => 'outofstock',
					'allow_on_order' => true,
				],
			]
		);
	}

	public function test_first_run_creates_products_and_the_second_changes_nothing(): void {
		$alpha = $this->supplier( 'Alpha', 'yml', 'alpha.yml' );

		$first = $this->sync( $alpha );
		$this->assertSame( Run::COMPLETED, $first->status, (string) $first->error );
		$this->assertSame( 3, $first->stats['created'] ?? 0, 'Two simple products and one variable product.' );

		$speaker = wc_get_product( wc_get_product_id_by_sku( 'ECHO-1' ) );
		$this->assertInstanceOf( \WC_Product_Simple::class, $speaker );
		$this->assertSame( '150.00', $speaker->get_regular_price() );
		$this->assertSame( 4, $speaker->get_stock_quantity() );
		$this->assertSame( '4000000000017', $speaker->get_global_unique_id() );
		$this->assertSame( [ 'Speakers' ], wp_list_pluck( get_the_terms( $speaker->get_id(), 'product_cat' ), 'name' ) );

		$buds = wc_get_product( wc_get_product_id_by_sku( 'ECHO-BUDS-BLK' ) );
		$this->assertInstanceOf( \WC_Product_Variation::class, $buds );
		$parent = wc_get_product( $buds->get_parent_id() );
		$this->assertInstanceOf( \WC_Product_Variable::class, $parent );
		$this->assertSame( 'Echo buds', $parent->get_name() );
		$this->assertCount( 2, $parent->get_children() );

		$second = $this->sync( $alpha );
		$this->assertSame( 0, ( $second->stats['created'] ?? 0 ) + ( $second->stats['updated'] ?? 0 ) );
		$this->assertSame( 3, $second->stats['unchanged'] ?? 0 );
	}

	public function test_dry_run_reports_changes_without_touching_products(): void {
		$alpha = $this->supplier( 'Alpha', 'yml', 'alpha.yml' );
		$this->sync( $alpha );

		$alpha = $this->repoint( $alpha, 'alpha-v2.yml' );
		$dry   = $this->sync( $alpha, true );

		$updated = Plugin::container()->get( RunRepository::class )->items( $dry->id, 'updated', '', 10, 0 );
		$this->assertSame( 1, $dry->stats['updated'] ?? 0, 'Only the speaker got cheaper: ' . wp_json_encode( $updated['items'] ) );
		$this->assertSame( 1, $dry->stats['unpublished'] ?? 0, 'The cable left the feed.' );
		$this->assertSame( '150.00', wc_get_product( wc_get_product_id_by_sku( 'ECHO-1' ) )->get_regular_price() );
		$this->assertSame( 'publish', get_post_status( wc_get_product_id_by_sku( 'ECHO-CBL' ) ) );

		$this->assertSame( [ '150.00', 135.0 ], $updated['items'][0]['changes']['regular_price'] );
	}

	public function test_offers_merge_by_ean_and_the_cheapest_supplier_sets_the_price(): void {
		$this->sync( $this->supplier( 'Alpha', 'yml', 'alpha.yml' ) );
		$beta = $this->supplier(
			'Beta',
			'csv',
			'beta.csv',
			[
				'columns' => [
					'sku'      => 'sku',
					'ean'      => 'ean',
					'name'     => 'name',
					'price'    => 'price',
					'quantity' => 'stock',
				],
			]
		);

		$run = $this->sync( $beta );
		$this->assertSame( 1, $run->stats['created'] ?? 0 );
		$this->assertSame( 1, $run->stats['updated'] ?? 0 );

		$speaker = wc_get_product( wc_get_product_id_by_sku( 'ECHO-1' ) );
		$this->assertSame( '120.00', $speaker->get_regular_price(), 'Beta sells it for 80, plus 50%.' );
		$this->assertSame( 10, $speaker->get_stock_quantity(), 'Stock is summed: 4 + 6.' );
		$this->assertSame( 'Echo speaker', $speaker->get_name(), 'The first supplier keeps the card content.' );
	}

	public function test_offer_missing_from_the_feed_takes_the_product_off_sale(): void {
		$alpha = $this->supplier( 'Alpha', 'yml', 'alpha.yml', [], 100 );
		$this->sync( $alpha );

		$run = $this->sync( $this->repoint( $alpha, 'alpha-v2.yml' ) );

		$this->assertSame( 1, $run->stats['unpublished'] ?? 0 );
		$cable = wc_get_product( wc_get_product_id_by_sku( 'ECHO-CBL' ) );
		$this->assertSame( 'draft', $cable->get_status() );
		$this->assertSame( 'outofstock', $cable->get_stock_status() );
	}

	public function test_safety_brake_stops_mass_removal(): void {
		$alpha = $this->supplier( 'Alpha', 'yml', 'alpha.yml', [], 10 );
		$this->sync( $alpha );

		$run = $this->sync( $this->repoint( $alpha, 'alpha-v2.yml' ) );

		$this->assertSame( 0, $run->stats['unpublished'] ?? 0 );
		$this->assertSame( 'publish', get_post_status( wc_get_product_id_by_sku( 'ECHO-CBL' ) ) );
	}

	/**
	 * @param array<string, mixed> $mapping
	 */
	private function supplier( string $name, string $type, string $fixture, array $mapping = [], int $max_removal = 30 ): Supplier {
		return Plugin::container()->get( SupplierRepository::class )->save(
			new Supplier(
				0,
				$name,
				$type,
				[
					'source'              => [ 'attachment_id' => $this->attachment( $fixture ) ],
					'currency'            => 'EUR',
					'mapping'             => $mapping,
					'max_removal_percent' => $max_removal,
				]
			)
		);
	}

	private function repoint( Supplier $supplier, string $fixture ): Supplier {
		$config                            = $supplier->config;
		$config['source']['attachment_id'] = $this->attachment( $fixture );
		return Plugin::container()->get( SupplierRepository::class )->save(
			new Supplier( $supplier->id, $supplier->name, $supplier->type, $config )
		);
	}

	private function attachment( string $fixture ): int {
		$id = self::factory()->attachment->create( [ 'post_mime_type' => 'text/plain' ] );
		update_attached_file( $id, self::FIXTURES . $fixture );
		return $id;
	}

	private function sync( Supplier $supplier, bool $dry_run = false ): Run {
		$service = Plugin::container()->get( RunService::class );
		$run     = $service->start( $supplier, $dry_run, 'cli' );
		return $service->run_now( $run->id, static function (): void {} );
	}
}
