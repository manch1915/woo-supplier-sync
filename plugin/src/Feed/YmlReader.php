<?php
/**
 * Yandex Market Language (YML) reader. Streams with XMLReader, so 50k offers fit in a few MB.
 *
 * Real-world YML is often off-spec: stock may live in <count>, <quantity> or <stock>,
 * barcodes may repeat, names may be split into typePrefix/vendor/model. All of that is accepted.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Feed;

use SupplierSync\Domain\Availability;
use SupplierSync\Domain\Offer;

final class YmlReader implements FeedReader {

	private const STOCK_TAGS = [ 'count', 'quantity', 'stock', 'stock_quantity', 'amount' ];

	/**
	 * @var array<string, array{name: string, parent: string}>
	 */
	private array $categories = [];

	/**
	 * @param int    $supplier_id      Supplier profile.
	 * @param string $default_currency Used when an offer has no currencyId.
	 */
	public function __construct(
		private readonly int $supplier_id,
		private readonly string $default_currency = 'RUB',
	) {}

	public function read( string $path, int $offset = 0 ): \Generator {
		$xml = new \XMLReader();
		if ( ! @$xml->open( $path, null, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new FeedException( 'Cannot open the YML file.' );
		}

		$this->categories = [];
		$position         = 0;
		$seen_catalog     = false;

		$has_node = $this->safe_read( $xml );
		while ( $has_node ) {
			if ( \XMLReader::ELEMENT === $xml->nodeType && 'offer' === $xml->localName ) {
				if ( $position >= $offset ) {
					yield $position => $this->expand_offer( $xml, $position );
				}
				++$position;
				// Jump over the offer subtree instead of walking into it.
				$has_node = $this->safe_next( $xml );
				continue;
			}

			if ( \XMLReader::ELEMENT === $xml->nodeType ) {
				if ( 'yml_catalog' === $xml->localName ) {
					$seen_catalog = true;
				} elseif ( 'category' === $xml->localName ) {
					$this->categories[ (string) $xml->getAttribute( 'id' ) ] = [
						'parent' => (string) $xml->getAttribute( 'parentId' ),
						'name'   => trim( $xml->readString() ),
					];
				}
			}

			$has_node = $this->safe_read( $xml );
		}

		$xml->close();

		if ( ! $seen_catalog ) {
			throw new FeedException( 'This file is not a YML catalog: <yml_catalog> is missing.' );
		}
	}

	private function expand_offer( \XMLReader $xml, int $position ): Offer|RowError {
		$node = $xml->expand();
		if ( false === $node ) {
			return new RowError( 'offer #' . $position, 'Broken offer markup.' );
		}
		$offer = simplexml_import_dom( ( new \DOMDocument() )->importNode( $node, true ) );
		return null !== $offer ? $this->to_offer( $offer ) : new RowError( 'offer #' . $position, 'Broken offer markup.' );
	}

	private function to_offer( \SimpleXMLElement $node ): Offer|RowError {
		$id  = trim( (string) $node['id'] );
		$ref = '' !== $id ? 'offer ' . $id : 'offer without id';

		$name = trim( (string) $node->name );
		if ( '' === $name ) {
			$name = trim( implode( ' ', array_filter( [ (string) $node->typePrefix, (string) $node->vendor, (string) $node->model ] ) ) );
		}
		if ( '' === $name ) {
			return new RowError( $ref, 'Offer has no name.' );
		}

		$price = Number::parse( (string) $node->price );
		if ( null === $price ) {
			return new RowError( $ref, 'Offer has no price.' );
		}

		$quantity = null;
		foreach ( self::STOCK_TAGS as $tag ) {
			if ( isset( $node->{$tag} ) ) {
				$quantity = Number::quantity( (string) $node->{$tag} );
				break;
			}
		}
		if ( null === $quantity && isset( $node->outlets->outlet ) ) {
			$quantity = 0;
			foreach ( $node->outlets->outlet as $outlet ) {
				$quantity += (int) $outlet['instock'];
			}
		}

		$available = Number::flag( (string) $node['available'] );

		$attributes = [];
		foreach ( $node->param as $param ) {
			$param_name = trim( (string) $param['name'] );
			$value      = trim( (string) $param );
			if ( '' === $param_name || '' === $value ) {
				continue;
			}
			$unit                      = trim( (string) $param['unit'] );
			$attributes[ $param_name ] = '' !== $unit ? $value . ' ' . $unit : $value;
		}

		$images = [];
		foreach ( $node->picture as $picture ) {
			$url = trim( (string) $picture );
			if ( '' !== $url ) {
				$images[] = $url;
			}
		}

		$category_id = trim( (string) $node->categoryId );
		$group_id    = trim( (string) ( $node['group_id'] ?? $node->group_id ) );
		$old_price   = Number::parse( (string) $node->oldprice );
		$currency    = trim( (string) $node->currencyId );

		return new Offer(
			supplier_id: $this->supplier_id,
			external_id: $id,
			name: $name,
			sku: (string) $node->vendorCode,
			ean: (string) $node->barcode,
			price: $price,
			currency: '' !== $currency ? $currency : $this->default_currency,
			old_price: $old_price,
			quantity: $quantity,
			availability: Availability::from_stock( $quantity, $available ),
			category_id: $category_id,
			category_path: $this->category_path( $category_id ),
			images: array_values( array_unique( $images ) ),
			attributes: $attributes,
			group_id: '' !== $group_id ? $group_id : null,
			description: trim( (string) $node->description ),
			vendor: trim( (string) $node->vendor ),
			url: trim( (string) $node->url ),
		);
	}

	private function category_path( string $id ): string {
		$names = [];
		$guard = 0;
		while ( '' !== $id && isset( $this->categories[ $id ] ) && $guard++ < 20 ) {
			array_unshift( $names, $this->categories[ $id ]['name'] );
			$id = $this->categories[ $id ]['parent'];
		}
		return implode( ' > ', $names );
	}

	/**
	 * XMLReader emits warnings on broken markup; turn them into a clean exception.
	 *
	 * @param \XMLReader $xml Reader.
	 */
	private function safe_read( \XMLReader $xml ): bool {
		return $this->guarded( static fn (): bool => $xml->read() );
	}

	private function safe_next( \XMLReader $xml ): bool {
		return $this->guarded( static fn (): bool => $xml->next() );
	}

	/**
	 * @param callable(): bool $step Cursor move.
	 */
	private function guarded( callable $step ): bool {
		$previous = libxml_use_internal_errors( true );
		$result   = $step();
		$error    = libxml_get_last_error();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $result && false !== $error && LIBXML_ERR_WARNING < $error->level ) {
			throw new FeedException( sprintf( 'Malformed XML at line %d: %s', (int) $error->line, trim( $error->message ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return $result;
	}
}
