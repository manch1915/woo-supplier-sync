<?php
/**
 * Global WooCommerce attributes (pa_*) and their terms. Global attributes are what
 * layered navigation, product filter blocks and the comparison table work with.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Infrastructure\Woo;

final class AttributeRegistry {

	/**
	 * @var array<string, string> Label => taxonomy.
	 */
	private array $taxonomies = [];

	/**
	 * @var array<string, int> Taxonomy|value => term ID.
	 */
	private array $terms = [];

	/**
	 * Returns the taxonomy name for an attribute label, creating the attribute when needed.
	 *
	 * @param string $label  Attribute label, e.g. "Color".
	 * @param bool   $create False in dry runs.
	 */
	public function taxonomy( string $label, bool $create = true ): ?string {
		$key = mb_strtolower( $label );
		if ( isset( $this->taxonomies[ $key ] ) ) {
			return $this->taxonomies[ $key ];
		}

		$existing = $this->find_by_label( $label );
		if ( null !== $existing ) {
			$this->taxonomies[ $key ] = $existing;
			return $this->taxonomies[ $key ];
		}
		if ( ! $create ) {
			return null;
		}

		$slug = Slug::attribute( $label );
		$id   = wc_create_attribute(
			[
				'name'         => $label,
				'slug'         => $slug,
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			]
		);
		if ( is_wp_error( $id ) ) {
			// Another request created it a moment ago, or the slug is taken.
			$existing = $this->find_by_label( $label );
			if ( null === $existing ) {
				throw new \RuntimeException( esc_html( $id->get_error_message() ) );
			}
			$this->taxonomies[ $key ] = $existing;
			return $this->taxonomies[ $key ];
		}

		$taxonomy = wc_attribute_taxonomy_name( $slug );
		// Attribute taxonomies are registered on init; a new one has to be registered by hand
		// before terms can be inserted in the same request.
		register_taxonomy(
			$taxonomy,
			[ 'product' ],
			[
				'labels'       => [
					'name'          => $label,
					'singular_name' => $label,
				],
				'hierarchical' => false,
				'show_ui'      => false,
				'query_var'    => true,
				'rewrite'      => false,
			]
		);

		$this->taxonomies[ $key ] = $taxonomy;
		return $this->taxonomies[ $key ];
	}

	/**
	 * @param string       $taxonomy Attribute taxonomy.
	 * @param list<string> $values   Term names.
	 * @param bool         $create   False in dry runs.
	 * @return list<int>
	 */
	public function term_ids( string $taxonomy, array $values, bool $create = true ): array {
		$ids = [];
		foreach ( $values as $value ) {
			$id = $this->term_id( $taxonomy, $value, $create );
			if ( null !== $id ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	public function term_id( string $taxonomy, string $value, bool $create = true ): ?int {
		$key = $taxonomy . '|' . mb_strtolower( $value );
		if ( isset( $this->terms[ $key ] ) ) {
			return $this->terms[ $key ];
		}

		$term = get_term_by( 'name', $value, $taxonomy );
		if ( $term instanceof \WP_Term ) {
			$this->terms[ $key ] = $term->term_id;
			return $this->terms[ $key ];
		}
		if ( ! $create ) {
			return null;
		}

		$result = wp_insert_term( $value, $taxonomy );
		if ( is_wp_error( $result ) ) {
			$existing = $result->get_error_data( 'term_exists' );
			if ( ! is_numeric( $existing ) ) {
				return null;
			}
			$this->terms[ $key ] = (int) $existing;
			return $this->terms[ $key ];
		}
		$this->terms[ $key ] = (int) $result['term_id'];
		return $this->terms[ $key ];
	}

	private function find_by_label( string $label ): ?string {
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			if ( 0 === strcasecmp( $attribute->attribute_label, $label ) || 0 === strcasecmp( $attribute->attribute_name, Slug::attribute( $label ) ) ) {
				return wc_attribute_taxonomy_name( $attribute->attribute_name );
			}
		}
		return null;
	}
}
