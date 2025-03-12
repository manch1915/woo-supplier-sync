<?php
/**
 * Compares a product snapshot with the desired draft. Drives both dry runs and delta writes.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Domain\Catalog;

final class Differ {

	/**
	 * @param array<string, mixed>|null $current Snapshot of the existing product, null if there is none.
	 * @param ProductDraft              $desired Draft built from offers.
	 * @param list<string>              $ignore  Top-level fields the supplier is not allowed to overwrite.
	 */
	public function diff( ?array $current, ProductDraft $desired, array $ignore = [] ): ChangeSet {
		$to = $this->flatten( $desired->to_comparable() );

		if ( null === $current ) {
			$changes = [];
			foreach ( $to as $path => $value ) {
				if ( null !== $value && '' !== $value ) {
					$changes[ $path ] = [ null, $value ];
				}
			}
			return new ChangeSet( ChangeSet::CREATE, $changes );
		}

		$from = $this->flatten( $current );
		$to   = array_filter(
			$to,
			static fn ( string $path ): bool => ! in_array( explode( '.', $path, 2 )[0], $ignore, true ),
			ARRAY_FILTER_USE_KEY
		);

		$changes = [];
		foreach ( array_keys( $to + $this->only_tracked( $from, $to ) ) as $path ) {
			$old = $this->normalize( $from[ $path ] ?? null );
			$new = $this->normalize( $to[ $path ] ?? null );
			if ( $old !== $new ) {
				$changes[ $path ] = [ $from[ $path ] ?? null, $to[ $path ] ?? null ];
			}
		}

		if ( [] === $changes ) {
			return new ChangeSet( ChangeSet::UNCHANGED );
		}

		$unpublishing = 'draft' === $desired->status && 'publish' === ( $current['status'] ?? null );
		return new ChangeSet( $unpublishing ? ChangeSet::UNPUBLISH : ChangeSet::UPDATE, $changes );
	}

	/**
	 * Keys present only in the current product still count when their branch is tracked,
	 * e.g. a variation that disappeared from the feed.
	 *
	 * @param array<string, mixed> $from Flattened current.
	 * @param array<string, mixed> $to   Flattened desired.
	 * @return array<string, mixed>
	 */
	private function only_tracked( array $from, array $to ): array {
		$tracked = [];
		foreach ( array_keys( $to ) as $path ) {
			$tracked[ explode( '.', (string) $path, 2 )[0] ] = true;
		}
		return array_filter(
			$from,
			static fn ( string $path ): bool => isset( $tracked[ explode( '.', $path, 2 )[0] ] ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * @param array<string, mixed> $data   Nested array.
	 * @param string               $prefix Path so far.
	 * @return array<string, mixed>
	 */
	private function flatten( array $data, string $prefix = '' ): array {
		$flat = [];
		foreach ( $data as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			if ( is_array( $value ) && [] !== $value ) {
				$flat += $this->flatten( $value, $path );
			} else {
				$flat[ $path ] = is_array( $value ) ? null : $value;
			}
		}
		return $flat;
	}

	/**
	 * WooCommerce stores prices as strings and empty values as "".
	 *
	 * @param mixed $value Raw value.
	 */
	private function normalize( mixed $value ): ?string {
		if ( null === $value || '' === $value || false === $value ) {
			return null;
		}
		// Codes with leading zeros (EAN, SKU) stay strings.
		if ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && preg_match( '/^-?(0|[1-9]\d*)(\.\d+)?$/', $value ) ) ) {
			return number_format( (float) $value, 2, '.', '' );
		}
		return (string) $value;
	}
}
