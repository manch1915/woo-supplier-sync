import { Button } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import { isActive } from '../actions';
import {
	EmptyState,
	ErrorNotice,
	Loading,
	PageHeader,
	ReadOnlyBanner,
	RunBar,
	StatusBadge,
	useMeta,
} from '../components';
import { number, relativeTime, runSummary } from '../format';
import { useAsync } from '../hooks';
import type { Dashboard, Supplier } from '../types';
import { SupplierRow } from './SupplierRow';
import { ActivityChart } from './ActivityChart';

export function Overview( {
	navigate,
}: {
	navigate: ( path: string ) => void;
} ) {
	const meta = useMeta();
	const suppliers = useAsync(
		() => api.get< Supplier[] >( '/suppliers' ),
		[],
		( list ) => list.some( ( s ) => isActive( s.active_run ) )
	);
	const dashboard = useAsync(
		() => api.get< Dashboard >( '/dashboard' ),
		[],
		( d ) => d.active_runs > 0,
		4000
	);

	const reload = () => {
		suppliers.reload();
		dashboard.reload();
	};

	const d = dashboard.data;
	const outOfStock = Number( d?.stock.outofstock ?? 0 );
	const onOrder = Number( d?.stock.onbackorder ?? 0 );

	return (
		<>
			<PageHeader
				title={ __( 'Catalog sync', 'woo-supplier-sync' ) }
				description={ __(
					'Prices, stock and new products from your suppliers, merged into one catalog.',
					'woo-supplier-sync'
				) }
				actions={
					meta.caps.manage && (
						<Button
							variant="secondary"
							onClick={ () => navigate( 'suppliers/new' ) }
						>
							{ __( 'Add supplier', 'woo-supplier-sync' ) }
						</Button>
					)
				}
			/>
			<ReadOnlyBanner />

			{ dashboard.error && (
				<ErrorNotice
					message={ dashboard.error }
					onRetry={ dashboard.reload }
				/>
			) }
			{ d && (
				<dl className="wss-figures">
					<div>
						<dt>
							{ __(
								'Products kept in sync',
								'woo-supplier-sync'
							) }
						</dt>
						<dd>{ number( d.products ) }</dd>
					</div>
					<div>
						<dt>
							{ __( 'Supplier offers', 'woo-supplier-sync' ) }
						</dt>
						<dd>{ number( d.offers ) }</dd>
					</div>
					<div>
						<dt>
							{ __(
								'Sold by several suppliers',
								'woo-supplier-sync'
							) }
						</dt>
						<dd>{ number( d.merged ) }</dd>
					</div>
					<div>
						<dt>
							{ __(
								'Out of stock / on order',
								'woo-supplier-sync'
							) }
						</dt>
						<dd>
							{ number( outOfStock ) }{ ' ' }
							<span className="wss-figures__minor">
								/ { number( onOrder ) }
							</span>
						</dd>
					</div>
					<div className={ d.errors_24h ? 'is-alert' : '' }>
						<dt>
							{ __(
								'Errors in the last 24 hours',
								'woo-supplier-sync'
							) }
						</dt>
						<dd>{ number( d.errors_24h ) }</dd>
					</div>
				</dl>
			) }

			<section
				className="wss-section"
				aria-labelledby="wss-suppliers-title"
			>
				<h2 id="wss-suppliers-title" className="wss-section__title">
					{ __( 'Suppliers', 'woo-supplier-sync' ) }
				</h2>
				{ suppliers.loading && <Loading /> }
				{ suppliers.error && (
					<ErrorNotice
						message={ suppliers.error }
						onRetry={ suppliers.reload }
					/>
				) }
				{ suppliers.data?.length === 0 && (
					<EmptyState
						title={ __( 'No suppliers yet', 'woo-supplier-sync' ) }
						action={
							meta.caps.manage && (
								<Button
									variant="primary"
									onClick={ () =>
										navigate( 'suppliers/new' )
									}
								>
									{ __(
										'Add the first supplier',
										'woo-supplier-sync'
									) }
								</Button>
							)
						}
					>
						{ __(
							'Connect a YML or XML feed, a CSV or Excel price list, a JSON API or a supplier website.',
							'woo-supplier-sync'
						) }
					</EmptyState>
				) }
				<div className="wss-supplier-list">
					{ suppliers.data?.map( ( supplier ) => (
						<SupplierRow
							key={ supplier.id }
							supplier={ supplier }
							navigate={ navigate }
							onStarted={ reload }
						/>
					) ) }
				</div>
			</section>

			{ d && (
				<div className="wss-columns">
					<section
						className="wss-section wss-panel"
						aria-labelledby="wss-activity-title"
					>
						<h2
							id="wss-activity-title"
							className="wss-section__title"
						>
							{ __( 'Last 14 days', 'woo-supplier-sync' ) }
						</h2>
						<ActivityChart days={ d.activity } />
					</section>
					<section
						className="wss-section wss-panel"
						aria-labelledby="wss-recent-title"
					>
						<div className="wss-section__head">
							<h2
								id="wss-recent-title"
								className="wss-section__title"
							>
								{ __( 'Recent runs', 'woo-supplier-sync' ) }
							</h2>
							<a href="#/runs">
								{ __( 'All runs', 'woo-supplier-sync' ) }
							</a>
						</div>
						{ d.recent_runs.length === 0 && (
							<p className="wss-muted">
								{ __(
									'Runs will show up here.',
									'woo-supplier-sync'
								) }
							</p>
						) }
						<ol className="wss-recent">
							{ d.recent_runs.map( ( run ) => {
								const name =
									suppliers.data?.find(
										( s ) => s.id === run.supplier_id
									)?.name ?? `#${ run.supplier_id }`;
								return (
									<li key={ run.id }>
										<a
											href={ `#/runs/${ run.id }` }
											className="wss-recent__link"
										>
											<span className="wss-recent__title">
												{ name }
												{ run.dry_run && (
													<span className="wss-tag">
														{ __(
															'Dry run',
															'woo-supplier-sync'
														) }
													</span>
												) }
											</span>
											<span className="wss-recent__summary">
												{ runSummary( run ) }
											</span>
										</a>
										<RunBar stats={ run.stats } compact />
										<span className="wss-recent__meta">
											<StatusBadge run={ run } />
											<span className="wss-muted">
												{ relativeTime(
													run.created_at
												) }
											</span>
										</span>
									</li>
								);
							} ) }
						</ol>
					</section>
				</div>
			) }
			{ d && d.active_runs > 0 && (
				<p className="wss-muted wss-footnote">
					{ sprintf(
						/* translators: %d: number of runs. */
						_n(
							'%d run in progress. This page refreshes on its own.',
							'%d runs in progress. This page refreshes on its own.',
							d.active_runs,
							'woo-supplier-sync'
						),
						d.active_runs
					) }
				</p>
			) }
		</>
	);
}
