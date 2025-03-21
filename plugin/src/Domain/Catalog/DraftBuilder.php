<?php
/**
 * Builds the desired product from merged units of one group.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Domain\Catalog;

final class DraftBuilder {

	private const BRAND = 'Brand';

	public function __construct( private readonly StockPolicy $policy ) {}

	/**
	 * @param string                       $group_key    Product identity.
	 * @param non-empty-list<AggregatedUnit> $units      Units of the product.
	 * @param list<int>                    $category_ids Resolved store categories.
	 *
	 * @throws DraftException When no unit has a price.
	 */
	public function build( string $group_key, array $units, array $category_ids ): ProductDraft {
		$priced = array_values( array_filter( $units, static fn ( AggregatedUnit $u ): bool => null !== $u->price ) );
		if ( [] === $priced ) {
			$errors = array_filter( array_map( static fn ( AggregatedUnit $u ): string => (string) $u->error, $units ) );
			throw new DraftException( implode( ' ', array_unique( $errors ) ) );
		}

		usort( $priced, static fn ( AggregatedUnit $a, AggregatedUnit $b ): int => strcmp( (string) $a->sku(), (string) $b->sku() ) );

		return count( $priced ) > 1
			? $this->build_variable( $group_key, $priced, $category_ids )
			: $this->build_simple( $group_key, $priced[0], $category_ids );
	}

	/**
	 * @param string         $group_key    Product identity.
	 * @param AggregatedUnit $unit         The only unit.
	 * @param list<int>      $category_ids Store categories.
	 */
	private function build_simple( string $group_key, AggregatedUnit $unit, array $category_ids ): ProductDraft {
		$offer = $unit->content();
		$price = $unit->price;
		assert( null !== $price );

		$attributes = [];
		foreach ( $this->with_brand( $offer->attributes, $offer->vendor ) as $name => $value ) {
			$attributes[] = [
				'name'      => $name,
				'values'    => [ $value ],
				'variation' => false,
			];
		}

		[ $status, $visibility ] = $this->publication( $unit->stock_status );

		return new ProductDraft(
			group_key: $group_key,
			type: ProductDraft::SIMPLE,
			name: $offer->name,
			description: $offer->description,
			sku: $offer->sku ?? $this->fallback_sku( $unit ),
			category_ids: $category_ids,
			attributes: $attributes,
			images: $offer->images,
			regular_price: $price->regular_price,
			sale_price: $price->sale_price,
			quantity: $unit->quantity,
			stock_status: $unit->stock_status,
			status: $status,
			visibility: $visibility,
			meta: [
				'ean'       => $offer->ean,
				'cost'      => $price->cost,
				'rule'      => $price->rule_id,
				'suppliers' => $this->supplier_list( [ $unit ] ),
			],
		);
	}

	/**
	 * @param string               $group_key    Product identity.
	 * @param list<AggregatedUnit> $units        Units with prices, at least two.
	 * @param list<int>            $category_ids Store categories.
	 */
	private function build_variable( string $group_key, array $units, array $category_ids ): ProductDraft {
		$values_by_name = [];
		foreach ( $units as $unit ) {
			foreach ( $this->with_brand( $unit->content()->attributes, $unit->content()->vendor ) as $name => $value ) {
				$values_by_name[ $name ][ $unit->unit_key ] = $value;
			}
		}

		$variation_names = [];
		$attributes      = [];
		foreach ( $values_by_name as $name => $values ) {
			$unique       = array_values( array_unique( $values ) );
			$is_variation = count( $unique ) > 1 && count( $values ) === count( $units );
			if ( $is_variation ) {
				$variation_names[] = $name;
			}
			$attributes[] = [
				'name'      => (string) $name,
				'values'    => array_map( 'strval', $unique ),
				'variation' => $is_variation,
			];
		}

		// Same attributes everywhere: tell variations apart by their own titles.
		if ( [] === $variation_names ) {
			$variation_names = [ 'Variant' ];
			$attributes[]    = [
				'name'      => 'Variant',
				'values'    => array_map( static fn ( AggregatedUnit $u ): string => $u->content()->name, $units ),
				'variation' => true,
			];
			foreach ( $units as $unit ) {
				$values_by_name['Variant'][ $unit->unit_key ] = $unit->content()->name;
			}
		}

		$variations = [];
		$images     = [];
		foreach ( $units as $unit ) {
			$price = $unit->price;
			assert( null !== $price );

			$options = [];
			foreach ( $variation_names as $name ) {
				$options[ $name ] = (string) ( $values_by_name[ $name ][ $unit->unit_key ] ?? '' );
			}

			$variations[] = new VariationDraft(
				unit_key: $unit->unit_key,
				sku: $unit->sku() ?? $this->fallback_sku( $unit ),
				attributes: $options,
				regular_price: $price->regular_price,
				sale_price: $price->sale_price,
				quantity: $unit->quantity,
				stock_status: $unit->stock_status,
				image: $unit->content()->images[0] ?? null,
				ean: $unit->content()->ean,
			);
			array_push( $images, ...$unit->content()->images );
		}

		$stock_status            = $this->parent_stock_status( $units );
		[ $status, $visibility ] = $this->publication( $stock_status );
		$description             = '';
		foreach ( $units as $unit ) {
			if ( strlen( $unit->content()->description ) > strlen( $description ) ) {
				$description = $unit->content()->description;
			}
		}

		return new ProductDraft(
			group_key: $group_key,
			type: ProductDraft::VARIABLE,
			name: $this->common_name( array_map( static fn ( AggregatedUnit $u ): string => $u->content()->name, $units ) ),
			description: $description,
			sku: null,
			category_ids: $category_ids,
			attributes: $attributes,
			images: array_values( array_unique( $images ) ),
			regular_price: null,
			sale_price: null,
			quantity: null,
			stock_status: $stock_status,
			status: $status,
			visibility: $visibility,
			variations: $variations,
			meta: [
				'suppliers' => $this->supplier_list( $units ),
			],
		);
	}

	/**
	 * Longest common word prefix: "Aurora ANC, black" + "Aurora ANC, white" = "Aurora ANC".
	 *
	 * @param list<string> $names Variation titles.
	 */
	public function common_name( array $names ): string {
		$split  = array_map( static fn ( string $n ): array => (array) preg_split( '/\s+/u', trim( $n ) ), $names );
		$prefix = [];
		foreach ( $split[0] as $i => $word ) {
			foreach ( $split as $words ) {
				if ( ( $words[ $i ] ?? null ) !== $word ) {
					break 2;
				}
			}
			$prefix[] = $word;
		}

		$name = rtrim( implode( ' ', $prefix ), " \t,;:-–—(/" );
		return '' !== $name ? $name : $names[0];
	}

	/**
	 * @param array<string, string> $attributes Offer attributes.
	 * @param string                $vendor     Brand.
	 * @return array<string, string>
	 */
	private function with_brand( array $attributes, string $vendor ): array {
		if ( '' !== $vendor && ! isset( $attributes[ self::BRAND ] ) ) {
			$attributes = [ self::BRAND => $vendor ] + $attributes;
		}
		return $attributes;
	}

	/**
	 * @return array{0: string, 1: string}
	 *
	 * @param string $stock_status Stock status of the product.
	 */
	private function publication( string $stock_status ): array {
		if ( AggregatedUnit::OUT_OF_STOCK !== $stock_status ) {
			return [ 'publish', 'visible' ];
		}
		return match ( $this->policy->when_zero ) {
			StockPolicy::ZERO_DRAFT => [ 'draft', 'visible' ],
			StockPolicy::ZERO_HIDE  => [ 'publish', 'hidden' ],
			default                 => [ 'publish', 'visible' ],
		};
	}

	/**
	 * @param list<AggregatedUnit> $units Units of a variable product.
	 */
	private function parent_stock_status( array $units ): string {
		$statuses = array_map( static fn ( AggregatedUnit $u ): string => $u->stock_status, $units );
		foreach ( [ AggregatedUnit::IN_STOCK, AggregatedUnit::ON_BACKORDER ] as $status ) {
			if ( in_array( $status, $statuses, true ) ) {
				return $status;
			}
		}
		return AggregatedUnit::OUT_OF_STOCK;
	}

	/**
	 * @param list<AggregatedUnit> $units Units.
	 */
	private function supplier_list( array $units ): string {
		$ids = [];
		foreach ( $units as $unit ) {
			foreach ( $unit->sources as $source ) {
				$ids[] = (int) $source['supplier_id'];
			}
		}
		$ids = array_unique( $ids );
		sort( $ids );
		return implode( ',', $ids );
	}

	private function fallback_sku( AggregatedUnit $unit ): string {
		return 'S' . $unit->content()->supplier_id . '-' . $unit->content()->external_id;
	}
}
