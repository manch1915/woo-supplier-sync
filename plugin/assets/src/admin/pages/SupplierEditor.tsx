import {
	Button,
	CheckboxControl,
	Modal,
	Notice,
	SelectControl,
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Icon, image } from '@wordpress/icons';
import { api } from '../api';
import {
	ErrorNotice,
	Loading,
	PageHeader,
	ReadOnlyBanner,
	useMeta,
} from '../components';
import { FIELD_LABELS, money, SCHEDULE_LABELS, TYPE_LABELS } from '../format';
import type { FeedType, Preview, Supplier, SupplierConfig } from '../types';
import { PairsEditor } from './PairsEditor';

const COLUMN_FIELDS: Array< {
	key: string;
	label: string;
	required?: boolean;
} > = [
	{
		key: 'name',
		label: __( 'Product title', 'woo-supplier-sync' ),
		required: true,
	},
	{
		key: 'price',
		label: __( 'Purchase price', 'woo-supplier-sync' ),
		required: true,
	},
	{ key: 'sku', label: __( 'Manufacturer SKU', 'woo-supplier-sync' ) },
	{ key: 'ean', label: __( 'EAN / barcode', 'woo-supplier-sync' ) },
	{ key: 'quantity', label: __( 'Stock quantity', 'woo-supplier-sync' ) },
	{ key: 'available', label: __( 'Available flag', 'woo-supplier-sync' ) },
	{
		key: 'old_price',
		label: __( 'Recommended retail price', 'woo-supplier-sync' ),
	},
	{ key: 'currency', label: __( 'Currency', 'woo-supplier-sync' ) },
	{ key: 'category', label: __( 'Category', 'woo-supplier-sync' ) },
	{ key: 'images', label: __( 'Photos', 'woo-supplier-sync' ) },
	{ key: 'description', label: __( 'Description', 'woo-supplier-sync' ) },
	{ key: 'vendor', label: __( 'Brand', 'woo-supplier-sync' ) },
	{
		key: 'group_id',
		label: __( 'Model (joins variations)', 'woo-supplier-sync' ),
	},
	{
		key: 'external_id',
		label: __( 'Supplier item ID', 'woo-supplier-sync' ),
	},
	{ key: 'url', label: __( 'Supplier product page', 'woo-supplier-sync' ) },
];

const SELECTOR_FIELDS: Array< { key: string; label: string } > = [
	{
		key: 'product_links',
		label: __( 'Links to products on a listing page', 'woo-supplier-sync' ),
	},
	{
		key: 'next_page',
		label: __( 'Link to the next listing page', 'woo-supplier-sync' ),
	},
	{ key: 'name', label: __( 'Product title', 'woo-supplier-sync' ) },
	{ key: 'price', label: __( 'Price', 'woo-supplier-sync' ) },
	{ key: 'old_price', label: __( 'Old price', 'woo-supplier-sync' ) },
	{ key: 'sku', label: __( 'SKU', 'woo-supplier-sync' ) },
	{ key: 'ean', label: __( 'EAN', 'woo-supplier-sync' ) },
	{ key: 'quantity', label: __( 'Stock', 'woo-supplier-sync' ) },
	{ key: 'category', label: __( 'Breadcrumbs', 'woo-supplier-sync' ) },
	{ key: 'images', label: __( 'Photos', 'woo-supplier-sync' ) },
	{ key: 'description', label: __( 'Description', 'woo-supplier-sync' ) },
	{
		key: 'attribute_rows',
		label: __( 'Specification rows', 'woo-supplier-sync' ),
	},
	{
		key: 'attribute_name',
		label: __( 'Name cell inside a row', 'woo-supplier-sync' ),
	},
	{
		key: 'attribute_value',
		label: __( 'Value cell inside a row', 'woo-supplier-sync' ),
	},
];

const EMPTY: Supplier = {
	id: 0,
	name: '',
	type: 'csv',
	schedule: 'daily',
	enabled: true,
	last_run_id: null,
	can_edit: true,
	config: {
		source: { url: '', headers: {} },
		currency: '',
		mapping: {
			delimiter: 'auto',
			encoding: 'UTF-8',
			decimal: 'auto',
			columns: {},
			attribute_columns: [],
		},
		scraper: {
			start_urls: [],
			max_pages: 50,
			max_products: 5000,
			delay_ms: 300,
			selectors: {},
		},
		attributes: {},
		unmapped_attributes: 'keep',
		categories: {},
		unmapped_categories: 'create',
		update_fields: [],
		max_removal_percent: 30,
	},
};

