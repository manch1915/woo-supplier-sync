<?php
/**
 * Converts the CSS selector subset that scraper profiles need into XPath.
 *
 * Supported: tag, *, #id, .class, [attr], [attr=v], [attr*=v], [attr^=v], [attr$=v],
 * :first-child, :last-child, :nth-child(n), descendant, ">" and "+" combinators, lists with ",".
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Feed\Scraper;

final class CssSelector {

	/**
	 * @param string $selector CSS selector.
	 *
	 * @throws \InvalidArgumentException On syntax the converter does not support.
	 */
	public static function to_xpath( string $selector ): string {
		$parts = [];
		foreach ( self::split_list( $selector ) as $single ) {
			$parts[] = self::convert( trim( $single ) );
		}
		return implode( ' | ', $parts );
	}

	private static function convert( string $selector ): string {
		if ( '' === $selector ) {
			throw new \InvalidArgumentException( 'Empty selector.' );
		}

		$xpath      = './/';
		$length     = strlen( $selector );
		$i          = 0;
		$expect_new = true;

		while ( $i < $length ) {
			$char = $selector[ $i ];

			if ( ctype_space( $char ) || '>' === $char || '+' === $char ) {
				$combinator = ' ';
				while ( $i < $length && ( ctype_space( $selector[ $i ] ) || '>' === $selector[ $i ] || '+' === $selector[ $i ] ) ) {
					if ( '>' === $selector[ $i ] || '+' === $selector[ $i ] ) {
						$combinator = $selector[ $i ];
					}
					++$i;
				}
				$xpath .= match ( $combinator ) {
					'>'     => '/',
					'+'     => '/following-sibling::*[1]/self::',
					default => '//',
				};
				$expect_new = true;
				continue;
			}

			if ( $expect_new ) {
				if ( preg_match( '/\G(\*|[a-zA-Z][a-zA-Z0-9-]*)/', $selector, $m, 0, $i ) ) {
					$xpath .= strtolower( $m[1] );
					$i     += strlen( $m[1] );
				} else {
					$xpath .= '*';
				}
				$expect_new = false;
				continue;
			}

			if ( '#' === $char && preg_match( '/\G#([\w-]+)/', $selector, $m, 0, $i ) ) {
				$xpath .= '[@id=' . self::literal( $m[1] ) . ']';
				$i     += strlen( $m[0] );
			} elseif ( '.' === $char && preg_match( '/\G\.([\w-]+)/', $selector, $m, 0, $i ) ) {
				$xpath .= "[contains(concat(' ', normalize-space(@class), ' '), " . self::literal( ' ' . $m[1] . ' ' ) . ')]';
				$i     += strlen( $m[0] );
			} elseif ( '[' === $char && preg_match( '/\G\[\s*([\w:-]+)\s*(?:([*^$]?=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]\s]*)))?\s*\]/', $selector, $m, 0, $i ) ) {
				$xpath .= self::attribute( $m[1], $m[2] ?? '', ( $m[3] ?? '' ) . ( $m[4] ?? '' ) . ( $m[5] ?? '' ) );
				$i     += strlen( $m[0] );
			} elseif ( ':' === $char && preg_match( '/\G:(first-child|last-child|nth-child\(\s*(\d+)\s*\))/', $selector, $m, 0, $i ) ) {
				$xpath .= match ( true ) {
					'first-child' === $m[1] => '[not(preceding-sibling::*)]',
					'last-child' === $m[1]  => '[not(following-sibling::*)]',
					default                 => '[count(preceding-sibling::*) = ' . ( (int) $m[2] - 1 ) . ']',
				};
				$i += strlen( $m[0] );
			} else {
				throw new \InvalidArgumentException( sprintf( 'Unsupported selector syntax near "%s".', substr( $selector, $i ) ) );
			}
		}

		return $xpath;
	}

	private static function attribute( string $name, string $operator, string $value ): string {
		$attr    = '@' . $name;
		$literal = self::literal( $value );

		return match ( $operator ) {
			''      => '[' . $attr . ']',
			'='     => '[' . $attr . '=' . $literal . ']',
			'*='    => '[contains(' . $attr . ', ' . $literal . ')]',
			'^='    => '[starts-with(' . $attr . ', ' . $literal . ')]',
			default => '[substring(' . $attr . ', string-length(' . $attr . ') - ' . ( strlen( $value ) - 1 ) . ') = ' . $literal . ']',
		};
	}

	private static function literal( string $value ): string {
		if ( ! str_contains( $value, "'" ) ) {
			return "'" . $value . "'";
		}
		if ( ! str_contains( $value, '"' ) ) {
			return '"' . $value . '"';
		}
		return "concat('" . str_replace( "'", "', \"'\", '", $value ) . "')";
	}

	/**
	 * Splits on commas that are not inside brackets or quotes.
	 *
	 * @param string $selector Selector list.
	 * @return list<string>
	 */
	private static function split_list( string $selector ): array {
		$parts = [];
		$depth = 0;
		$quote = '';
		$start = 0;
		$len   = strlen( $selector );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $selector[ $i ];
			if ( '' !== $quote ) {
				$quote = $char === $quote ? '' : $quote;
			} elseif ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( '[' === $char || '(' === $char ) {
				++$depth;
			} elseif ( ']' === $char || ')' === $char ) {
				--$depth;
			} elseif ( ',' === $char && 0 === $depth ) {
				$parts[] = substr( $selector, $start, $i - $start );
				$start   = $i + 1;
			}
		}
		$parts[] = substr( $selector, $start );

		return $parts;
	}
}
