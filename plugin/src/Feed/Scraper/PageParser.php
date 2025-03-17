<?php
/**
 * Extracts product data from supplier HTML pages with CSS selectors from the profile.
 *
 * A selector may end with "@attr" to read an attribute instead of text: "img.main@src".
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Feed\Scraper;

final class PageParser {

	private const TEXT_FIELDS = [ 'name', 'sku', 'ean', 'price', 'old_price', 'quantity', 'available', 'vendor', 'category' ];

	/**
	 * @param array<string, string> $selectors Field => selector. Also: images, description,
	 *                                         attribute_rows, attribute_name, attribute_value,
	 *                                         product_links, next_page.
	 */
	public function __construct( private readonly array $selectors ) {}

	/**
	 * @param string $html Page markup.
	 * @param string $url  Page URL, used to resolve relative links.
	 * @return array<string, mixed> Flat record compatible with RowMapper.
	 */
	public function product( string $html, string $url ): array {
		$xpath  = $this->load( $html );
		$record = [ 'url' => $url ];

		foreach ( self::TEXT_FIELDS as $field ) {
			if ( ! empty( $this->selectors[ $field ] ) ) {
				$values = $this->select( $xpath, $this->selectors[ $field ] );
				// Breadcrumbs come as several nodes; other fields take the first match.
				$record[ $field ] = 'category' === $field ? implode( ' > ', $values ) : ( $values[0] ?? '' );
			}
		}

		if ( ! empty( $this->selectors['description'] ) ) {
			$record['description'] = $this->inner_html( $xpath, $this->selectors['description'] );
		}

		if ( ! empty( $this->selectors['images'] ) ) {
			$record['images'] = array_values(
				array_unique( array_map( fn ( string $src ): string => $this->absolute( $src, $url ), $this->select( $xpath, $this->selectors['images'] ) ) )
			);
		}

		if ( ! empty( $this->selectors['attribute_rows'] ) ) {
			$record['@attributes'] = $this->attributes( $xpath );
		}

		return $record;
	}

	/**
	 * Product links and the next listing page.
	 *
	 * @param string $html Listing markup.
	 * @param string $url  Listing URL.
	 * @return array{products: list<string>, next: string|null}
	 */
	public function listing( string $html, string $url ): array {
		$xpath    = $this->load( $html );
		$products = [];
		foreach ( $this->select( $xpath, $this->with_attr( (string) ( $this->selectors['product_links'] ?? 'a' ), 'href' ) ) as $href ) {
			$products[] = $this->absolute( $href, $url );
		}

		$next = null;
		if ( ! empty( $this->selectors['next_page'] ) ) {
			$found = $this->select( $xpath, $this->with_attr( $this->selectors['next_page'], 'href' ) );
			$next  = isset( $found[0] ) ? $this->absolute( $found[0], $url ) : null;
		}

		return [
			'products' => array_values( array_unique( $products ) ),
			'next'     => $next,
		];
	}

	/**
	 * @return list<string>
	 */
	public function select( \DOMXPath $xpath, string $selector ): array {
		[ $css, $attr ] = $this->split_attr( $selector );

		$nodes = $xpath->query( CssSelector::to_xpath( $css ) );
		if ( false === $nodes ) {
			return [];
		}

		$values = [];
		foreach ( $nodes as $node ) {
			$value = null !== $attr && $node instanceof \DOMElement ? $node->getAttribute( $attr ) : $node->textContent;
			$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
			if ( '' !== $value ) {
				$values[] = $value;
			}
		}
		return $values;
	}

	public function load( string $html ): \DOMXPath {
		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument();
		$document->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return new \DOMXPath( $document );
	}

	/**
	 * @return array<string, string>
	 */
	private function attributes( \DOMXPath $xpath ): array {
		$rows = $xpath->query( CssSelector::to_xpath( $this->selectors['attribute_rows'] ) );
		if ( false === $rows ) {
			return [];
		}

		$name_xpath  = CssSelector::to_xpath( $this->selectors['attribute_name'] ?? 'th' );
		$value_xpath = CssSelector::to_xpath( $this->selectors['attribute_value'] ?? 'td' );

		$attributes = [];
		foreach ( $rows as $row ) {
			$name  = $xpath->query( $name_xpath, $row );
			$value = $xpath->query( $value_xpath, $row );
			if ( false === $name || false === $value || 0 === $name->length || 0 === $value->length ) {
				continue;
			}
			$key = trim( (string) $name->item( 0 )?->textContent, " \t\n\r:" );
			$val = trim( (string) preg_replace( '/\s+/u', ' ', (string) $value->item( 0 )?->textContent ) );
			if ( '' !== $key && '' !== $val ) {
				$attributes[ $key ] = $val;
			}
		}
		return $attributes;
	}

	private function inner_html( \DOMXPath $xpath, string $selector ): string {
		$nodes = $xpath->query( CssSelector::to_xpath( $this->split_attr( $selector )[0] ) );
		$node  = false !== $nodes ? $nodes->item( 0 ) : null;
		if ( null === $node || null === $node->ownerDocument ) {
			return '';
		}

		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return trim( $html );
	}

	/**
	 * @return array{0: string, 1: string|null}
	 */
	private function split_attr( string $selector ): array {
		if ( preg_match( '/^(.*[^\s])@([\w:-]+)$/', trim( $selector ), $m ) && ! str_ends_with( $m[1], '[' ) ) {
			return [ $m[1], $m[2] ];
		}
		return [ $selector, null ];
	}

	private function with_attr( string $selector, string $attr ): string {
		return null === $this->split_attr( $selector )[1] ? $selector . '@' . $attr : $selector;
	}

	private function absolute( string $href, string $base ): string {
		if ( preg_match( '#^https?://#i', $href ) ) {
			return $href;
		}
		$parts = parse_url( $base ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $href;
		}
		$origin = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( str_starts_with( $href, '//' ) ) {
			return ( $parts['scheme'] ?? 'https' ) . ':' . $href;
		}
		if ( str_starts_with( $href, '/' ) ) {
			return $origin . $href;
		}
		$dir = rtrim( dirname( ( $parts['path'] ?? '/' ) . 'x' ), '/' );
		return $origin . $dir . '/' . $href;
	}
}