type Patch = ( config: SupplierConfig ) => void;

export function SupplierEditor( {
	id,
	navigate,
}: {
	id: number | null;
	navigate: ( path: string ) => void;
} ) {
	const meta = useMeta();
	const [ supplier, setSupplier ] = useState< Supplier | null >(
		id === null
			? {
					...EMPTY,
					config: {
						...EMPTY.config,
						currency: meta.store_currency,
						update_fields: meta.update_fields,
					},
				}
			: null
	);
	const [ error, setError ] = useState< string | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ saved, setSaved ] = useState( false );
	const [ confirmDelete, setConfirmDelete ] = useState( false );
	const [ preview, setPreview ] = useState< Preview | null >( null );
	const [ previewError, setPreviewError ] = useState< string | null >( null );
	const [ previewing, setPreviewing ] = useState( false );

	useEffect( () => {
		if ( id !== null ) {
			api.get< Supplier >( `/suppliers/${ id }` )
				.then( setSupplier )
				.catch( ( e: Error ) => setError( e.message ) );
		}
	}, [ id ] );

	if ( error && ! supplier ) {
		return <ErrorNotice message={ error } />;
	}
	if ( ! supplier ) {
		return <Loading />;
	}

	const readOnly = ! meta.caps.manage;
	const s = supplier;
	const config = s.config;
	const type = s.type;

	const update = ( changes: Partial< Supplier > ) => {
		setSupplier( { ...s, ...changes } );
		setSaved( false );
	};
	const patch = ( fn: Patch ) => {
		const next = structuredClone( config );
		fn( next );
		update( { config: next } );
	};

	const save = async () => {
		setSaving( true );
		setError( null );
		try {
			const body = {
				name: s.name,
				type: s.type,
				schedule: s.schedule,
				enabled: s.enabled,
				config: s.config,
			};
			const result = s.id
				? await api.put< Supplier >( `/suppliers/${ s.id }`, body )
				: await api.post< Supplier >( '/suppliers', body );
			setSupplier( result );
			setSaved( true );
			if ( ! s.id ) {
				navigate( `suppliers/${ result.id }` );
			}
		} catch ( e ) {
			setError( e instanceof Error ? e.message : String( e ) );
		} finally {
			setSaving( false );
		}
	};

	const remove = async () => {
		try {
			await api.del( `/suppliers/${ s.id }` );
			navigate( 'suppliers' );
		} catch ( e ) {
			setError( e instanceof Error ? e.message : String( e ) );
			setConfirmDelete( false );
		}
	};

	const loadPreview = async () => {
		setPreviewing( true );
		setPreviewError( null );
		try {
			setPreview(
				await api.post< Preview >( '/suppliers/preview', {
					id: s.id || undefined,
					name: s.name || 'preview',
					type: s.type,
					config: s.config,
				} )
			);
		} catch ( e ) {
			setPreview( null );
			setPreviewError( e instanceof Error ? e.message : String( e ) );
		} finally {
			setPreviewing( false );
		}
	};

	const columns = preview?.columns ?? [];
	const tabular =
		type === 'csv' || type === 'xlsx' || type === 'json' || type === 'xml';

	return (
		<>
			<p className="wss-back">
				<a href="#/suppliers">
					{ __( 'All suppliers', 'woo-supplier-sync' ) }
				</a>
			</p>
			<PageHeader
				title={
					s.id ? s.name : __( 'New supplier', 'woo-supplier-sync' )
				}
				description={
					s.id
						? TYPE_LABELS[ type ]
						: __(
								'Point to the feed, check the preview, then save.',
								'woo-supplier-sync'
							)
				}
			/>
			<ReadOnlyBanner />

			<div className="wss-editor">
				<form
					className="wss-editor__form"
					onSubmit={ ( e ) => {
						e.preventDefault();
						save();
					} }
				>
					<fieldset disabled={ readOnly } className="wss-fieldset">
						<section className="wss-panel wss-form-section">
							<h2 className="wss-form-section__title">
								{ __( 'Supplier', 'woo-supplier-sync' ) }
							</h2>
							<div className="wss-grid-2">
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ __( 'Name', 'woo-supplier-sync' ) }
									value={ s.name }
									onChange={ ( name ) => update( { name } ) }
									required
								/>
								<SelectControl< string >
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ __(
										'Catalog format',
										'woo-supplier-sync'
									) }
									value={ type }
									options={ meta.types.map( ( t ) => ( {
										value: t,
										label: TYPE_LABELS[ t ],
									} ) ) }
									onChange={ ( value ) => {
										update( { type: value as FeedType } );
										setPreview( null );
									} }
								/>
								<SelectControl< string >
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ __(
										'Prices are in',
										'woo-supplier-sync'
									) }
									value={
										config.currency || meta.store_currency
									}
									options={ meta.currencies.map( ( c ) => ( {
										value: c,
										label: c,
									} ) ) }
									onChange={ ( value ) =>
										patch( ( c ) => ( c.currency = value ) )
									}
									help={ __(
										'Used when a row has no currency of its own. Exchange rates are on the Prices screen.',
										'woo-supplier-sync'
									) }
								/>
								<SelectControl< string >
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ __(
										'Sync automatically',
										'woo-supplier-sync'
									) }
									value={ s.schedule }
									options={ meta.schedules.map(
										( value ) => ( {
											value,
											label:
												SCHEDULE_LABELS[ value ] ??
												value,
										} )
									) }
									onChange={ ( schedule ) =>
										update( { schedule } )
									}
								/>
							</div>
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __( 'Active', 'woo-supplier-sync' ) }
								help={ __(
									'A paused supplier keeps its products but skips scheduled runs.',
									'woo-supplier-sync'
								) }
								checked={ s.enabled }
								onChange={ ( enabled ) =>
									update( { enabled } )
								}
							/>
						</section>

						<section className="wss-panel wss-form-section">
							<h2 className="wss-form-section__title">
								{ type === 'site'
									? __( 'Website', 'woo-supplier-sync' )
									: __(
											'Where the catalog is',
											'woo-supplier-sync'
										) }
							</h2>
							{ type === 'site' ? (
								<SiteSettings
									config={ config }
									patch={ patch }
								/>
							) : (
								<>
									<TextControl
										__next40pxDefaultSize
										__nextHasNoMarginBottom
										type="url"
										label={ __(
											'Feed URL',
											'woo-supplier-sync'
										) }
										placeholder="https://supplier.example/export/catalog.xml"
										value={ config.source.url }
										onChange={ ( url ) =>
											patch(
												( c ) => ( c.source.url = url )
											)
										}
									/>
									<PairsEditor
										title={ __(
											'Request headers',
											'woo-supplier-sync'
										) }
										help={ __(
											'For APIs that need a key, for example Authorization: Bearer …. Values are stored on the server and never shown again.',
											'woo-supplier-sync'
										) }
										fromLabel={ __(
											'Header',
											'woo-supplier-sync'
										) }
										toLabel={ __(
											'Value',
											'woo-supplier-sync'
										) }
										value={ config.source.headers ?? {} }
										onChange={ ( headers ) =>
											patch(
												( c ) =>
													( c.source.headers =
														headers as Record<
															string,
															string
														> )
											)
										}
									/>
								</>
							) }
						</section>

						<section className="wss-panel wss-form-section">
							<h2 className="wss-form-section__title">
								{ __(
									'Reading the feed',
									'woo-supplier-sync'
								) }
							</h2>
							{ type === 'yml' && (
								<p className="wss-muted">
									{ __(
										'YML is read as Yandex Market describes it: offers, categories, params, pictures and group_id for variations. Stock is taken from count, quantity or outlets.',
										'woo-supplier-sync'
									) }
								</p>
							) }
							{ type === 'site' && (
								<SelectorSettings
									config={ config }
									patch={ patch }
								/>
							) }
							{ tabular && (
								<FormatSettings
									type={ type }
									config={ config }
									patch={ patch }
								/>
							) }
							{ tabular && (
								<ColumnMapping
									type={ type }
									config={ config }
									patch={ patch }
									columns={ columns }
								/>
							) }
						</section>

						<section className="wss-panel wss-form-section">
							<h2 className="wss-form-section__title">
								{ __(
									'Attributes and categories',
									'woo-supplier-sync'
								) }
							</h2>
							<PairsEditor
								title={ __(
									'Rename attributes',
									'woo-supplier-sync'
								) }
								help={ __(
									'Match supplier names to your store attributes, e.g. Colour to Color. Leave the right side empty to drop an attribute.',
									'woo-supplier-sync'
								) }
								fromLabel={ __(
									'Supplier attribute',
									'woo-supplier-sync'
								) }
								toLabel={ __(
									'Store attribute',
									'woo-supplier-sync'
								) }
								suggestions={ meta.attributes }
								value={ config.attributes ?? {} }
								onChange={ ( attributes ) =>
									patch(
										( c ) =>
											( c.attributes =
												attributes as Record<
													string,
													string
												> )
									)
								}
							/>
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __(
									'Import attributes that are not in the list',
									'woo-supplier-sync'
								) }
								checked={
									config.unmapped_attributes !== 'skip'
								}
								onChange={ ( keep ) =>
									patch(
										( c ) =>
											( c.unmapped_attributes = keep
												? 'keep'
												: 'skip' )
									)
								}
							/>
							<PairsEditor
								title={ __(
									'Category mapping',
									'woo-supplier-sync'
								) }
								help={ __(
									'Supplier category (ID or path such as Power > Chargers) to a store category. Set the store side to 0 to skip that category.',
									'woo-supplier-sync'
								) }
								fromLabel={ __(
									'Supplier category',
									'woo-supplier-sync'
								) }
								toLabel={ __(
									'Store category',
									'woo-supplier-sync'
								) }
								suggestions={ categoryPaths( meta.categories ) }
								value={ Object.fromEntries(
									Object.entries(
										config.categories ?? {}
									).map( ( [ k, v ] ) => [ k, String( v ) ] )
								) }
								onChange={ ( categories ) =>
									patch(
										( c ) => ( c.categories = categories )
									)
								}
							/>
							<div className="wss-grid-2">
								<SelectControl< string >
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ __(
										'Categories that are not mapped',
										'woo-supplier-sync'
									) }
									value={ String(
										config.unmapped_categories ?? 'create'
									) }
									options={ [
										{
											value: 'create',
											label: __(
												'Create them in the store',
												'woo-supplier-sync'
											),
										},
										{
											value: 'skip',
											label: __(
												'Do not import those products',
												'woo-supplier-sync'
											),
										},
										...meta.categories.map( ( c ) => ( {
											value: String( c.id ),
											label: sprintf(
												/* translators: %s: category. */ __(
													'Put into "%s"',
													'woo-supplier-sync'
												),
												c.name
											),
										} ) ),
									] }
									onChange={ ( value ) =>
										patch(
											( c ) =>
												( c.unmapped_categories =
													/^\d+$/.test( value )
														? Number( value )
														: ( value as
																| 'create'
																| 'skip' ) )
										)
									}
								/>
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ __(
										'Create new categories under',
										'woo-supplier-sync'
									) }
									placeholder={ __(
										'Store root',
										'woo-supplier-sync'
									) }
									value={ String(
										config.category_root ?? ''
									) }
									onChange={ ( value ) =>
										patch(
											( c ) => ( c.category_root = value )
										)
									}
								/>
							</div>
						</section>

						<section className="wss-panel wss-form-section">
							<h2 className="wss-form-section__title">
								{ __(
									'Updates and safety',
									'woo-supplier-sync'
								) }
							</h2>
							<p className="wss-muted">
								{ __(
									'Fields this supplier may change on products that already exist. New products always get everything.',
									'woo-supplier-sync'
								) }
							</p>
							<div className="wss-checks">
								{ meta.update_fields.map( ( field ) => (
									<CheckboxControl
										key={ field }
										__nextHasNoMarginBottom
										label={ FIELD_LABELS[ field ] ?? field }
										checked={ (
											config.update_fields ??
											meta.update_fields
										).includes( field ) }
										onChange={ ( checked ) =>
											patch( ( c ) => {
												const current = new Set(
													c.update_fields ??
														meta.update_fields
												);
												if ( checked ) {
													current.add( field );
												} else {
													current.delete( field );
												}
												c.update_fields = [
													...current,
												];
											} )
										}
									/>
								) ) }
							</div>
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								type="number"
								min={ 0 }
								max={ 100 }
								label={ __(
									'Stop if more than this share of offers disappears at once, %',
									'woo-supplier-sync'
								) }
								help={ __(
									'A broken or half-empty feed should not take your catalog off sale. Such a run logs a warning and removes nothing.',
									'woo-supplier-sync'
								) }
								value={ String(
									config.max_removal_percent ?? 30
								) }
								onChange={ ( value ) =>
									patch(
										( c ) =>
											( c.max_removal_percent =
												Number( value ) )
									)
								}
							/>
						</section>
					</fieldset>

					{ ! readOnly && (
						<div className="wss-savebar">
							{ error && (
								<p className="wss-inline-error" role="alert">
									{ error }
								</p>
							) }
							{ saved && (
								<p className="wss-saved" role="status">
									{ __( 'Saved', 'woo-supplier-sync' ) }
								</p>
							) }
							{ s.id > 0 && (
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () => setConfirmDelete( true ) }
								>
									{ __(
										'Delete supplier',
										'woo-supplier-sync'
									) }
								</Button>
							) }
							<Button
								variant="primary"
								type="submit"
								isBusy={ saving }
								disabled={ saving || ! s.name }
							>
								{ s.id
									? __( 'Save changes', 'woo-supplier-sync' )
									: __(
											'Create supplier',
											'woo-supplier-sync'
										) }
							</Button>
						</div>
					) }
				</form>

				<aside
					className="wss-editor__preview"
					aria-labelledby="wss-preview-title"
				>
					<div className="wss-panel">
						<div className="wss-section__head">
							<h2
								id="wss-preview-title"
								className="wss-form-section__title"
							>
								{ __( 'Feed preview', 'woo-supplier-sync' ) }
							</h2>
							{ ! readOnly && (
								<Button
									variant="secondary"
									onClick={ loadPreview }
									isBusy={ previewing }
									disabled={ previewing }
								>
									{ preview
										? __(
												'Read again',
												'woo-supplier-sync'
											)
										: __(
												'Read first rows',
												'woo-supplier-sync'
											) }
								</Button>
							) }
						</div>
						{ readOnly && (
							<p className="wss-muted">
								{ __(
									'The preview downloads the feed, so it is available to store managers only.',
									'woo-supplier-sync'
								) }
							</p>
						) }
						{ ! readOnly &&
							! preview &&
							! previewError &&
							! previewing && (
								<p className="wss-muted">
									{ __(
										'Downloads the feed with the settings on the left, unsaved ones included, and shows what the first products would look like.',
										'woo-supplier-sync'
									) }
								</p>
							) }
						{ previewing && (
							<Loading
								label={ __(
									'Downloading the feed…',
									'woo-supplier-sync'
								) }
							/>
						) }
						{ previewError && (
							<Notice status="error" isDismissible={ false }>
								{ previewError }
							</Notice>
						) }
						{ preview && (
							<PreviewResult
								preview={ preview }
								currency={ meta.store_currency }
							/>
						) }
					</div>
				</aside>
			</div>

			{ confirmDelete && (
				<Modal
					title={ __( 'Delete this supplier?', 'woo-supplier-sync' ) }
					onRequestClose={ () => setConfirmDelete( false ) }
					size="small"
				>
					<p>
						{ __(
							'Its products stay in the store. They just stop getting price and stock updates from this supplier.',
							'woo-supplier-sync'
						) }
					</p>
					<div className="wss-modal-actions">
						<Button
							variant="tertiary"
							onClick={ () => setConfirmDelete( false ) }
						>
							{ __( 'Keep supplier', 'woo-supplier-sync' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							onClick={ remove }
						>
							{ __( 'Delete supplier', 'woo-supplier-sync' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}

function categoryPaths(
	categories: Array< { id: number; name: string; parent: number } >
): string[] {
	const byId = new Map( categories.map( ( c ) => [ c.id, c ] ) );
	return categories
		.map( ( c ) => {
			const names = [ c.name ];
			let parent = byId.get( c.parent );
			while ( parent ) {
				names.unshift( parent.name );
				parent = byId.get( parent.parent );
			}
			return names.join( ' > ' );
		} )
		.sort();
}

function FormatSettings( {
	type,
	config,
	patch,
}: {
	type: FeedType;
	config: SupplierConfig;
	patch: ( fn: Patch ) => void;
} ) {
	const mapping = config.mapping ?? {};
	return (
		<div className="wss-grid-3">
			{ type === 'csv' && (
				<>
					<SelectControl< string >
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Separator', 'woo-supplier-sync' ) }
						value={ String( mapping.delimiter ?? 'auto' ) }
						options={ [
							{
								value: 'auto',
								label: __( 'Detect', 'woo-supplier-sync' ),
							},
							{
								value: ';',
								label: __( 'Semicolon ;', 'woo-supplier-sync' ),
							},
							{
								value: ',',
								label: __( 'Comma ,', 'woo-supplier-sync' ),
							},
							{
								value: '\t',
								label: __( 'Tab', 'woo-supplier-sync' ),
							},
							{
								value: '|',
								label: __( 'Pipe |', 'woo-supplier-sync' ),
							},
						] }
						onChange={ ( value ) =>
							patch(
								( c ) =>
									( c.mapping = {
										...c.mapping,
										delimiter: value,
									} )
							)
						}
					/>
					<SelectControl< string >
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Encoding', 'woo-supplier-sync' ) }
						value={ String( mapping.encoding ?? 'UTF-8' ) }
						options={ [
							{ value: 'UTF-8', label: 'UTF-8' },
							{ value: 'windows-1251', label: 'Windows-1251' },
							{ value: 'ISO-8859-1', label: 'ISO-8859-1' },
						] }
						onChange={ ( value ) =>
							patch(
								( c ) =>
									( c.mapping = {
										...c.mapping,
										encoding: value,
									} )
							)
						}
					/>
				</>
			) }
			{ ( type === 'csv' || type === 'xlsx' ) && (
				<SelectControl< string >
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __(
						'Decimal mark in prices',
						'woo-supplier-sync'
					) }
					value={ String( mapping.decimal ?? 'auto' ) }
					options={ [
						{
							value: 'auto',
							label: __( 'Detect', 'woo-supplier-sync' ),
						},
						{ value: ',', label: '1 249,50' },
						{ value: '.', label: '1,249.50' },
					] }
					onChange={ ( value ) =>
						patch(
							( c ) =>
								( c.mapping = { ...c.mapping, decimal: value } )
						)
					}
				/>
			) }
			{ type === 'json' && (
				<>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Path to the product list',
							'woo-supplier-sync'
						) }
						placeholder="data.items"
						value={ mapping.items_path ?? '' }
						onChange={ ( value ) =>
							patch(
								( c ) =>
									( c.mapping = {
										...c.mapping,
										items_path: value,
									} )
							)
						}
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Object with attributes',
							'woo-supplier-sync'
						) }
						placeholder="specs"
						value={ mapping.attribute_prefix ?? '' }
						onChange={ ( value ) =>
							patch(
								( c ) =>
									( c.mapping = {
										...c.mapping,
										attribute_prefix: value,
									} )
							)
						}
					/>
				</>
			) }
			{ type === 'xml' && (
				<>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Product element', 'woo-supplier-sync' ) }
						placeholder="product"
						value={ mapping.item_tag ?? '' }
						onChange={ ( value ) =>
							patch(
								( c ) =>
									( c.mapping = {
										...c.mapping,
										item_tag: value,
									} )
							)
						}
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Attribute elements (XPath)',
							'woo-supplier-sync'
						) }
						placeholder="specs/spec"
						value={ mapping.attributes_path ?? '' }
						onChange={ ( value ) =>
							patch(
								( c ) =>
									( c.mapping = {
										...c.mapping,
										attributes_path: value,
									} )
							)
						}
					/>
				</>
			) }
		</div>
	);
}

