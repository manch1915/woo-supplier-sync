import { __, _n, sprintf } from '@wordpress/i18n';
import type { FeedType, ItemAction, Run, RunPhase } from './types';

export const LOCALE: string =
	( window as unknown as { supplierSyncLocale?: string } )
		.supplierSyncLocale ??
	document.documentElement.lang ??
	'en-US';

export const TYPE_LABELS: Record< FeedType, string > = {
	yml: __( 'YML feed', 'woo-supplier-sync' ),
	xml: __( 'XML feed', 'woo-supplier-sync' ),
	csv: __( 'CSV price list', 'woo-supplier-sync' ),
	xlsx: __( 'Excel price list', 'woo-supplier-sync' ),
	json: __( 'JSON API', 'woo-supplier-sync' ),
	site: __( 'Website', 'woo-supplier-sync' ),
};

export const SCHEDULE_LABELS: Record< string, string > = {
	manual: __( 'Manual only', 'woo-supplier-sync' ),
	hourly: __( 'Every hour', 'woo-supplier-sync' ),
	every_6_hours: __( 'Every 6 hours', 'woo-supplier-sync' ),
	twice_daily: __( 'Twice a day', 'woo-supplier-sync' ),
	daily: __( 'Daily at 04:00', 'woo-supplier-sync' ),
};

export const ROUNDING_LABELS: Record< string, string > = {
	none: __( 'Keep cents', 'woo-supplier-sync' ),
	whole: __( 'Up to a whole number', 'woo-supplier-sync' ),
	tens: __( 'Up to tens', 'woo-supplier-sync' ),
	ending_90: __( 'Ending in 90 (1 290)', 'woo-supplier-sync' ),
	ending_99: __( 'Ending in .99', 'woo-supplier-sync' ),
	ending_90_cents: __( 'Ending in .90', 'woo-supplier-sync' ),
};

export const ACTION_LABELS: Record< ItemAction, string > = {
	created: __( 'New', 'woo-supplier-sync' ),
	updated: __( 'Updated', 'woo-supplier-sync' ),
	unpublished: __( 'Off sale', 'woo-supplier-sync' ),
	error: __( 'Error', 'woo-supplier-sync' ),
	unchanged: __( 'No change', 'woo-supplier-sync' ),
	skipped: __( 'Skipped', 'woo-supplier-sync' ),
};

export const PHASE_LABELS: Record< RunPhase, string > = {
	fetch: __( 'Downloading the feed', 'woo-supplier-sync' ),
	parse: __( 'Reading offers', 'woo-supplier-sync' ),
	apply: __( 'Updating products', 'woo-supplier-sync' ),
	sweep: __( 'Checking removed offers', 'woo-supplier-sync' ),
	finish: __( 'Finishing', 'woo-supplier-sync' ),
};

export const FIELD_LABELS: Record< string, string > = {
	name: __( 'Title', 'woo-supplier-sync' ),
	description: __( 'Description', 'woo-supplier-sync' ),
	images: __( 'Photos', 'woo-supplier-sync' ),
	categories: __( 'Categories', 'woo-supplier-sync' ),
	attributes: __( 'Attributes', 'woo-supplier-sync' ),
	regular_price: __( 'Price', 'woo-supplier-sync' ),
	sale_price: __( 'Sale price', 'woo-supplier-sync' ),
	quantity: __( 'Stock', 'woo-supplier-sync' ),
	stock_status: __( 'Availability', 'woo-supplier-sync' ),
	variations: __( 'Variations', 'woo-supplier-sync' ),
	status: __( 'Visibility in store', 'woo-supplier-sync' ),
	visibility: __( 'Catalog visibility', 'woo-supplier-sync' ),
	sku: __( 'SKU', 'woo-supplier-sync' ),
	type: __( 'Product type', 'woo-supplier-sync' ),
};

