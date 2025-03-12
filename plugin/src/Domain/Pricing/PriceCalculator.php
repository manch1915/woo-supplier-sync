<?php
/**
 * Turns a supplier purchase price into a retail price.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Domain\Pricing;

final class PriceCalculator {

	/**
	 * @var list<PriceRule>
	 */
	private array $rules;

	/**
	 * @param list<PriceRule> $rules        Rules from settings.
	 * @param PriceRule       $default_rule Used when nothing else matches.
	 * @param ExchangeRates   $rates        Supplier currency to store currency.
	 */
	public function __construct(
		array $rules,
		private readonly PriceRule $default_rule,
		private readonly ExchangeRates $rates,
	) {
		usort(
			$rules,
			static fn ( PriceRule $a, PriceRule $b ): int => [ $b->specificity(), $a->priority, $a->id ] <=> [ $a->specificity(), $b->priority, $b->id ]
		);
		$this->rules = $rules;
	}

	/**
	 * @param float      $cost         Purchase price in supplier currency.
	 * @param string     $currency     Supplier currency.
	 * @param int        $supplier_id  Supplier ID.
	 * @param list<int>  $category_ids Store categories including ancestors.
	 * @param float|null $old_price    Supplier RRP in supplier currency.
	 *
	 * @throws PricingException When there is no exchange rate for the currency.
	 */
	public function calculate( float $cost, string $currency, int $supplier_id, array $category_ids = [], ?float $old_price = null ): PriceResult {
		$store_cost = $this->rates->convert( $cost, $currency );
		$rule       = $this->find_rule( $supplier_id, $category_ids, $store_cost );

		$price     = $store_cost * ( 1 + $rule->markup_percent / 100 ) + $rule->markup_fixed;
		$min_price = $store_cost * ( 1 + $rule->min_margin_percent / 100 );
		$floored   = $price < $min_price;
		$price     = $rule->rounding->apply( max( $price, $min_price ) );

		$regular = $price;
		$sale    = null;
		if ( null !== $old_price && $old_price > 0 ) {
			$rrp = $rule->rounding->apply( $this->rates->convert( $old_price, $currency ) );
			if ( $rrp > $price ) {
				$regular = $rrp;
				$sale    = $price;
			}
		}

		return new PriceResult(
			cost: round( $store_cost, 2 ),
			regular_price: $regular,
			sale_price: $sale,
			rule_id: $rule->id,
			margin_floor_applied: $floored,
		);
	}

	/**
	 * @param int       $supplier_id  Supplier ID.
	 * @param list<int> $category_ids Store categories including ancestors.
	 * @param float     $store_cost   Cost in store currency.
	 */
	public function find_rule( int $supplier_id, array $category_ids, float $store_cost ): PriceRule {
		foreach ( $this->rules as $rule ) {
			if ( $rule->matches( $supplier_id, $category_ids, $store_cost ) ) {
				return $rule;
			}
		}
		return $this->default_rule;
	}
}
