<?php
/**
 * What the store does when suppliers run out.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Domain\Catalog;

final class StockPolicy {

	public const ZERO_OUT_OF_STOCK = 'outofstock';
	public const ZERO_HIDE         = 'hide';
	public const ZERO_DRAFT        = 'draft';

	/**
	 * @param string $when_zero      outofstock | hide | draft.
	 * @param bool   $allow_on_order Show "available on order" when a supplier can deliver later.
	 */
	public function __construct(
		public readonly string $when_zero = self::ZERO_OUT_OF_STOCK,
		public readonly bool $allow_on_order = true,
	) {}

	/**
	 * @param array<string, mixed> $data Settings array.
	 */
	public static function from_array( array $data ): self {
		$when_zero = (string) ( $data['when_zero'] ?? self::ZERO_OUT_OF_STOCK );
		if ( ! in_array( $when_zero, [ self::ZERO_OUT_OF_STOCK, self::ZERO_HIDE, self::ZERO_DRAFT ], true ) ) {
			$when_zero = self::ZERO_OUT_OF_STOCK;
		}
		return new self( $when_zero, (bool) ( $data['allow_on_order'] ?? true ) );
	}
}