export function money(
	value: number | null | undefined,
	currency: string
): string {
	if ( value === null || value === undefined || Number.isNaN( value ) ) {
		return '—';
	}
	try {
		return new Intl.NumberFormat( LOCALE, {
			style: 'currency',
			currency,
		} ).format( value );
	} catch {
		return value.toFixed( 2 ) + ' ' + currency;
	}
}

export function number( value: number ): string {
	return new Intl.NumberFormat( LOCALE ).format( value );
}

export function relativeTime( iso: string | null | undefined ): string {
	if ( ! iso ) {
		return __( 'never', 'woo-supplier-sync' );
	}
	const seconds = Math.round(
		( new Date( iso ).getTime() - Date.now() ) / 1000
	);
	const abs = Math.abs( seconds );
	const rtf = new Intl.RelativeTimeFormat( LOCALE, { numeric: 'auto' } );
	if ( abs < 60 ) {
		return rtf.format( seconds, 'second' );
	}
	if ( abs < 3600 ) {
		return rtf.format( Math.round( seconds / 60 ), 'minute' );
	}
	if ( abs < 86400 ) {
		return rtf.format( Math.round( seconds / 3600 ), 'hour' );
	}
	return rtf.format( Math.round( seconds / 86400 ), 'day' );
}

export function dateTime( iso: string | null | undefined ): string {
	return iso
		? new Date( iso ).toLocaleString( LOCALE, {
				dateStyle: 'medium',
				timeStyle: 'short',
			} )
		: '—';
}

export function duration( run: Run ): string {
	if ( ! run.started_at ) {
		return '—';
	}
	const end = run.finished_at
		? new Date( run.finished_at ).getTime()
		: Date.now();
	const seconds = Math.max(
		1,
		Math.round( ( end - new Date( run.started_at ).getTime() ) / 1000 )
	);
	if ( seconds < 60 ) {
		return sprintf(
			/* translators: %d: number. */
			_n( '%d second', '%d seconds', seconds, 'woo-supplier-sync' ),
			seconds
		);
	}
	const minutes = Math.round( seconds / 60 );
	return sprintf(
		/* translators: %d: number. */
		_n( '%d minute', '%d minutes', minutes, 'woo-supplier-sync' ),
		minutes
	);
}

export function runSummary( run: Run ): string {
	if ( run.status === 'failed' ) {
		return run.error ?? __( 'Failed', 'woo-supplier-sync' );
	}
	if ( run.status === 'queued' ) {
		return __( 'Waiting in the queue', 'woo-supplier-sync' );
	}
	if ( run.status === 'running' ) {
		return PHASE_LABELS[ run.phase ];
	}
	if ( run.status === 'cancelled' ) {
		return __( 'Cancelled', 'woo-supplier-sync' );
	}
	const s = run.stats;
	const parts: string[] = [];
	if ( s.created ) {
		parts.push(
			sprintf(
				/* translators: %d: number. */
				_n( '%d new', '%d new', s.created, 'woo-supplier-sync' ),
				s.created
			)
		);
	}
	if ( s.updated ) {
		parts.push(
			sprintf(
				/* translators: %d: number. */
				_n(
					'%d updated',
					'%d updated',
					s.updated,
					'woo-supplier-sync'
				),
				s.updated
			)
		);
	}
	if ( s.unpublished ) {
		parts.push(
			sprintf(
				/* translators: %d: number. */
				_n(
					'%d taken off sale',
					'%d taken off sale',
					s.unpublished,
					'woo-supplier-sync'
				),
				s.unpublished
			)
		);
	}
	if ( s.errors ) {
		parts.push(
			sprintf(
				/* translators: %d: number. */
				_n( '%d error', '%d errors', s.errors, 'woo-supplier-sync' ),
				s.errors
			)
		);
	}
	if ( ! parts.length ) {
		return run.dry_run
			? __( 'Nothing would change', 'woo-supplier-sync' )
			: __( 'Everything was already up to date', 'woo-supplier-sync' );
	}
	return parts.join( ', ' );
}
