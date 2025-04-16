import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api } from './api';
import { ErrorNotice, Loading, MetaContext } from './components';
import { useAsync, useRoute } from './hooks';
import { Overview } from './pages/Overview';
import { RunDetail } from './pages/RunDetail';
import { Runs } from './pages/Runs';
import { Rules } from './pages/Rules';
import { Settings } from './pages/Settings';
import { SupplierEditor } from './pages/SupplierEditor';
import { Suppliers } from './pages/Suppliers';
import type { Meta } from './types';
import './style.scss';

const NAV = [
	{ path: '', label: __( 'Overview', 'woo-supplier-sync' ) },
	{ path: 'suppliers', label: __( 'Suppliers', 'woo-supplier-sync' ) },
	{ path: 'rules', label: __( 'Prices', 'woo-supplier-sync' ) },
	{ path: 'runs', label: __( 'Runs', 'woo-supplier-sync' ) },
	{ path: 'settings', label: __( 'Settings', 'woo-supplier-sync' ) },
];

function App() {
	const [ route, navigate ] = useRoute();
	const meta = useAsync( () => api.get< Meta >( '/meta' ), [] );
	const section = route[ 0 ] ?? '';

	let page;
	if ( section === 'suppliers' && route[ 1 ] ) {
		page = (
			<SupplierEditor
				id={ route[ 1 ] === 'new' ? null : Number( route[ 1 ] ) }
				navigate={ navigate }
			/>
		);
	} else if ( section === 'suppliers' ) {
		page = <Suppliers navigate={ navigate } />;
	} else if ( section === 'runs' && route[ 1 ] ) {
		page = <RunDetail id={ Number( route[ 1 ] ) } navigate={ navigate } />;
	} else if ( section === 'runs' ) {
		page = <Runs navigate={ navigate } />;
	} else if ( section === 'rules' ) {
		page = <Rules />;
	} else if ( section === 'settings' ) {
		page = <Settings />;
	} else {
		page = <Overview navigate={ navigate } />;
	}

	return (
		<div className="wss-app">
			<nav
				className="wss-nav"
				aria-label={ __( 'Supplier Sync', 'woo-supplier-sync' ) }
			>
				<span className="wss-nav__brand">
					<svg
						viewBox="0 0 24 24"
						width="22"
						height="22"
						aria-hidden="true"
						focusable="false"
					>
						<path
							d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5v-9Z"
							fill="none"
							stroke="currentColor"
							strokeWidth="1.6"
							strokeLinejoin="round"
						/>
						<path
							d="m8 12 3 3 5-6"
							fill="none"
							stroke="currentColor"
							strokeWidth="1.8"
							strokeLinecap="round"
							strokeLinejoin="round"
						/>
					</svg>
					{ __( 'Supplier Sync', 'woo-supplier-sync' ) }
				</span>
				<ul className="wss-nav__links">
					{ NAV.map( ( item ) => (
						<li key={ item.path }>
							<a
								href={ `#/${ item.path }` }
								aria-current={
									section === item.path ? 'page' : undefined
								}
							>
								{ item.label }
							</a>
						</li>
					) ) }
				</ul>
				{ meta.data && (
					<a
						className="wss-nav__store"
						href={ meta.data.urls.shop }
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'Open store', 'woo-supplier-sync' ) }
					</a>
				) }
			</nav>
			<main className="wss-main" id="wss-main">
				{ meta.error && (
					<ErrorNotice
						message={ meta.error }
						onRetry={ meta.reload }
					/>
				) }
				{ ! meta.data && ! meta.error && <Loading /> }
				{ meta.data && (
					<MetaContext.Provider value={ meta.data }>
						{ page }
					</MetaContext.Provider>
				) }
			</main>
		</div>
	);
}

const root = document.getElementById( 'supplier-sync-root' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
