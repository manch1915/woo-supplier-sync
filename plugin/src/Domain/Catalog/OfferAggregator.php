<?php
/**
 * Merges offers for the same unit from several suppliers.
 *
 * The cheapest supplier that has stock sets the price, stock is summed
 * over suppliers that have it.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Domain\Catalog;

use SupplierSync\Domain\Availability;
use SupplierSync\Domain\Offer;
use SupplierSync\Domain\Pricing\PriceCalculator;
use SupplierSync\Domain\Pricing\PriceResult;
use SupplierSync\Domain\Pricing\PricingException;

final class OfferAggregator {

	public function __construct(
		private readonly PriceCalculator $calculator,
		private readonly StockPolicy $policy,
	) {}

	/**
	 * @param non-empty-list<Offer> $offers       Offers that share a unit key.
	 * @param list<int>             $category_ids Store categories of the product, including ancestors.
	 */
	public function aggregate( array $offers, array $category_ids = [] ): AggregatedUnit {
		$candidates = [];
		$sources    = [];
		$errors     = [];

		foreach ( $offers as $offer ) {
			$source = [
				'supplier_id'  => $offer->supplier_id,
				'cost'         => $offer->price,
				'currency'     => $offer->currency,
				'quantity'     => $offer->quantity,
				'availability' => $offer->availability->value,
			];

			try {
				if ( null === $offer->price || $offer->price <= 0 ) {
					throw new PricingException( 'Missing purchase price.' );
				}
				$result          = $this->calculator->calculate( $offer->price, $offer->currency, $offer->supplier_id, $category_ids, $offer->old_price );
				$source['price'] = $result->final_price();
				$source['rule']  = $result->rule_id;
				$candidates[]    = [ $offer, $result ];
			} catch ( PricingException $e ) {
				$source['error'] = $e->getMessage();
				$errors[]        = $e->getMessage();
			}

			$sources[] = $source;
		}

		[ $quantity, $status ] = $this->stock( $offers );
		$content               = $this->content( $offers );

		$best = $this->pick_best( $candidates );
		if ( null === $best ) {
			return new AggregatedUnit(
				unit_key: $offers[0]->unit_key(),
				primary: $offers[0],
				price: null,
				quantity: $quantity,
				stock_status: $status,
				sources: $sources,
				error: implode( ' ', array_unique( $errors ) ),
				content: $content,
			);
		}

		return new AggregatedUnit(
			unit_key: $best[0]->unit_key(),
			primary: $best[0],
			price: $best[1],
			quantity: $quantity,
			stock_status: $status,
			sources: $sources,
			content: $content,
		);
	}

	/**
	 * The supplier that was connected first owns the card. Others only fill gaps:
	 * missing attributes, photos or description.
	 *
	 * @param non-empty-list<Offer> $offers Offers for one unit.
	 */
	private function content( array $offers ): Offer {
		usort( $offers, static fn ( Offer $a, Offer $b ): int => [ $a->supplier_id, $a->external_id ] <=> [ $b->supplier_id, $b->external_id ] );
		$owner = $offers[0];
		if ( 1 === count( $offers ) ) {
			return $owner;
		}

		$data = $owner->to_array();
		foreach ( array_slice( $offers, 1 ) as $other ) {
			$data['attributes'] += $other->attributes;
			if ( [] === $data['images'] ) {
				$data['images'] = $other->images;
			}
			if ( '' === trim( (string) $data['description'] ) ) {
				$data['description'] = $other->description;
			}
			$data['sku'] ??= $other->sku;
			$data['ean'] ??= $other->ean;
		}
		return Offer::from_array( $data );
	}

	/**
	 * @param non-empty-list<Offer> $offers Offers for one unit.
	 * @return array{0: int|null, 1: string}
	 */
	private function stock( array $offers ): array {
		$in_stock = array_filter( $offers, static fn ( Offer $o ): bool => Availability::IN_STOCK === $o->availability );

		if ( [] !== $in_stock ) {
			$quantity = 0;
			foreach ( $in_stock as $offer ) {
				if ( null === $offer->quantity ) {
					return [ null, AggregatedUnit::IN_STOCK ];
				}
				$quantity += max( 0, $offer->quantity );
			}
			return [ $quantity, AggregatedUnit::IN_STOCK ];
		}

		foreach ( $offers as $offer ) {
			if ( Availability::ON_ORDER === $offer->availability && $this->policy->allow_on_order ) {
				return [ 0, AggregatedUnit::ON_BACKORDER ];
			}
		}

		return [ 0, AggregatedUnit::OUT_OF_STOCK ];
	}

	/**
	 * In-stock offers first, then on-order, then the rest. Cheapest wins, more stock breaks ties.
	 *
	 * @param list<array{0: Offer, 1: PriceResult}> $candidates Priced offers.
	 * @return array{0: Offer, 1: PriceResult}|null
	 */
	private function pick_best( array $candidates ): ?array {
		if ( [] === $candidates ) {
			return null;
		}

		$rank = static fn ( Offer $o ): int => match ( $o->availability ) {
			Availability::IN_STOCK     => 0,
			Availability::ON_ORDER     => 1,
			Availability::OUT_OF_STOCK => 2,
		};

		usort(
			$candidates,
			static fn ( array $a, array $b ): int => [ $rank( $a[0] ), $a[1]->final_price(), -( $a[0]->quantity ?? 0 ), $a[0]->supplier_id ]
				<=> [ $rank( $b[0] ), $b[1]->final_price(), -( $b[0]->quantity ?? 0 ), $b[0]->supplier_id ]
		);

		return $candidates[0];
	}
}
