<?php
/**
 * Supplier profile: where the feed lives and how to read and map it.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Domain;

final class Supplier {

	public const UPDATABLE_FIELDS = [ 'name', 'description', 'images', 'categories', 'attributes', 'regular_price', 'sale_price', 'quantity', 'stock_status', 'variations' ];

	/**
	 * @param int                  $id           Row ID, 0 for a new profile.
	 * @param string               $name         Display name.
	 * @param string               $type         yml | xml | csv | xlsx | json | site.
	 * @param array<string, mixed> $config       source, currency, mapping, scraper, attributes, categories, update_fields.
	 * @param string               $schedule     manual | hourly | every_6_hours | daily.
	 * @param bool                 $enabled      Disabled profiles are skipped by the scheduler.
	 * @param string               $pricing_hash Pricing settings used by the last live run.
	 * @param int|null             $last_run_id  Last run.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $type,
		public readonly array $config,
		public readonly string $schedule = 'manual',
		public readonly bool $enabled = true,
		public readonly string $pricing_hash = '',
		public readonly ?int $last_run_id = null,
	) {}

	public function source_url(): string {
		return (string) ( $this->config['source']['url'] ?? '' );
	}

	public function currency(): string {
		return (string) ( $this->config['currency'] ?? '' );
	}

	/**
	 * Fields this supplier must not overwrite on existing products.
	 *
	 * @return list<string>
	 */
	public function locked_fields(): array {
		$allowed = (array) ( $this->config['update_fields'] ?? self::UPDATABLE_FIELDS );
		return array_values( array_diff( self::UPDATABLE_FIELDS, $allowed ) );
	}

	/**
	 * @return array<string, string>
	 */
	public function attribute_map(): array {
		return array_map( 'strval', (array) ( $this->config['attributes'] ?? [] ) );
	}

	/**
	 * Supplier category (ID or path) => store term ID or store path ("Audio > Headphones"),
	 * 0 to skip products of that category.
	 *
	 * @return array<string, int|string>
	 */
	public function category_map(): array {
		return array_map(
			static fn ( $target ): int|string => is_numeric( $target ) ? (int) $target : trim( (string) $target ),
			(array) ( $this->config['categories'] ?? [] )
		);
	}

	/**
	 * Safety brake: a broken feed must not unpublish half the catalog.
	 */
	public function max_removal_percent(): int {
		return (int) ( $this->config['max_removal_percent'] ?? 30 );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'id'          => $this->id,
			'name'        => $this->name,
			'type'        => $this->type,
			'config'      => $this->config,
			'schedule'    => $this->schedule,
			'enabled'     => $this->enabled,
			'last_run_id' => $this->last_run_id,
		];
	}
}
