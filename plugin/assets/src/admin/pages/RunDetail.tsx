import { Button, SearchControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, getPage } from '../api';
import { isActive, startRun } from '../actions';
import {
	DryRunTag,
	ErrorNotice,
	Loading,
	PageHeader,
	Progress,
	RunBar,
	RunLegend,
	StatusBadge,
	useMeta,
} from '../components';
import {
	ACTION_LABELS,
	dateTime,
	duration,
	FIELD_LABELS,
	PHASE_LABELS,
	runSummary,
	LOCALE,
} from '../format';
import { useAsync } from '../hooks';
import type { ItemAction, Run, RunItem } from '../types';

const FILTERS: Array< {
	value: '' | ItemAction;
	label: string;
	stat: keyof Run[ 'stats' ] | null;
} > = [
	{ value: '', label: __( 'All changes', 'woo-supplier-sync' ), stat: null },
	{ value: 'created', label: ACTION_LABELS.created, stat: 'created' },
	{ value: 'updated', label: ACTION_LABELS.updated, stat: 'updated' },
	{
		value: 'unpublished',
		label: ACTION_LABELS.unpublished,
		stat: 'unpublished',
	},
	{
		value: 'error',
		label: __( 'Errors', 'woo-supplier-sync' ),
		stat: 'errors',
	},
];

