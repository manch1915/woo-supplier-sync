<?php
/**
 * Streams offers out of a downloaded feed file.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Feed;

use SupplierSync\Domain\Offer;

interface FeedReader {

	/**
	 * Yields offers or row errors keyed by their position in the feed. Positions before
	 * $offset are skipped cheaply, so a long feed can be parsed over several requests.
	 *
	 * @param string $path   Local file.
	 * @param int    $offset Position to resume from.
	 * @return \Generator<int, Offer|RowError>
	 *
	 * @throws FeedException When the file cannot be read at all.
	 */
	public function read( string $path, int $offset = 0 ): \Generator;
}
