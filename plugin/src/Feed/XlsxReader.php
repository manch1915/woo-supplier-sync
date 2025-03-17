<?php
/**
 * Streaming XLSX reader for the first worksheet. Built on ZipArchive and XMLReader
 * so the plugin ships without third-party code that could clash with other plugins.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Feed;

final class XlsxReader implements FeedReader, TabularSource {

	private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

	public function __construct( private readonly RowMapper $mapper ) {}

	public function read( string $path, int $offset = 0 ): \Generator {
		foreach ( $this->rows( $path, $offset ) as $position => $row ) {
			yield $position => $this->mapper->map( $row, 'row ' . ( $position + 2 ) );
		}
	}

	public function rows( string $path, int $offset = 0 ): \Generator {
		if ( ! class_exists( \ZipArchive::class ) ) {
			throw new FeedException( 'The zip PHP extension is required to read XLSX files.' );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::RDONLY ) ) {
			throw new FeedException( 'This file is not a valid XLSX workbook.' );
		}
		$sheet   = $this->first_sheet_path( $zip );
		$strings = $this->shared_strings( $zip );
		$zip->close();

		$xml = new \XMLReader();
		if ( ! @$xml->open( 'zip://' . $path . '#' . $sheet, null, LIBXML_NONET | LIBXML_COMPACT ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new FeedException( 'The workbook has no readable worksheet.' );
		}

		$header   = null;
		$position = 0;
		while ( $xml->read() ) {
			if ( \XMLReader::ELEMENT !== $xml->nodeType || 'row' !== $xml->localName ) {
				continue;
			}

			$cells = $this->row_cells( $xml, $strings );
			if ( null === $header ) {
				$header = array_map( 'trim', $cells );
				continue;
			}
			if ( [] === array_filter( $cells, static fn ( string $c ): bool => '' !== trim( $c ) ) ) {
				continue;
			}

			if ( $position >= $offset ) {
				$row = [];
				foreach ( $header as $index => $name ) {
					if ( '' !== $name ) {
						$row[ $name ] = $cells[ $index ] ?? '';
					}
				}
				yield $position => $row;
			}
			++$position;
		}
		$xml->close();

		if ( null === $header ) {
			throw new FeedException( 'The first worksheet is empty.' );
		}
	}

	private function first_sheet_path( \ZipArchive $zip ): string {
		$workbook = $zip->getFromName( 'xl/workbook.xml' );
		$rels     = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
		if ( false === $workbook || false === $rels ) {
			return 'xl/worksheets/sheet1.xml';
		}

		$book  = simplexml_load_string( $workbook );
		$links = simplexml_load_string( $rels );
		if ( false === $book || false === $links ) {
			return 'xl/worksheets/sheet1.xml';
		}

		$sheet = $book->children( self::MAIN_NS )->sheets->sheet[0] ?? null;
		$rid   = null !== $sheet ? (string) $sheet->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' )['id'] : '';
		foreach ( $links->children() as $link ) {
			if ( (string) $link['Id'] === $rid ) {
				$target = ltrim( (string) $link['Target'], '/' );
				return str_starts_with( $target, 'xl/' ) ? $target : 'xl/' . $target;
			}
		}
		return 'xl/worksheets/sheet1.xml';
	}

	/**
	 * @return list<string>
	 */
	private function shared_strings( \ZipArchive $zip ): array {
		$data = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false === $data ) {
			return [];
		}

		$xml = new \XMLReader();
		$xml->XML( $data, null, LIBXML_NONET | LIBXML_COMPACT );

		$strings  = [];
		$has_node = $xml->read();
		while ( $has_node ) {
			if ( \XMLReader::ELEMENT === $xml->nodeType && 'si' === $xml->localName ) {
				// Rich text splits a string into several <t> runs; readString() joins them.
				$strings[] = $xml->readString();
				$has_node  = $xml->next();
				continue;
			}
			$has_node = $xml->read();
		}
		$xml->close();

		return $strings;
	}

	/**
	 * @param \XMLReader   $xml     Positioned on a <row>.
	 * @param list<string> $strings Shared strings table.
	 * @return array<int, string>
	 */
	private function row_cells( \XMLReader $xml, array $strings ): array {
		$node = $xml->expand();
		if ( ! $node instanceof \DOMElement ) {
			return [];
		}

		$cells = [];
		$next  = 0;
		foreach ( $node->childNodes as $cell ) {
			if ( ! $cell instanceof \DOMElement || 'c' !== $cell->localName ) {
				continue;
			}

			$index = $cell->hasAttribute( 'r' ) ? $this->column_index( $cell->getAttribute( 'r' ) ) : $next;
			$next  = $index + 1;
			$type  = $cell->getAttribute( 't' );
			$value = '';

			if ( 'inlineStr' === $type ) {
				$value = $cell->textContent;
			} else {
				foreach ( $cell->childNodes as $child ) {
					if ( $child instanceof \DOMElement && 'v' === $child->localName ) {
						$value = $child->textContent;
					}
				}
				if ( 's' === $type ) {
					$value = $strings[ (int) $value ] ?? '';
				} elseif ( 'b' === $type ) {
					$value = '1' === $value ? 'true' : 'false';
				}
			}

			$cells[ $index ] = $value;
		}

		if ( [] === $cells ) {
			return [];
		}
		return array_replace( array_fill( 0, max( array_keys( $cells ) ) + 1, '' ), $cells );
	}

	/**
	 * "B12" -> 1, "AA3" -> 26.
	 *
	 * @param string $reference Cell reference.
	 */
	private function column_index( string $reference ): int {
		$letters = (string) preg_replace( '/\d+/', '', strtoupper( $reference ) );
		$index   = 0;
		foreach ( str_split( $letters ) as $letter ) {
			$index = $index * 26 + ( ord( $letter ) - 64 );
		}
		return $index - 1;
	}
}
