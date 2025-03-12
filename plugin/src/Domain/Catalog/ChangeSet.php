<?php
/**
 * Result of comparing the current product with the desired one.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Domain\Catalog;

final class ChangeSet {

	public const CREATE    = 'created';
	public const UPDATE    = 'updated';
	public const UNCHANGED = 'unchanged';
	public const UNPUBLISH = 'unpublished';

	/**
	 * @param string                               $action  One of the constants.
	 * @param array<string, array{0: mixed, 1: mixed}> $changes Dot path => [from, to].
	 */
	public function __construct(
		public readonly string $action,
		public readonly array $changes = [],
	) {}

	public function is_noop(): bool {
		return self::UNCHANGED === $this->action;
	}

	/**
	 * Top-level fields that changed, e.g. "regular_price" or "variations".
	 *
	 * @return list<string>
	 */
	public function changed_fields(): array {
		$fields = [];
		foreach ( array_keys( $this->changes ) as $path ) {
			$fields[ explode( '.', (string) $path, 2 )[0] ] = true;
		}
		return array_keys( $fields );
	}

	public function touches( string $field ): bool {
		return in_array( $field, $this->changed_fields(), true );
	}
}
