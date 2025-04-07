<?php
/**
 * Group key <-> WooCommerce product ID.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Infrastructure\Persistence;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table.

use SupplierSync\Infrastructure\Schema;

final class ProductMapRepository {

	public function __construct( private readonly \wpdb $db ) {}

	public function product_id( string $group_key ): ?int {
		$id = $this->db->get_var( $this->db->prepare( 'SELECT product_id FROM %i WHERE group_key = %s', Schema::table( 'products' ), $group_key ) );
		return null !== $id ? (int) $id : null;
	}

	public function save( string $group_key, int $product_id ): void {
		$this->db->replace(
			Schema::table( 'products' ),
			[
				'group_key'  => $group_key,
				'product_id' => $product_id,
			]
		);
	}

	public function forget_product( int $product_id ): void {
		$this->db->delete( Schema::table( 'products' ), [ 'product_id' => $product_id ] );
	}

	public function count(): int {
		return (int) $this->db->get_var( $this->db->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'products' ) ) );
	}
}
