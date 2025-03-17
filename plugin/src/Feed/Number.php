<?php
/**
 * Parses prices and quantities written the way suppliers write them.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Feed;

final class Number {

	/**
	 * Handles "1 234,50", "1,234.50", "1.234,50 €", "$12" and empty strings.
	 *
	 * @param string|null $raw     Raw cell value.
	 * @param string      $decimal Decimal separator hint: ".", "," or "auto".
	 */
	public static function parse( ?string $raw, string $decimal = 'auto' ): ?float {
		if ( null === $raw ) {
			return null;
		}

		$value = (string) preg_replace( '/[^\d,.\-]/u', '', str_replace( "\u{00A0}", '', $raw ) );
		if ( '' === $value || '-' === $value ) {
			return null;
		}

		$has_comma = str_contains( $value, ',' );
		$has_dot   = str_contains( $value, '.' );

		if ( $has_comma && $has_dot ) {
			// The separator that comes last is the decimal one.
			$decimal = strrpos( $value, ',' ) > strrpos( $value, '.' ) ? ',' : '.';
		} elseif ( 'auto' === $decimal ) {
			$decimal = ( $has_comma && preg_match( '/,\d{1,2}$/', $value ) ) ? ',' : '.';
		}

		$thousands = ',' === $decimal ? '.' : ',';
		$value     = str_replace( $thousands, '', $value );
		$value     = str_replace( ',', '.', $value );

		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Quantities like "12", "12 шт", ">10" or "много".
	 *
	 * @param string|null $raw Raw value.
	 */
	public static function quantity( ?string $raw ): ?int {
		if ( null === $raw || '' === trim( $raw ) ) {
			return null;
		}
		if ( preg_match( '/-?\d+/', $raw, $m ) ) {
			return max( 0, (int) $m[0] );
		}
		return null;
	}

	/**
	 * Truthy flags: "true", "1", "yes", "да", "+", "in stock".
	 *
	 * @param string|null $raw     Raw value.
	 * @param bool        $fallback Used for empty values.
	 */
	public static function flag( ?string $raw, bool $fallback = true ): bool {
		if ( null === $raw || '' === trim( $raw ) ) {
			return $fallback;
		}
		$value = mb_strtolower( trim( $raw ) );
		if ( in_array( $value, [ 'false', '0', 'no', 'нет', '-', 'out of stock', 'нет в наличии' ], true ) ) {
			return false;
		}
		return true;
	}
}