function ColumnMapping( {
	type,
	config,
	patch,
	columns,
}: {
	type: FeedType;
	config: SupplierConfig;
	patch: ( fn: Patch ) => void;
	columns: string[];
} ) {
	const key = type === 'xml' ? 'fields' : 'columns';
	const values =
		( type === 'xml' ? config.mapping?.fields : config.mapping?.columns ) ??
		{};
	const used = new Set( Object.values( values ) );
	const attributeColumns = config.mapping?.attribute_columns ?? [];

	const set = ( field: string, value: string ) =>
		patch( ( c ) => {
			const next = { ...( c.mapping?.[ key ] ?? {} ) };
			if ( value ) {
				next[ field ] = value;
			} else {
				delete next[ field ];
			}
			c.mapping = { ...c.mapping, [ key ]: next };
		} );

	return (
		<>
			<h3 className="wss-subtitle">
				{ type === 'xml'
					? __(
							'Where each field is (XPath inside the product element)',
							'woo-supplier-sync'
						)
					: __( 'Which column holds what', 'woo-supplier-sync' ) }
			</h3>
			{ columns.length === 0 && type !== 'xml' && (
				<p className="wss-muted">
					{ __(
						'Read the first rows to pick columns from a list instead of typing their names.',
						'woo-supplier-sync'
					) }
				</p>
			) }
			<div className="wss-mapping">
				{ COLUMN_FIELDS.map( ( field ) =>
					columns.length > 0 && type !== 'xml' ? (
						<SelectControl< string >
							key={ field.key }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={
								field.label + ( field.required ? ' *' : '' )
							}
							value={ values[ field.key ] ?? '' }
							options={ [
								{
									value: '',
									label: __(
										'Not in the feed',
										'woo-supplier-sync'
									),
								},
								...columns.map( ( c ) => ( {
									value: c,
									label: c,
								} ) ),
								...( values[ field.key ] &&
								! columns.includes( values[ field.key ]! )
									? [
											{
												value: values[ field.key ]!,
												label: values[ field.key ]!,
											},
										]
									: [] ),
							] }
							onChange={ ( value ) => set( field.key, value ) }
						/>
					) : (
						<TextControl
							key={ field.key }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={
								field.label + ( field.required ? ' *' : '' )
							}
							value={ values[ field.key ] ?? '' }
							onChange={ ( value ) => set( field.key, value ) }
						/>
					)
				) }
			</div>
			{ ( type === 'csv' || type === 'xlsx' ) && (
				<>
					<h3 className="wss-subtitle">
						{ __(
							'Columns that become attributes',
							'woo-supplier-sync'
						) }
					</h3>
					{ columns.length > 0 ? (
						<div className="wss-checks">
							{ columns
								.filter(
									( c ) =>
										! used.has( c ) ||
										attributeColumns.includes( c )
								)
								.map( ( column ) => (
									<CheckboxControl
										key={ column }
										__nextHasNoMarginBottom
										label={ column }
										checked={ attributeColumns.includes(
											column
										) }
										onChange={ ( checked ) =>
											patch( ( c ) => {
												const list = new Set(
													c.mapping
														?.attribute_columns ??
														[]
												);
												if ( checked ) {
													list.add( column );
												} else {
													list.delete( column );
												}
												c.mapping = {
													...c.mapping,
													attribute_columns: [
														...list,
													],
												};
											} )
										}
									/>
								) ) }
						</div>
					) : (
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __(
								'Column names, comma separated',
								'woo-supplier-sync'
							) }
							value={ attributeColumns.join( ', ' ) }
							onChange={ ( value ) =>
								patch(
									( c ) =>
										( c.mapping = {
											...c.mapping,
											attribute_columns: value
												.split( ',' )
												.map( ( v ) => v.trim() )
												.filter( Boolean ),
										} )
								)
							}
						/>
					) }
				</>
			) }
		</>
	);
}

