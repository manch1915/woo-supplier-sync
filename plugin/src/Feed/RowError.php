<?php
/**
 * A feed row that could not be turned into an offer.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Feed;

final class RowError {

	public function __construct(
		public readonly string $reference,
		public readonly string $message,
	) {}
}
