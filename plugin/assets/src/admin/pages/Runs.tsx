import { Button, SelectControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api, getPage } from '../api';
import { isActive } from '../actions';
import {
	DryRunTag,
	EmptyState,
	ErrorNotice,
	Loading,
	PageHeader,
	RunBar,
	StatusBadge,
} from '../components';
import { dateTime, duration, runSummary } from '../format';
import { useAsync } from '../hooks';
import type { Run, Supplier } from '../types';

const SOURCE_LABELS: Record< Run[ 'source' ], string > = {
	manual: __( 'Started by hand', 'woo-supplier-sync' ),
	schedule: __( 'On schedule', 'woo-supplier-sync' ),
	cli: __( 'WP-CLI', 'woo-supplier-sync' ),
};

export function Runs( { navigate }: { navigate: ( path: string ) => void } ) {
	const [ supplier, setSupplier ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const suppliers = useAsync(
		() => api.get< Supplier[] >( '/suppliers' ),
		[]
	);
	const runs = useAsync(
		() =>
			getPage< Run >( '/runs', {
				supplier: supplier || undefined,
				page,
			} ),
		[ supplier, page ],
		( result ) => result.items.some( isActive ),
		3000
	);

	return (
		<>
			<PageHeader
				title={ __( 'Runs', 'woo-supplier-sync' ) }
				description={ __(
					'Every sync and dry run, with the list of products it changed.',
					'woo-supplier-sync'
				) }
			/>
			<div className="wss-toolbar">
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Supplier', 'woo-supplier-sync' ) }
					value={ String( supplier ) }
					options={ [
						{
							value: '0',
							label: __( 'All suppliers', 'woo-supplier-sync' ),
						},
						...( suppliers.data ?? [] ).map( ( s ) => ( {
							value: String( s.id ),
							label: s.name,
						} ) ),
					] }
					onChange={ ( value ) => {
						setSupplier( Number( value ) );
						setPage( 1 );
					} }
				/>
			</div>

			{ runs.loading && ! runs.data && <Loading /> }
			{ runs.error && (
				<ErrorNotice message={ runs.error } onRetry={ runs.reload } />
			) }
			{ runs.data?.items.length === 0 && (
				<EmptyState title={ __( 'No runs yet', 'woo-supplier-sync' ) }>
					{ __(
						'Start a sync or a dry run from the overview.',
						'woo-supplier-sync'
					) }
				</EmptyState>
			) }

			{ !! runs.data?.items.length && (
				<div className="wss-table-wrap">
					<table className="wss-table wss-runs">
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Run', 'woo-supplier-sync' ) }
								</th>
								<th scope="col">
									{ __( 'Supplier', 'woo-supplier-sync' ) }
								</th>
								<th scope="col" className="wss-runs__result">
									{ __( 'Result', 'woo-supplier-sync' ) }
								</th>
								<th scope="col">
									{ __( 'Started', 'woo-supplier-sync' ) }
								</th>
								<th scope="col">
									{ __( 'Took', 'woo-supplier-sync' ) }
								</th>
								<th scope="col">
									{ __( 'Status', 'woo-supplier-sync' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ runs.data.items.map( ( run ) => (
								<tr
									key={ run.id }
									onClick={ () =>
										navigate( `runs/${ run.id }` )
									}
									className="is-clickable"
								>
									<td>
										<a href={ `#/runs/${ run.id }` }>
											#{ run.id }
										</a>
										{ run.dry_run && <DryRunTag /> }
									</td>
									<td>
										{ run.supplier_name }
										<span className="wss-cell-sub">
											{ SOURCE_LABELS[ run.source ] }
										</span>
									</td>
									<td className="wss-runs__result">
										<span
											className={
												run.status === 'failed'
													? 'is-failed'
													: ''
											}
										>
											{ runSummary( run ) }
										</span>
										<RunBar stats={ run.stats } compact />
									</td>
									<td>
										{ dateTime(
											run.started_at ?? run.created_at
										) }
									</td>
									<td className="wss-num">
										{ duration( run ) }
									</td>
									<td>
										<StatusBadge run={ run } />
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			{ runs.data && runs.data.pages > 1 && (
				<nav
					className="wss-pager"
					aria-label={ __( 'Pages', 'woo-supplier-sync' ) }
				>
					<Button
						variant="tertiary"
						disabled={ page <= 1 }
						onClick={ () => setPage( page - 1 ) }
					>
						{ __( 'Newer', 'woo-supplier-sync' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: page, 2: total pages. */ __(
								'Page %1$d of %2$d',
								'woo-supplier-sync'
							),
							page,
							runs.data.pages
						) }
					</span>
					<Button
						variant="tertiary"
						disabled={ page >= runs.data.pages }
						onClick={ () => setPage( page + 1 ) }
					>
						{ __( 'Older', 'woo-supplier-sync' ) }
					</Button>
				</nav>
			) }
		</>
	);
}