function SiteSettings( {
	config,
	patch,
}: {
	config: SupplierConfig;
	patch: ( fn: Patch ) => void;
} ) {
	const scraper = config.scraper ?? {};
	return (
		<>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __(
					'Listing pages to start from, one per line',
					'woo-supplier-sync'
				) }
				value={ ( scraper.start_urls ?? [] ).join( '\n' ) }
				onChange={ ( value ) =>
					patch( ( c ) => {
						const urls = value
							.split( /\n+/ )
							.map( ( v ) => v.trim() )
							.filter( Boolean );
						c.scraper = { ...c.scraper, start_urls: urls };
						c.source.url = urls[ 0 ] ?? '';
					} )
				}
				rows={ 3 }
			/>
			<div className="wss-grid-3">
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="number"
					min={ 1 }
					label={ __( 'Listing pages, max', 'woo-supplier-sync' ) }
					value={ String( scraper.max_pages ?? 50 ) }
					onChange={ ( v ) =>
						patch(
							( c ) =>
								( c.scraper = {
									...c.scraper,
									max_pages: Number( v ),
								} )
						)
					}
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="number"
					min={ 1 }
					label={ __( 'Products, max', 'woo-supplier-sync' ) }
					value={ String( scraper.max_products ?? 5000 ) }
					onChange={ ( v ) =>
						patch(
							( c ) =>
								( c.scraper = {
									...c.scraper,
									max_products: Number( v ),
								} )
						)
					}
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="number"
					min={ 0 }
					label={ __(
						'Pause between pages, ms',
						'woo-supplier-sync'
					) }
					value={ String( scraper.delay_ms ?? 300 ) }
					onChange={ ( v ) =>
						patch(
							( c ) =>
								( c.scraper = {
									...c.scraper,
									delay_ms: Number( v ),
								} )
						)
					}
				/>
			</div>
			<p className="wss-muted">
				{ __(
					'The crawler stays on the same site, follows robots.txt and identifies itself in the User-Agent.',
					'woo-supplier-sync'
				) }
			</p>
		</>
	);
}

