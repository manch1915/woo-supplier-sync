<?php
/**
 * Retail price rounding modes.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Domain\Pricing;

enum Rounding: string {
	case NONE          = 'none';
	case WHOLE         = 'whole';
	case TENS          = 'tens';
	case ENDING_90     = 'ending_90';
	case ENDING_99     = 'ending_99';
	case ENDING_90_CTS = 'ending_90_cents';

	/**
	 * Always rounds up, so rounding never eats into the margin.
	 *
	 * @param float $price Price before rounding.
	 */
	public function apply( float $price ): float {
		// Kill float noise like 1289.9999999 before ceiling.
		$price = round( $price, 6 );

		// Ending in 90 turns 1234 into 1290 and 1291 into 1390; ending in .99 turns 13.00 into 13.99.
		$rounded = match ( $this ) { // phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts -- enum method.
			self::NONE          => $price,
			self::WHOLE         => ceil( $price ),
			self::TENS          => ceil( round( $price / 10, 6 ) ) * 10,
			self::ENDING_90     => ceil( round( ( $price - 90 ) / 100, 6 ) ) * 100 + 90,
			self::ENDING_99     => ceil( round( $price - 0.99, 6 ) ) + 0.99,
			self::ENDING_90_CTS => ceil( round( $price - 0.9, 6 ) ) + 0.9,
		};

		return round( $rounded, 2 );
	}
}
