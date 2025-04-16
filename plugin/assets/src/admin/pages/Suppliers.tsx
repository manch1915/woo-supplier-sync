import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { api } from '../api';
import { isActive } from '../actions';
import {
	EmptyState,
	ErrorNotice,
	Loading,
	PageHeader,
	ReadOnlyBanner,
	useMeta,
} from '../components';
import { useAsync } from '../hooks';
import type { Supplier } from '../types';
import { SupplierRow } from './SupplierRow';

export function Suppliers( {
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

	return (
		<>
			<PageHeader
				title={ __( 'Suppliers', 'woo-supplier-sync' ) }
				description={ __(
					'Where each supplier publishes its catalog and how its columns map to your products.',
					'woo-supplier-sync'
				) }
				actions={
					meta.caps.manage && (
						<Button
							variant="primary"
							onClick={ () => navigate( 'suppliers/new' ) }
						>
							{ __( 'Add supplier', 'woo-supplier-sync' ) }
						</Button>
					)
				}
			/>
			<ReadOnlyBanner />
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
				>
					{ __(
						'Add a supplier to start importing its catalog.',
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
						onStarted={ suppliers.reload }
					/>
				) ) }
			</div>
		</>
	);
}