function SelectorSettings( {
	config,
	patch,
}: {
	config: SupplierConfig;
	patch: ( fn: Patch ) => void;
} ) {
	const selectors = config.scraper?.selectors ?? {};
	return (
		<>
			<p className="wss-muted">
				{ __(
					'CSS selectors. Add @attribute to read an attribute instead of text, for example .gallery img@src.',
					'woo-supplier-sync'
				) }
			</p>
			<div className="wss-mapping">
				{ SELECTOR_FIELDS.map( ( field ) => (
					<TextControl
						key={ field.key }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						className="wss-code-input"
						label={ field.label }
						value={ selectors[ field.key ] ?? '' }
						onChange={ ( value ) =>
							patch(
								( c ) =>
									( c.scraper = {
										...c.scraper,
										selectors: {
											...c.scraper?.selectors,
											[ field.key ]: value,
										},
									} )
							)
						}
					/>
				) ) }
			</div>
		</>
	);
}

function PreviewResult( {
	preview,
	currency,
}: {
	preview: Preview;
	currency: string;
} ) {
	return (
		<>
			{ preview.errors.length > 0 && (
				<ul className="wss-preview-errors">
					{ preview.errors.map( ( e ) => (
						<li key={ e.reference }>
							<strong>{ e.reference }:</strong> { e.message }
						</li>
					) ) }
				</ul>
			) }
			{ preview.offers.length === 0 && (
				<p className="wss-muted">
					{ __(
						'No products found with these settings.',
						'woo-supplier-sync'
					) }
				</p>
			) }
			<ol className="wss-preview">
				{ preview.offers.map( ( offer, index ) => {
					const images =
						( offer.images as string[] | undefined ) ?? [];
					const attributes = Object.entries(
						( offer.attributes as
							Record< string, string > | undefined ) ?? {}
					);
					return (
						<li key={ index } className="wss-preview__item">
							<div
								className={ `wss-preview__thumb${ images.length ? '' : ' is-empty' }` }
								title={ sprintf(
									/* translators: %d: number of photos. */
									_n(
										'%d photo',
										'%d photos',
										images.length,
										'woo-supplier-sync'
									),
									images.length
								) }
							>
								<Icon icon={ image } size={ 20 } />
								<span>{ images.length }</span>
							</div>
							<div className="wss-preview__body">
								<p className="wss-preview__name">
									{ offer.name }
								</p>
								<p className="wss-preview__codes">
									{ [ offer.sku, offer.ean ]
										.filter( Boolean )
										.join( ' / ' ) ||
										__(
											'no SKU or EAN',
											'woo-supplier-sync'
										) }
									{ offer.category_path ? (
										<span className="wss-preview__category">
											{ ' ' }
											{ String( offer.category_path ) }
										</span>
									) : null }
								</p>
								{ attributes.length > 0 && (
									<p className="wss-preview__attrs">
										{ attributes
											.slice( 0, 4 )
											.map(
												( [ k, v ] ) => `${ k }: ${ v }`
											)
											.join( ', ' ) }
									</p>
								) }
							</div>
							<div className="wss-preview__price">
								<span className="wss-muted">
									{ String( offer.price ?? '—' ) }{ ' ' }
									{ String( offer.currency ?? '' ) }
								</span>
								<strong>
									{ offer.price_error
										? offer.price_error
										: money(
												offer.retail ?? null,
												currency
											) }
								</strong>
								<span
									className={ `wss-stock is-${ String( offer.availability ) }` }
								>
									{ stockLabel( offer ) }
								</span>
							</div>
						</li>
					);
				} ) }
			</ol>
			{ preview.rows.length > 0 && (
				<details className="wss-raw">
					<summary>
						{ __(
							'Raw rows as the supplier sent them',
							'woo-supplier-sync'
						) }
					</summary>
					<div className="wss-table-wrap">
						<table className="wss-table is-raw">
							<thead>
								<tr>
									{ preview.columns.map( ( c ) => (
										<th key={ c } scope="col">
											{ c }
										</th>
									) ) }
								</tr>
							</thead>
							<tbody>
								{ preview.rows.map( ( row, i ) => (
									<tr key={ i }>
										{ preview.columns.map( ( c ) => (
											<td key={ c }>
												{ String( row[ c ] ?? '' ) }
											</td>
										) ) }
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				</details>
			) }
		</>
	);
}

function stockLabel( offer: Preview[ 'offers' ][ number ] ): string {
	if ( offer.quantity !== null && offer.quantity !== undefined ) {
		return sprintf(
			/* translators: %d: quantity. */
			__( '%d in stock', 'woo-supplier-sync' ),
			Number( offer.quantity )
		);
	}
	if ( offer.availability === 'on_order' ) {
		return __( 'on order', 'woo-supplier-sync' );
	}
	return offer.availability === 'out_of_stock'
		? __( 'out of stock', 'woo-supplier-sync' )
		: __( 'in stock', 'woo-supplier-sync' );
}
