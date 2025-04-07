<?php
/**
 * Supplier category -> store categories, following the mapping in the supplier profile.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Infrastructure\Woo;

use SupplierSync\Domain\Offer;
use SupplierSync\Domain\Supplier;

final class CategoryResolver {

	/**
	 * @var array<string, int|null>
	 */
	private array $path_cache = [];

	/**
	 * @return array{ids: list<int>, lineage: list<int>, skip: bool}
	 *         ids: categories to assign, lineage: ids plus ancestors (for price rules),
	 *         skip: the profile says not to import this category.
	 *
	 * @param Supplier $supplier Profile with the mapping.
	 * @param Offer    $offer    Offer that anchors the product.
	 * @param bool     $create   False in dry runs.
	 */
	public function resolve( Supplier $supplier, Offer $offer, bool $create = true ): array {
		$map = $supplier->category_map();

		$mapped = null;
		foreach ( [ $offer->category_id, $offer->category_path ] as $key ) {
			if ( '' !== $key && array_key_exists( $key, $map ) ) {
				$mapped = $map[ $key ];
				break;
			}
		}

		if ( 0 === $mapped ) {
			return [
				'ids'     => [],
				'lineage' => [],
				'skip'    => true,
			];
		}

		if ( is_string( $mapped ) ) {
			$term_id = '' !== $mapped ? $this->ensure_path( $mapped, 0, $create ) : null;
		} elseif ( is_int( $mapped ) ) {
			$term_id = $mapped;
		} else {
			$policy = (string) ( $supplier->config['unmapped_categories'] ?? 'create' );
			if ( 'skip' === $policy ) {
				return [
					'ids'     => [],
					'lineage' => [],
					'skip'    => true,
				];
			}
			$term_id = 'create' === $policy
				? $this->ensure_path( $offer->category_path, $this->root( $supplier, $create ), $create )
				: (int) $policy;
		}

		if ( null === $term_id || $term_id <= 0 ) {
			return [
				'ids'     => [],
				'lineage' => [],
				'skip'    => false,
			];
		}

		$ancestors = array_map( 'intval', get_ancestors( $term_id, 'product_cat', 'taxonomy' ) );
		return [
			'ids'     => [ $term_id ],
			'lineage' => array_values( array_merge( [ $term_id ], $ancestors ) ),
			'skip'    => false,
		];
	}

	/**
	 * Parent for auto-created categories: a term ID or a path such as "Outdoor".
	 */
	private function root( Supplier $supplier, bool $create ): int {
		$root = $supplier->config['category_root'] ?? 0;
		if ( is_numeric( $root ) ) {
			return (int) $root;
		}
		return (int) $this->ensure_path( (string) $root, 0, $create );
	}

	/**
	 * "Audio > Headphones" -> term ID of Headphones under Audio, created level by level.
	 *
	 * @param string $path   Category path.
	 * @param int    $root   Parent for the top level.
	 * @param bool   $create Whether missing terms may be created.
	 */
	private function ensure_path( string $path, int $root, bool $create ): ?int {
		$cache_key = $root . '|' . $path . '|' . (int) $create;
		if ( array_key_exists( $cache_key, $this->path_cache ) ) {
			return $this->path_cache[ $cache_key ];
		}

		$parent = $root;
		$names  = array_filter( array_map( 'trim', explode( '>', $path ) ), static fn ( string $n ): bool => '' !== $n );
		if ( [] === $names ) {
			$this->path_cache[ $cache_key ] = null;
			return $this->path_cache[ $cache_key ];
		}

		foreach ( $names as $name ) {
			$existing = get_terms(
				[
					'taxonomy'   => 'product_cat',
					'name'       => $name,
					'parent'     => $parent,
					'hide_empty' => false,
					'number'     => 1,
					'fields'     => 'ids',
				]
			);

			if ( is_array( $existing ) && [] !== $existing ) {
				$parent = (int) $existing[0];
				continue;
			}
			if ( ! $create ) {
				$this->path_cache[ $cache_key ] = null;
				return $this->path_cache[ $cache_key ];
			}

			$created = wp_insert_term( $name, 'product_cat', [ 'parent' => $parent ] );
			if ( is_wp_error( $created ) ) {
				$existing_id = $created->get_error_data( 'term_exists' );
				if ( ! is_numeric( $existing_id ) ) {
					$this->path_cache[ $cache_key ] = null;
					return $this->path_cache[ $cache_key ];
				}
				$parent = (int) $existing_id;
				continue;
			}
			$parent = (int) $created['term_id'];
		}

		$this->path_cache[ $cache_key ] = $parent;
		return $this->path_cache[ $cache_key ];
	}
}