export function RunDetail( {
	id,
	navigate,
}: {
	id: number;
	navigate: ( path: string ) => void;
} ) {
	const meta = useMeta();
	const [ action, setAction ] = useState< '' | ItemAction >( '' );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ busy, setBusy ] = useState( false );
	const [ actionError, setActionError ] = useState< string | null >( null );

	const run = useAsync(
		() => api.get< Run >( `/runs/${ id }` ),
		[ id ],
		isActive
	);
	const active = isActive( run.data );
	const items = useAsync(
		() =>
			getPage< RunItem >( `/runs/${ id }/items`, {
				action,
				search: query,
				page,
			} ),
		[ id, action, query, page, active ]
	);

	useEffect( () => {
		const timer = window.setTimeout( () => {
			setQuery( search );
			setPage( 1 );
		}, 300 );
		return () => window.clearTimeout( timer );
	}, [ search ] );

	if ( run.loading && ! run.data ) {
		return <Loading />;
	}
	if ( run.error || ! run.data ) {
		return (
			<ErrorNotice
				message={
					run.error ?? __( 'Run not found.', 'woo-supplier-sync' )
				}
				onRetry={ run.reload }
			/>
		);
	}

	const r = run.data;

	const cancel = async () => {
		setBusy( true );
		try {
			run.setData( await api.post< Run >( `/runs/${ r.id }/cancel` ) );
		} catch ( e ) {
			setActionError( e instanceof Error ? e.message : String( e ) );
		} finally {
			setBusy( false );
		}
	};

	const applyForReal = async () => {
		setBusy( true );
		try {
			const started = await startRun( r.supplier_id, false );
			navigate( `runs/${ started.id }` );
		} catch ( e ) {
			setActionError( e instanceof Error ? e.message : String( e ) );
		} finally {
			setBusy( false );
		}
	};

	const changed = r.stats.created + r.stats.updated + r.stats.unpublished;

	return (
		<>
			<p className="wss-back">
				<a href="#/runs">{ __( 'All runs', 'woo-supplier-sync' ) }</a>
			</p>
			<PageHeader
				title={ sprintf(
					/* translators: 1: run ID, 2: supplier. */ __(
						'Run #%1$d, %2$s',
						'woo-supplier-sync'
					),
					r.id,
					r.supplier_name ?? ''
				) }
				description={
					<>
						{ r.dry_run && <DryRunTag /> }
						<StatusBadge run={ r } />
						<span>
							{ dateTime( r.started_at ?? r.created_at ) }
						</span>
						{ r.user_name && <span>{ r.user_name }</span> }
						{ r.finished_at && <span>{ duration( r ) }</span> }
					</>
				}
				actions={
					<>
						{ active && meta.caps.run && (
							<Button
								variant="secondary"
								isDestructive
								onClick={ cancel }
								isBusy={ busy }
								disabled={ busy }
							>
								{ __( 'Cancel run', 'woo-supplier-sync' ) }
							</Button>
						) }
						{ r.dry_run &&
							r.status === 'completed' &&
							changed > 0 &&
							meta.caps.run && (
								<Button
									variant="primary"
									onClick={ applyForReal }
									isBusy={ busy }
									disabled={ busy }
								>
									{ __(
										'Apply these changes',
										'woo-supplier-sync'
									) }
								</Button>
							) }
					</>
				}
			/>
			{ actionError && <ErrorNotice message={ actionError } /> }

			{ active ? (
				<section className="wss-panel wss-live" aria-live="polite">
					<p className="wss-live__phase">
						{ PHASE_LABELS[ r.phase ] }
					</p>
					<Progress run={ r } />
					<p className="wss-muted">
						{ sprintf(
							/* translators: %d: number of offers. */
							_n(
								'%d offer read so far.',
								'%d offers read so far.',
								r.stats.parsed,
								'woo-supplier-sync'
							),
							r.stats.parsed
						) }
					</p>
				</section>
			) : (
				<section
					className={ `wss-panel wss-outcome${ r.status === 'failed' ? ' is-failed' : '' }` }
				>
					<p className="wss-outcome__summary">
						{ r.dry_run && r.status === 'completed'
							? sprintf(
									/* translators: %s: summary such as "3 updated". */
									__(
										'If you sync now: %s.',
										'woo-supplier-sync'
									),
									runSummary( r ).toLowerCase()
								)
							: runSummary( r ) }
					</p>
					<RunBar stats={ r.stats } />
					<RunLegend stats={ r.stats } />
				</section>
			) }

			<section className="wss-section" aria-labelledby="wss-items-title">
				<h2 id="wss-items-title" className="wss-section__title">
					{ r.dry_run
						? __( 'What would change', 'woo-supplier-sync' )
						: __( 'What changed', 'woo-supplier-sync' ) }
				</h2>
				<div className="wss-toolbar">
					<div
						className="wss-segmented"
						role="group"
						aria-label={ __(
							'Filter by change',
							'woo-supplier-sync'
						) }
					>
						{ FILTERS.map( ( filter ) => (
							<button
								key={ filter.value }
								type="button"
								aria-pressed={ action === filter.value }
								onClick={ () => {
									setAction( filter.value );
									setPage( 1 );
								} }
							>
								{ filter.label }
								{ filter.stat && (
									<span className="wss-count">
										{ r.stats[ filter.stat ] }
									</span>
								) }
							</button>
						) ) }
					</div>
					<SearchControl
						__nextHasNoMarginBottom
						label={ __( 'Search products', 'woo-supplier-sync' ) }
						placeholder={ __(
							'Search by product title',
							'woo-supplier-sync'
						) }
						value={ search }
						onChange={ setSearch }
					/>
				</div>

				{ items.error && (
					<ErrorNotice
						message={ items.error }
						onRetry={ items.reload }
					/>
				) }
				{ items.loading && ! items.data && <Loading /> }
				{ items.data?.items.length === 0 && (
					<p className="wss-empty-inline">
						{ query
							? __(
									'No products match this search.',
									'woo-supplier-sync'
								)
							: __(
									'No product was created, changed or taken off sale in this run.',
									'woo-supplier-sync'
								) }
					</p>
				) }
				<ul className="wss-manifest">
					{ items.data?.items.map( ( item ) => (
						<ManifestItem
							key={ item.id }
							item={ item }
							currency={ meta.store_currency }
						/>
					) ) }
				</ul>
				{ items.data && items.data.pages > 1 && (
					<nav
						className="wss-pager"
						aria-label={ __( 'Pages', 'woo-supplier-sync' ) }
					>
						<Button
							variant="tertiary"
							disabled={ page <= 1 }
							onClick={ () => setPage( page - 1 ) }
						>
							{ __( 'Previous', 'woo-supplier-sync' ) }
						</Button>
						<span>
							{ sprintf(
								/* translators: 1: page, 2: pages. */ __(
									'Page %1$d of %2$d',
									'woo-supplier-sync'
								),
								page,
								items.data.pages
							) }
						</span>
						<Button
							variant="tertiary"
							disabled={ page >= items.data.pages }
							onClick={ () => setPage( page + 1 ) }
						>
							{ __( 'Next', 'woo-supplier-sync' ) }
						</Button>
					</nav>
				) }
			</section>
		</>
	);
}

