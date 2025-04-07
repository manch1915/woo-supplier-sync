<?php
/**
 * Short Latin slugs for attribute taxonomies. WooCommerce limits them to 28 characters,
 * and percent-encoded Cyrillic ("%d1%86%d0%b2...") blows through that immediately.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Infrastructure\Woo;

final class Slug {

	private const CYRILLIC = [
		'а' => 'a',
		'б' => 'b',
		'в' => 'v',
		'г' => 'g',
		'д' => 'd',
		'е' => 'e',
		'ё' => 'e',
		'ж' => 'zh',
		'з' => 'z',
		'и' => 'i',
		'й' => 'y',
		'к' => 'k',
		'л' => 'l',
		'м' => 'm',
		'н' => 'n',
		'о' => 'o',
		'п' => 'p',
		'р' => 'r',
		'с' => 's',
		'т' => 't',
		'у' => 'u',
		'ф' => 'f',
		'х' => 'kh',
		'ц' => 'ts',
		'ч' => 'ch',
		'ш' => 'sh',
		'щ' => 'shch',
		'ъ' => '',
		'ы' => 'y',
		'ь' => '',
		'э' => 'e',
		'ю' => 'yu',
		'я' => 'ya',
	];

	public static function attribute( string $label, int $max = 28 ): string {
		$latin = strtr( mb_strtolower( trim( $label ) ), self::CYRILLIC );
		$slug  = sanitize_title( remove_accents( $latin ) );
		$slug  = trim( (string) preg_replace( '/[^a-z0-9-]+/', '-', $slug ), '-' );

		if ( '' === $slug ) {
			return 'attr-' . substr( md5( $label ), 0, 8 );
		}
		if ( strlen( $slug ) > $max ) {
			$slug = rtrim( substr( $slug, 0, $max - 7 ), '-' ) . '-' . substr( md5( $label ), 0, 6 );
		}
		return $slug;
	}
}
