<?php
/**
 * Picks and configures a reader for a supplier profile.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Feed;

final class ReaderFactory {

	public const TYPES = [ 'yml', 'xml', 'csv', 'xlsx', 'json', 'site' ];

	/**
	 * @param string               $type        Feed type.
	 * @param int                  $supplier_id Supplier profile ID.
	 * @param array<string, mixed> $config      Supplier config.
	 *
	 * @throws FeedException For unknown types.
	 */
	public function create( string $type, int $supplier_id, array $config ): FeedReader {
		$mapping  = (array) ( $config['mapping'] ?? [] );
		$currency = (string) ( $config['currency'] ?? '' );
		$mapper   = new RowMapper( $supplier_id, $mapping + [ 'currency' => $currency ] );

		return match ( $type ) {
			'yml'  => new YmlReader( $supplier_id, '' !== $currency ? $currency : 'RUB' ),
			'csv'  => new CsvReader( $mapper, (string) ( $mapping['delimiter'] ?? 'auto' ), (string) ( $mapping['encoding'] ?? 'UTF-8' ) ),
			'xlsx' => new XlsxReader( $mapper ),
			'json' => new JsonReader( $mapper, (string) ( $mapping['items_path'] ?? '' ) ),
			'xml'  => new XmlFeedReader(
				new RowMapper( $supplier_id, [ 'columns' => array_combine( RowMapper::FIELDS, RowMapper::FIELDS ) ] + $mapping + [ 'currency' => $currency ] ),
				(string) ( $mapping['item_tag'] ?? '' ),
				array_map( 'strval', (array) ( $mapping['fields'] ?? [] ) ),
				(string) ( $mapping['attributes_path'] ?? '' ),
				(string) ( $mapping['attribute_name'] ?? '@name' ),
			),
			// The crawler stores page records as JSON Lines keyed by offer field names.
			'site' => new JsonReader(
				new RowMapper(
					$supplier_id,
					[
						'columns'          => array_combine( RowMapper::FIELDS, RowMapper::FIELDS ),
						'attribute_prefix' => '@attributes',
						'currency'         => $currency,
					]
				)
			),
			default => throw new FeedException( sprintf( 'Unknown feed type "%s".', $type ) ),
		};
	}
}
