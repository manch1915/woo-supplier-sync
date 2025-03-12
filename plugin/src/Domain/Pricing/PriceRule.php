<?php
/**
 * Markup rule. Empty conditions match everything.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Domain\Pricing;

final class PriceRule {

	/**
	 * @param string     $id                 Stable ID for the UI and logs.
	 * @param string     $label              Human-readable name.
	 * @param int|null   $supplier_id        Only offers from this supplier.
	 * @param int|null   $category_id        Only products in this store category or its children.
	 * @param float|null $min_cost           Lower bound of purchase price in store currency, inclusive.
	 * @param float|null $max_cost           Upper bound, exclusive.
	 * @param float      $markup_percent     Percentage added to the cost.
	 * @param float      $markup_fixed       Fixed amount added after the percentage.
	 * @param float      $min_margin_percent Price never goes below cost plus this margin.
	 * @param Rounding   $rounding           Rounding mode for the final price.
	 * @param int        $priority           Lower wins among equally specific rules.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly ?int $supplier_id = null,
		public readonly ?int $category_id = null,
		public readonly ?float $min_cost = null,
		public readonly ?float $max_cost = null,
		public readonly float $markup_percent = 0.0,
		public readonly float $markup_fixed = 0.0,
		public readonly float $min_margin_percent = 0.0,
		public readonly Rounding $rounding = Rounding::NONE,
		public readonly int $priority = 10,
	) {}

	/**
	 * @param int       $supplier_id  Supplier of the offer.
	 * @param list<int> $category_ids Store categories of the product including ancestors.
	 * @param float     $cost         Purchase price in store currency.
	 */
	public function matches( int $supplier_id, array $category_ids, float $cost ): bool {
		if ( null !== $this->supplier_id && $this->supplier_id !== $supplier_id ) {
			return false;
		}
		if ( null !== $this->category_id && ! in_array( $this->category_id, $category_ids, true ) ) {
			return false;
		}
		if ( null !== $this->min_cost && $cost < $this->min_cost ) {
			return false;
		}
		if ( null !== $this->max_cost && $cost >= $this->max_cost ) {
			return false;
		}
		return true;
	}

	/**
	 * Supplier beats category, category beats price range.
	 */
	public function specificity(): int {
		return ( null !== $this->supplier_id ? 4 : 0 )
			+ ( null !== $this->category_id ? 2 : 0 )
			+ ( null !== $this->min_cost || null !== $this->max_cost ? 1 : 0 );
	}

	/**
	 * @param array<string, mixed> $data Raw rule from settings or REST.
	 */
	public static function from_array( array $data ): self {
		$nullable_int   = static fn ( $value ): ?int => ( null === $value || '' === $value ) ? null : (int) $value;
		$nullable_float = static fn ( $value ): ?float => ( null === $value || '' === $value ) ? null : (float) $value;

		return new self(
			id: (string) ( $data['id'] ?? '' ),
			label: (string) ( $data['label'] ?? '' ),
			supplier_id: $nullable_int( $data['supplier_id'] ?? null ),
			category_id: $nullable_int( $data['category_id'] ?? null ),
			min_cost: $nullable_float( $data['min_cost'] ?? null ),
			max_cost: $nullable_float( $data['max_cost'] ?? null ),
			markup_percent: (float) ( $data['markup_percent'] ?? 0 ),
			markup_fixed: (float) ( $data['markup_fixed'] ?? 0 ),
			min_margin_percent: (float) ( $data['min_margin_percent'] ?? 0 ),
			rounding: Rounding::tryFrom( (string) ( $data['rounding'] ?? '' ) ) ?? Rounding::NONE,
			priority: (int) ( $data['priority'] ?? 10 ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'id'                 => $this->id,
			'label'              => $this->label,
			'supplier_id'        => $this->supplier_id,
			'category_id'        => $this->category_id,
			'min_cost'           => $this->min_cost,
			'max_cost'           => $this->max_cost,
			'markup_percent'     => $this->markup_percent,
			'markup_fixed'       => $this->markup_fixed,
			'min_margin_percent' => $this->min_margin_percent,
			'rounding'           => $this->rounding->value,
			'priority'           => $this->priority,
		];
	}
}
