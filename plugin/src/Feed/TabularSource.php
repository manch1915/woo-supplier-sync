<?php
/**
 * A feed with raw rows, so the admin can preview columns before mapping them.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Feed;

interface TabularSource {

	/**
	 * @param string $path   Local file.
	 * @param int    $offset First row to yield.
	 * @return \Generator<int, array<string, mixed>>
	 *
	 * @throws FeedException When the file cannot be read.
	 */
	public function rows( string $path, int $offset = 0 ): \Generator;
}
