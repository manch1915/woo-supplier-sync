<?php
/**
 * Arbitrary XML price lists: the profile names the item element and an XPath per field.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Feed;

final class XmlFeedReader implements FeedReader, TabularSource {

	/**
	 * @param RowMapper             $mapper          Identity mapping: field => field.
	 * @param string                $item_tag        Element that holds one product, e.g. "product".
	 * @param array<string, string> $fields          Offer field => XPath relative to the item.
	 * @param string                $attributes_path XPath to attribute elements, e.g. "specs/spec".
	 * @param string                $attribute_name  XPath to the name inside an attribute element, e.g. "@name".
	 */
	public function __construct(
		private readonly RowMapper $mapper,
		private readonly string $item_tag,
		private readonly array $fields,
		private readonly string $attributes_path = '',
		private readonly string $attribute_name = '@name',
	) {}

	public function read( string $path, int $offset = 0 ): \Generator {
		foreach ( $this->rows( $path, $offset ) as $position => $row ) {
			yield $position => $this->mapper->map( $row, $this->item_tag . ' #' . ( $position + 1 ) );
		}
	}

	public function rows( string $path, int $offset = 0 ): \Generator {
		$xml = new \XMLReader();
		if ( '' === $this->item_tag || ! @$xml->open( $path, null, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new FeedException( 'Cannot open the XML file or the item element is not set.' );
		}

		$position = 0;
		$has_node = $xml->read();
		while ( $has_node ) {
			if ( \XMLReader::ELEMENT === $xml->nodeType && $this->item_tag === $xml->localName ) {
				if ( $position >= $offset ) {
					$node = $xml->expand();
					$item = false !== $node ? simplexml_import_dom( ( new \DOMDocument() )->importNode( $node, true ) ) : null;
					yield $position => null !== $item ? $this->to_row( $item ) : [];
				}
				++$position;
				$has_node = $xml->next();
				continue;
			}
			$has_node = $xml->read();
		}
		$xml->close();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function to_row( \SimpleXMLElement $item ): array {
		$row = [];
		foreach ( $this->fields as $field => $xpath ) {
			$values        = array_map( static fn ( \SimpleXMLElement $n ): string => trim( (string) $n ), (array) $item->xpath( $xpath ) );
			$row[ $field ] = 'images' === $field ? $values : ( $values[0] ?? '' );
		}

		if ( '' !== $this->attributes_path ) {
			$attributes = [];
			foreach ( (array) $item->xpath( $this->attributes_path ) as $element ) {
				$name  = trim( (string) ( $element->xpath( $this->attribute_name )[0] ?? '' ) );
				$value = trim( (string) $element );
				if ( '' !== $name && '' !== $value ) {
					$attributes[ $name ] = $value;
				}
			}
			$row['@attributes'] = $attributes;
		}

		return $row;
	}
}