function ManifestItem( {
	item,
	currency,
}: {
	item: RunItem;
	currency: string;
} ) {
	const rows = Object.entries( item.changes ).filter(
		( [ path ] ) => path !== 'type' || item.action !== 'created'
	);
	const [ open, setOpen ] = useState(
		item.action !== 'created' && rows.length <= 8
	);
	const link = item.edit_url ?? item.view_url;

	return (
		<li className={ `wss-manifest__item is-${ item.action }` }>
			<div className="wss-manifest__head">
				<span className={ `wss-action is-${ item.action }` }>
					{ ACTION_LABELS[ item.action ] }
				</span>
				<span className="wss-manifest__title">
					{ link ? (
						<a href={ link } target="_blank" rel="noreferrer">
							{ item.title }
						</a>
					) : (
						item.title
					) }
				</span>
				{ rows.length > 0 && (
					<button
						type="button"
						className="wss-link-button"
						aria-expanded={ open }
						onClick={ () => setOpen( ! open ) }
					>
						{ open
							? __( 'Hide details', 'woo-supplier-sync' )
							: sprintf(
									/* translators: %d: number of fields. */ _n(
										'%d field',
										'%d fields',
										rows.length,
										'woo-supplier-sync'
									),
									rows.length
								) }
					</button>
				) }
			</div>
			{ item.message && (
				<p className="wss-manifest__message">{ item.message }</p>
			) }
			{ open && rows.length > 0 && (
				<table className="wss-diff">
					<tbody>
						{ rows.map( ( [ path, [ from, to ] ] ) => (
							<tr key={ path }>
								<th scope="row">{ fieldLabel( path ) }</th>
								<td>
									{ from !== null &&
										from !== undefined &&
										from !== '' && (
											<del>
												{ display(
													path,
													from,
													currency
												) }
											</del>
										) }
									<ins>{ display( path, to, currency ) }</ins>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</li>
	);
}

function fieldLabel( path: string ): string {
	const parts = path.split( '.' );
	const head = FIELD_LABELS[ parts[ 0 ]! ] ?? parts[ 0 ]!;
	if ( parts[ 0 ] === 'attributes' ) {
		return parts.slice( 1 ).join( '.' );
	}
	if ( parts[ 0 ] === 'variations' && parts.length >= 3 ) {
		// variations.SKU.attributes.Color reads as "SKU · Color".
		const field =
			parts[ 2 ] === 'attributes' && parts.length > 3
				? parts.slice( 3 ).join( '.' )
				: ( FIELD_LABELS[ parts[ 2 ]! ] ?? parts[ 2 ] );
		return `${ parts[ 1 ] } · ${ field }`;
	}
	return head;
}

function display( path: string, value: unknown, currency: string ): string {
	if ( value === null || value === undefined || value === '' ) {
		return __( 'empty', 'woo-supplier-sync' );
	}
	const field = path.split( '.' ).at( -1 ) ?? '';
	if (
		( field === 'regular_price' || field === 'sale_price' ) &&
		! Number.isNaN( Number( value ) )
	) {
		return new Intl.NumberFormat( LOCALE, {
			style: 'currency',
			currency,
		} ).format( Number( value ) );
	}
	if ( field === 'description' ) {
		return __( 'new text', 'woo-supplier-sync' );
	}
	if ( field === 'images' ) {
		const count = String( value ).split( ' ' ).filter( Boolean ).length;
		return sprintf(
			/* translators: %d: number of photos. */ _n(
				'%d photo',
				'%d photos',
				count,
				'woo-supplier-sync'
			),
			count
		);
	}
	if ( field === 'stock_status' ) {
		const labels: Record< string, string > = {
			instock: __( 'In stock', 'woo-supplier-sync' ),
			outofstock: __( 'Out of stock', 'woo-supplier-sync' ),
			onbackorder: __( 'On order', 'woo-supplier-sync' ),
		};
		return labels[ String( value ) ] ?? String( value );
	}
	if ( field === 'status' ) {
		return String( value ) === 'publish'
			? __( 'Published', 'woo-supplier-sync' )
			: __( 'Draft', 'woo-supplier-sync' );
	}
	if ( field === 'categories' ) {
		return __( 'changed', 'woo-supplier-sync' );
	}
	return String( value );
}
