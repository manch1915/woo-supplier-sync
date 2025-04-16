import { Button, Notice, Spinner } from '@wordpress/components';
import { createContext, useContext } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import { ACTION_LABELS, number } from './format';
import type { Meta, Run, RunStats } from './types';

export const MetaContext = createContext< Meta | null >( null );

export function useMeta(): Meta {
	const meta = useContext( MetaContext );
	if ( ! meta ) {
		throw new Error( 'Meta is not loaded' );
	}
	return meta;
}

export function PageHeader( {
	title,
	description,
	actions,
}: {
	title: string;
	description?: ReactNode;
	actions?: ReactNode;
} ) {
	return (
		<header className="wss-page-header">
			<div>
				<h1 className="wss-page-title">{ title }</h1>
				{ description && (
					<p className="wss-page-description">{ description }</p>
				) }
			</div>
			{ actions && <div className="wss-page-actions">{ actions }</div> }
		</header>
	);
}

export function Loading( { label }: { label?: string } ) {
	return (
		<div className="wss-loading" role="status">
			<Spinner />
			<span>{ label ?? __( 'Loading…', 'woo-supplier-sync' ) }</span>
		</div>
	);
}

export function ErrorNotice( {
	message,
	onRetry,
}: {
	message: string;
	onRetry?: () => void;
} ) {
	return (
		<Notice status="error" isDismissible={ false } className="wss-notice">
			<p>{ message }</p>
			{ onRetry && (
				<Button variant="secondary" onClick={ onRetry }>
					{ __( 'Try again', 'woo-supplier-sync' ) }
				</Button>
			) }
		</Notice>
	);
}

export function EmptyState( {
	title,
	children,
	action,
}: {
	title: string;
	children?: ReactNode;
	action?: ReactNode;
} ) {
	return (
		<div className="wss-empty">
			<h2>{ title }</h2>
			{ children && <p>{ children }</p> }
			{ action }
		</div>
	);
}

export function ReadOnlyBanner() {
	const meta = useMeta();
	if ( meta.caps.manage ) {
		return null;
	}
	return (
		<p className="wss-readonly">
			{ meta.caps.run
				? __(
						'Demo access: you can run syncs and preview changes. Editing suppliers and prices is turned off.',
						'woo-supplier-sync'
					)
				: __( 'Read-only access.', 'woo-supplier-sync' ) }
		</p>
	);
}

type Segment = 'created' | 'updated' | 'unpublished' | 'errors' | 'unchanged';
const SEGMENTS: Segment[] = [
	'created',
	'updated',
	'unpublished',
	'errors',
	'unchanged',
];
const segmentLabel = ( key: Segment ): string =>
	ACTION_LABELS[ key === 'errors' ? 'error' : key ];

/**
 * What a run did, as one bar: each segment is a share of the products the run touched.
 */
export function RunBar( {
	stats,
	compact = false,
}: {
	stats: RunStats;
	compact?: boolean;
} ) {
	const total = SEGMENTS.reduce(
		( sum, key ) => sum + ( stats[ key ] ?? 0 ),
		0
	);
	if ( total === 0 ) {
		return (
			<div
				className={ `wss-runbar is-empty${ compact ? ' is-compact' : '' }` }
				aria-hidden="true"
			/>
		);
	}
	const label = SEGMENTS.filter( ( key ) => stats[ key ] > 0 )
		.map(
			( key ) => `${ segmentLabel( key ) }: ${ number( stats[ key ] ) }`
		)
		.join( ', ' );

	return (
		<div
			className={ `wss-runbar${ compact ? ' is-compact' : '' }` }
			role="img"
			aria-label={ label }
			title={ label }
		>
			{ SEGMENTS.map( ( key ) =>
				stats[ key ] > 0 ? (
					<span
						key={ key }
						className={ `wss-runbar__seg is-${ key }` }
						style={ { flexGrow: stats[ key ] } }
					/>
				) : null
			) }
		</div>
	);
}

export function RunLegend( { stats }: { stats: RunStats } ) {
	return (
		<ul className="wss-legend">
			{ SEGMENTS.map( ( key ) => (
				<li key={ key } className={ stats[ key ] ? '' : 'is-zero' }>
					<span
						className={ `wss-dot is-${ key }` }
						aria-hidden="true"
					/>
					<span className="wss-legend__label">
						{ segmentLabel( key ) }
					</span>
					<strong>{ number( stats[ key ] ?? 0 ) }</strong>
				</li>
			) ) }
		</ul>
	);
}

export function StatusBadge( { run }: { run: Run } ) {
	const labels: Record< Run[ 'status' ], string > = {
		queued: __( 'Queued', 'woo-supplier-sync' ),
		running: __( 'Running', 'woo-supplier-sync' ),
		completed: run.dry_run
			? __( 'Preview ready', 'woo-supplier-sync' )
			: __( 'Done', 'woo-supplier-sync' ),
		failed: __( 'Failed', 'woo-supplier-sync' ),
		cancelled: __( 'Cancelled', 'woo-supplier-sync' ),
	};
	return (
		<span className={ `wss-badge is-${ run.status }` }>
			{ labels[ run.status ] }
		</span>
	);
}

export function DryRunTag() {
	return (
		<span className="wss-tag">
			{ __( 'Dry run', 'woo-supplier-sync' ) }
		</span>
	);
}

export function Progress( { run }: { run: Run } ) {
	const percent = Math.round( run.progress * 100 );
	return (
		<div className="wss-progress">
			<div
				className="wss-progress__track"
				role="progressbar"
				aria-valuemin={ 0 }
				aria-valuemax={ 100 }
				aria-valuenow={ percent }
				aria-label={ sprintf(
					/* translators: %d: percent. */ __(
						'%d%% done',
						'woo-supplier-sync'
					),
					percent
				) }
			>
				<span style={ { width: `${ percent }%` } } />
			</div>
			<span className="wss-progress__value">{ percent }%</span>
		</div>
	);
}

export function Field( {
	label,
	help,
	children,
	wide = false,
}: {
	label: string;
	help?: ReactNode;
	children: ReactNode;
	wide?: boolean;
} ) {
	return (
		<div className={ `wss-field${ wide ? ' is-wide' : '' }` }>
			<span className="wss-field__label">{ label }</span>
			{ children }
			{ help && <span className="wss-field__help">{ help }</span> }
		</div>
	);
}
