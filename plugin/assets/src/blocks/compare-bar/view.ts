import { getContext, getElement, store } from '@wordpress/interactivity';

interface Item {
	id: number;
	name: string;
	image: string;
}

interface Product extends Item {
	url: string;
	price: string;
	availability: string;
	sku: string;
	specs: Record< string, string >;
}

interface Cell {
	key: number;
	value: string;
}

interface Row {
	label: string;
	cells: Cell[];
	differs: boolean;
}

interface Labels {
	add: string;
	remove: string;
	full: string;
	compare: string;
	price: string;
	availability: string;
	sku: string;
	removeItem: string;
	addOneMore: string;
	countOf: string;
	loadError: string;
}

const STORAGE_KEY = 'supplier-sync-compare';
const LIMIT = 4;

function persist( items: Item[] ): void {
	try {
		window.localStorage.setItem( STORAGE_KEY, JSON.stringify( items ) );
	} catch {
		// Private mode or blocked storage: the tray simply forgets on reload.
	}
}

function restore(): Item[] {
	try {
		const raw = window.localStorage.getItem( STORAGE_KEY );
		const parsed: unknown = raw ? JSON.parse( raw ) : [];
		return Array.isArray( parsed )
			? ( parsed as Item[] )
					.filter( ( i ) => Number.isInteger( i?.id ) )
					.slice( 0, LIMIT )
			: [];
	} catch {
		return [];
	}
}

const dialog = () =>
	document.getElementById( 'wss-compare-dialog' ) as HTMLDialogElement | null;

// Values that come from the server (render.php) must not get client defaults here:
// a client value would overwrite the server one when the stores merge.
interface State {
	restUrl: string;
	labels: Labels;
	items: Item[];
	products: Product[];
	isLoading: boolean;
	onlyDifferences: boolean;
	error: string;
	readonly hasItems: boolean;
	readonly hasProducts: boolean;
	readonly canCompare: boolean;
	readonly isSelected: boolean;
	readonly isDisabled: boolean;
	readonly toggleLabel: string;
	readonly compareLabel: string;
	readonly hint: string;
	readonly removeLabel: string;
	readonly rows: Row[];
	readonly rowHidden: boolean;
}

const { state, actions } = store( 'supplier-sync/compare', {
	state: {
		get hasItems(): boolean {
			return state.items.length > 0;
		},
		get hasProducts(): boolean {
			return state.products.length > 0;
		},
		get canCompare(): boolean {
			return state.items.length >= 2;
		},
		get isSelected(): boolean {
			const { id } = getContext< Item >();
			return state.items.some( ( item ) => item.id === id );
		},
		get isDisabled(): boolean {
			return ! state.isSelected && state.items.length >= LIMIT;
		},
		get toggleLabel(): string {
			if ( state.isSelected ) {
				return state.labels.remove;
			}
			return state.items.length >= LIMIT
				? state.labels.full
				: state.labels.add;
		},
		get compareLabel(): string {
			return `${ state.labels.compare } (${ state.items.length })`;
		},
		get hint(): string {
			if ( state.items.length === 1 ) {
				return state.labels.addOneMore;
			}
			return state.labels.countOf
				.replace( '%1$d', String( state.items.length ) )
				.replace( '%2$d', String( LIMIT ) );
		},
		get removeLabel(): string {
			const { item } = getContext< { item: Item } >();
			return state.labels.removeItem.replace( '%s', item.name );
		},
		get rows(): Row[] {
			const products = state.products;
			if ( ! products.length ) {
				return [];
			}

			const specLabels: string[] = [];
			for ( const product of products ) {
				for ( const label of Object.keys( product.specs ) ) {
					if ( ! specLabels.includes( label ) ) {
						specLabels.push( label );
					}
				}
			}

			const fields: Array< {
				label: string;
				value: ( p: Product ) => string;
			} > = [
				{ label: state.labels.price, value: ( p ) => p.price },
				{
					label: state.labels.availability,
					value: ( p ) => p.availability,
				},
				...specLabels.map( ( label ) => ( {
					label,
					value: ( p: Product ) => p.specs[ label ] ?? '—',
				} ) ),
				{ label: state.labels.sku, value: ( p ) => p.sku || '—' },
			];

			return fields.map( ( field ) => {
				const cells = products.map( ( p ) => ( {
					key: p.id,
					value: field.value( p ),
				} ) );
				return {
					label: field.label,
					cells,
					differs: new Set( cells.map( ( c ) => c.value ) ).size > 1,
				};
			} );
		},
		get rowHidden(): boolean {
			const { row } = getContext< { row: Row } >();
			return state.onlyDifferences && ! row.differs;
		},
	} as State,

	actions: {
		toggle() {
			const item = getContext< Item >();
			if ( state.items.some( ( i ) => i.id === item.id ) ) {
				state.items = state.items.filter( ( i ) => i.id !== item.id );
			} else if ( state.items.length < LIMIT ) {
				state.items = [
					...state.items,
					{ id: item.id, name: item.name, image: item.image },
				];
			}
			persist( state.items );
		},
		remove() {
			const { item } = getContext< { item: Item } >();
			state.items = state.items.filter( ( i ) => i.id !== item.id );
			persist( state.items );
		},
		removeFromTable() {
			const { product } = getContext< { product: Product } >();
			state.items = state.items.filter( ( i ) => i.id !== product.id );
			state.products = state.products.filter(
				( p ) => p.id !== product.id
			);
			persist( state.items );
			if ( state.items.length < 2 ) {
				actions.close();
			}
		},
		clear() {
			state.items = [];
			state.products = [];
			persist( state.items );
		},
		*open() {
			dialog()?.showModal();
			const ids = state.items.map( ( i ) => i.id ).join( ',' );
			if ( state.products.map( ( p ) => p.id ).join( ',' ) === ids ) {
				return;
			}
			state.isLoading = true;
			state.error = '';
			try {
				const response: Response = yield fetch(
					`${ state.restUrl }?ids=${ encodeURIComponent( ids ) }`
				);
				if ( ! response.ok ) {
					throw new Error( String( response.status ) );
				}
				const products: Product[] = yield response.json();
				// Keep the order in which the shopper picked the products.
				state.products = state.items
					.map( ( item ) =>
						products.find( ( p ) => p.id === item.id )
					)
					.filter( ( p ): p is Product => !! p );
			} catch {
				state.error = state.labels.loadError;
			} finally {
				state.isLoading = false;
			}
		},
		close() {
			dialog()?.close();
		},
		closeOnBackdrop( event: MouseEvent ) {
			if ( event.target === getElement().ref ) {
				actions.close();
			}
		},
		toggleDifferences() {
			state.onlyDifferences = ! state.onlyDifferences;
		},
	},

	callbacks: {
		restore() {
			state.items = restore();
		},
		syncTabs( event: StorageEvent ) {
			if ( event.key === STORAGE_KEY ) {
				state.items = restore();
			}
		},
	},
} );
