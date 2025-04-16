import {
	Button,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { arrowDown, arrowUp, closeSmall } from '@wordpress/icons';
import { api } from '../api';
import {
	ErrorNotice,
	Loading,
	PageHeader,
	ReadOnlyBanner,
	useMeta,
} from '../components';
import { money, ROUNDING_LABELS } from '../format';
import { useAsync } from '../hooks';
import type { PricePreview, PriceRule, Settings, Supplier } from '../types';

type Option = { value: string; label: string };

const blankRule = ( priority: number ): PriceRule => ( {
	id: '',
	label: '',
	supplier_id: null,
	category_id: null,
	min_cost: null,
	max_cost: null,
	markup_percent: 30,
	markup_fixed: 0,
	min_margin_percent: 10,
	rounding: 'ending_99',
	priority,
} );

const toNumber = ( value: string ): number | null =>
	value === '' ? null : Number( value );

export function Rules() {
	const meta = useMeta();
	const settings = useAsync( () => api.get< Settings >( '/settings' ), [] );
	const suppliers = useAsync(
		() => api.get< Supplier[] >( '/suppliers' ),
		[]
	);
	const [ draft, setDraft ] = useState< Settings | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState< {
		status: 'success' | 'error';
		text: string;
	} | null >( null );
	const readOnly = ! meta.caps.manage;

	useEffect( () => {
		if ( settings.data ) {
			setDraft( settings.data );
		}
	}, [ settings.data ] );

	if ( settings.error ) {
		return (
			<ErrorNotice
				message={ settings.error }
				onRetry={ settings.reload }
			/>
		);
	}
	if ( ! draft ) {
		return <Loading />;
	}

	const setRule = ( index: number, changes: Partial< PriceRule > ) =>
		setDraft( {
			...draft,
			rules: draft.rules.map( ( r, i ) =>
				i === index ? { ...r, ...changes } : r
			),
		} );

	const move = ( index: number, delta: number ) => {
		const rules = [ ...draft.rules ];
		const [ rule ] = rules.splice( index, 1 );
		rules.splice( index + delta, 0, rule! );
		setDraft( {
			...draft,
			rules: rules.map( ( r, i ) => ( {
				...r,
				priority: ( i + 1 ) * 10,
			} ) ),
		} );
	};

	const save = async () => {
		setSaving( true );
		setNotice( null );
		try {
			const result = await api.put< Settings >( '/settings', {
				rates: draft.rates,
				default_rule: draft.default_rule,
				rules: draft.rules,
			} );
			setDraft( result );
			setNotice( {
				status: 'success',
				text: __(
					'Prices saved. The next run of each supplier recalculates all of its products.',
					'woo-supplier-sync'
				),
			} );
		} catch ( e ) {
			setNotice( {
				status: 'error',
				text: e instanceof Error ? e.message : String( e ),
			} );
		} finally {
			setSaving( false );
		}
	};

	const options = {
		suppliers: [
			{ value: '', label: __( 'Any supplier', 'woo-supplier-sync' ) },
			...( suppliers.data ?? [] ).map( ( s ) => ( {
				value: String( s.id ),
				label: s.name,
			} ) ),
		],
		categories: [
			{ value: '', label: __( 'Any category', 'woo-supplier-sync' ) },
			...meta.categories.map( ( c ) => ( {
				value: String( c.id ),
				label: c.name,
			} ) ),
		],
		roundings: meta.roundings.map( ( r ) => ( {
			value: r,
			label: ROUNDING_LABELS[ r ] ?? r,
		} ) ),
	};

	return (
		<>
			<PageHeader
				title={ __( 'Prices', 'woo-supplier-sync' ) }
				description={ __(
					'Retail price = purchase price in your currency + markup, never below the minimum margin, then rounded up. The most specific matching rule wins: supplier, then category, then price range.',
					'woo-supplier-sync'
				) }
			/>
			<ReadOnlyBanner />

			<div className="wss-rules">
				<fieldset
					disabled={ readOnly }
					className="wss-fieldset wss-rules__main"
				>
					<section className="wss-panel wss-form-section">
						<h2 className="wss-form-section__title">
							{ __( 'Markup rules', 'woo-supplier-sync' ) }
						</h2>
						<div className="wss-table-wrap">
							<table className="wss-table wss-rule-table">
								<thead>
									<tr>
										<th scope="col">
											{ __(
												'Rule',
												'woo-supplier-sync'
											) }
										</th>
										<th scope="col">
											{ __(
												'Applies to',
												'woo-supplier-sync'
											) }
										</th>
										<th scope="col">
											{ sprintf(
												/* translators: %s: currency code. */
												__(
													'Cost range, %s',
													'woo-supplier-sync'
												),
												meta.store_currency
											) }
										</th>
										<th scope="col">
											{ __(
												'Markup',
												'woo-supplier-sync'
											) }
										</th>
										<th scope="col">
											{ __(
												'Rounding',
												'woo-supplier-sync'
											) }
										</th>
										<th scope="col">
											<span className="screen-reader-text">
												{ __(
													'Order',
													'woo-supplier-sync'
												) }
											</span>
										</th>
									</tr>
								</thead>
								<tbody>
									{ draft.rules.map( ( rule, index ) => (
										<RuleRow
											key={ index }
											rule={ rule }
											options={ options }
											isFirst={ index === 0 }
											isLast={
												index === draft.rules.length - 1
											}
											onChange={ ( changes ) =>
												setRule( index, changes )
											}
											onMove={ ( delta ) =>
												move( index, delta )
											}
											onDelete={ () =>
												setDraft( {
													...draft,
													rules: draft.rules.filter(
														( _, i ) => i !== index
													),
												} )
											}
										/>
									) ) }
									<tr className="wss-rule-table__default">
										<th scope="row">
											{ __(
												'Everything else',
												'woo-supplier-sync'
											) }
										</th>
										<td className="wss-muted">
											{ __(
												'Used when no rule matches',
												'woo-supplier-sync'
											) }
										</td>
										<td />
										<td>
											<MarkupFields
												rule={ draft.default_rule }
												onChange={ ( changes ) =>
													setDraft( {
														...draft,
														default_rule: {
															...draft.default_rule,
															...changes,
														},
													} )
												}
											/>
										</td>
										<td>
											<NativeSelect
												label={ __(
													'Rounding',
													'woo-supplier-sync'
												) }
												value={
													draft.default_rule
														.rounding ?? 'none'
												}
												options={ options.roundings }
												onChange={ ( rounding ) =>
													setDraft( {
														...draft,
														default_rule: {
															...draft.default_rule,
															rounding,
														},
													} )
												}
											/>
										</td>
										<td />
									</tr>
								</tbody>
							</table>
						</div>
						<Button
							variant="secondary"
							onClick={ () =>
								setDraft( {
									...draft,
									rules: [
										...draft.rules,
										blankRule(
											( draft.rules.length + 1 ) * 10
										),
									],
								} )
							}
						>
							{ __( 'Add rule', 'woo-supplier-sync' ) }
						</Button>
					</section>

					<Rates draft={ draft } setDraft={ setDraft } />

					{ ! readOnly && (
						<div className="wss-savebar">
							{ notice && (
								<Notice
									status={ notice.status }
									isDismissible
									onRemove={ () => setNotice( null ) }
								>
									{ notice.text }
								</Notice>
							) }
							<Button
								variant="primary"
								onClick={ save }
								isBusy={ saving }
								disabled={ saving }
							>
								{ __( 'Save prices', 'woo-supplier-sync' ) }
							</Button>
						</div>
					) }
				</fieldset>

				<Calculator
					draft={ draft }
					suppliers={ suppliers.data ?? [] }
				/>
			</div>
		</>
	);
}

interface RuleRowProps {
	rule: PriceRule;
	options: { suppliers: Option[]; categories: Option[]; roundings: Option[] };
	isFirst: boolean;
	isLast: boolean;
	onChange: ( changes: Partial< PriceRule > ) => void;
	onMove: ( delta: number ) => void;
	onDelete: () => void;
}

function RuleRow( {
	rule,
	options,
	isFirst,
	isLast,
	onChange,
	onMove,
	onDelete,
}: RuleRowProps ) {
	return (
		<tr>
			<td>
				<input
					className="wss-input"
					aria-label={ __( 'Rule name', 'woo-supplier-sync' ) }
					placeholder={ __( 'Rule name', 'woo-supplier-sync' ) }
					value={ rule.label }
					onChange={ ( e ) => onChange( { label: e.target.value } ) }
				/>
			</td>
			<td className="wss-rule-table__scope">
				<NativeSelect
					label={ __( 'Supplier', 'woo-supplier-sync' ) }
					value={ String( rule.supplier_id ?? '' ) }
					options={ options.suppliers }
					onChange={ ( v ) =>
						onChange( { supplier_id: toNumber( v ) } )
					}
				/>
				<NativeSelect
					label={ __( 'Category', 'woo-supplier-sync' ) }
					value={ String( rule.category_id ?? '' ) }
					options={ options.categories }
					onChange={ ( v ) =>
						onChange( { category_id: toNumber( v ) } )
					}
				/>
			</td>
			<td>
				<span className="wss-range">
					<NumberField
						label={ __( 'Cost from', 'woo-supplier-sync' ) }
						placeholder={ __( 'from', 'woo-supplier-sync' ) }
						step="0.01"
						value={ rule.min_cost }
						onChange={ ( v ) => onChange( { min_cost: v } ) }
					/>
					<NumberField
						label={ __( 'Cost up to', 'woo-supplier-sync' ) }
						placeholder={ __( 'up to', 'woo-supplier-sync' ) }
						step="0.01"
						value={ rule.max_cost }
						onChange={ ( v ) => onChange( { max_cost: v } ) }
					/>
				</span>
			</td>
			<td>
				<MarkupFields rule={ rule } onChange={ onChange } />
			</td>
			<td>
				<NativeSelect
					label={ __( 'Rounding', 'woo-supplier-sync' ) }
					value={ rule.rounding }
					options={ options.roundings }
					onChange={ ( rounding ) => onChange( { rounding } ) }
				/>
			</td>
			<td className="wss-rule-table__tools">
				<Button
					icon={ arrowUp }
					size="small"
					label={ __( 'Move up', 'woo-supplier-sync' ) }
					disabled={ isFirst }
					onClick={ () => onMove( -1 ) }
				/>
				<Button
					icon={ arrowDown }
					size="small"
					label={ __( 'Move down', 'woo-supplier-sync' ) }
					disabled={ isLast }
					onClick={ () => onMove( 1 ) }
				/>
				<Button
					icon={ closeSmall }
					size="small"
					isDestructive
					label={ __( 'Delete rule', 'woo-supplier-sync' ) }
					onClick={ onDelete }
				/>
			</td>
		</tr>
	);
}

function MarkupFields( {
	rule,
	onChange,
}: {
	rule: Partial< PriceRule >;
	onChange: ( changes: Partial< PriceRule > ) => void;
} ) {
	return (
		<span className="wss-markup">
			<NumberField
				label={ __( 'Markup, %', 'woo-supplier-sync' ) }
				suffix={ __( '% markup', 'woo-supplier-sync' ) }
				value={ rule.markup_percent ?? 0 }
				onChange={ ( v ) => onChange( { markup_percent: v ?? 0 } ) }
			/>
			<NumberField
				label={ __( 'Minimum margin, %', 'woo-supplier-sync' ) }
				suffix={ __( '% min. margin', 'woo-supplier-sync' ) }
				value={ rule.min_margin_percent ?? 0 }
				onChange={ ( v ) => onChange( { min_margin_percent: v ?? 0 } ) }
			/>
		</span>
	);
}

interface NumberFieldProps {
	label: string;
	value: number | null | undefined;
	onChange: ( value: number | null ) => void;
	suffix?: string;
	placeholder?: string;
	step?: string;
}

function NumberField( {
	label,
	value,
	onChange,
	suffix,
	placeholder,
	step = '0.1',
}: NumberFieldProps ) {
	return (
		<span className="wss-markup__item">
			<input
				className="wss-input is-num"
				type="number"
				step={ step }
				aria-label={ label }
				placeholder={ placeholder }
				value={ value ?? '' }
				onChange={ ( e ) => onChange( toNumber( e.target.value ) ) }
			/>
			{ suffix && <span aria-hidden="true">{ suffix }</span> }
		</span>
	);
}

function NativeSelect( {
	label,
	value,
	options,
	onChange,
}: {
	label: string;
	value: string;
	options: Option[];
	onChange: ( value: string ) => void;
} ) {
	return (
		<select
			className="wss-input"
			aria-label={ label }
			value={ value }
			onChange={ ( e ) => onChange( e.target.value ) }
		>
			{ options.map( ( o ) => (
				<option key={ o.value } value={ o.value }>
					{ o.label }
				</option>
			) ) }
		</select>
	);
}

function Rates( {
	draft,
	setDraft,
}: {
	draft: Settings;
	setDraft: ( settings: Settings ) => void;
} ) {
	const meta = useMeta();
	const available = meta.currencies.filter(
		( c ) => c !== meta.store_currency && ! ( c in draft.rates )
	);

	return (
		<section className="wss-panel wss-form-section">
			<h2 className="wss-form-section__title">
				{ __( 'Exchange rates', 'woo-supplier-sync' ) }
			</h2>
			<p className="wss-muted">
				{ sprintf(
					/* translators: %s: store currency code. */
					__(
						'How much one unit of a supplier currency costs in %s. Update them when your bank rate moves.',
						'woo-supplier-sync'
					),
					meta.store_currency
				) }
			</p>
			<div className="wss-rates">
				{ Object.entries( draft.rates ).map( ( [ code, rate ] ) => (
					<div className="wss-rate" key={ code }>
						<span className="wss-rate__pair">1 { code } =</span>
						<NumberField
							label={ sprintf(
								/* translators: %s: currency code. */
								__( 'Rate for %s', 'woo-supplier-sync' ),
								code
							) }
							step="0.0001"
							value={ rate }
							onChange={ ( v ) =>
								setDraft( {
									...draft,
									rates: { ...draft.rates, [ code ]: v ?? 0 },
								} )
							}
						/>
						<span>{ meta.store_currency }</span>
						<Button
							icon={ closeSmall }
							size="small"
							label={ sprintf(
								/* translators: %s: currency code. */
								__( 'Remove %s', 'woo-supplier-sync' ),
								code
							) }
							onClick={ () => {
								const rates = { ...draft.rates };
								delete rates[ code ];
								setDraft( { ...draft, rates } );
							} }
						/>
					</div>
				) ) }
				<SelectControl< string >
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Add a currency', 'woo-supplier-sync' ) }
					value=""
					options={ [
						{
							value: '',
							label: __( 'Choose…', 'woo-supplier-sync' ),
						},
						...available.map( ( c ) => ( { value: c, label: c } ) ),
					] }
					onChange={ ( code ) =>
						code &&
						setDraft( {
							...draft,
							rates: { ...draft.rates, [ code ]: 1 },
						} )
					}
				/>
			</div>
		</section>
	);
}

function Calculator( {
	draft,
	suppliers,
}: {
	draft: Settings;
	suppliers: Supplier[];
} ) {
	const meta = useMeta();
	const [ input, setInput ] = useState( {
		cost: '2450',
		currency: Object.keys( draft.rates )[ 0 ] ?? meta.store_currency,
		supplier_id: '',
		category_id: '',
		old_price: '',
	} );
	const [ result, setResult ] = useState< PricePreview | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		const timer = window.setTimeout( async () => {
			try {
				setResult(
					await api.post< PricePreview >( '/pricing/preview', {
						...input,
						cost: Number( input.cost ),
						rules: draft.rules,
						default_rule: draft.default_rule,
						rates: draft.rates,
					} )
				);
				setError( null );
			} catch ( e ) {
				setResult( null );
				setError( e instanceof Error ? e.message : String( e ) );
			}
		}, 250 );
		return () => window.clearTimeout( timer );
	}, [ input, draft ] );

	const set = ( key: keyof typeof input ) => ( value: string ) =>
		setInput( { ...input, [ key ]: value } );

	return (
		<aside className="wss-panel wss-calc" aria-labelledby="wss-calc-title">
			<h2 id="wss-calc-title" className="wss-form-section__title">
				{ __( 'Try a price', 'woo-supplier-sync' ) }
			</h2>
			<p className="wss-muted">
				{ __(
					'Uses the rules on this screen, including unsaved edits.',
					'woo-supplier-sync'
				) }
			</p>
			<div className="wss-grid-2">
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="number"
					min={ 0 }
					label={ __( 'Purchase price', 'woo-supplier-sync' ) }
					value={ input.cost }
					onChange={ set( 'cost' ) }
				/>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Currency', 'woo-supplier-sync' ) }
					value={ input.currency }
					options={ [
						meta.store_currency,
						...Object.keys( draft.rates ),
					].map( ( c ) => ( { value: c, label: c } ) ) }
					onChange={ set( 'currency' ) }
				/>
			</div>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Supplier', 'woo-supplier-sync' ) }
				value={ input.supplier_id }
				options={ [
					{ value: '', label: __( 'Any', 'woo-supplier-sync' ) },
					...suppliers.map( ( s ) => ( {
						value: String( s.id ),
						label: s.name,
					} ) ),
				] }
				onChange={ set( 'supplier_id' ) }
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Category', 'woo-supplier-sync' ) }
				value={ input.category_id }
				options={ [
					{ value: '', label: __( 'Any', 'woo-supplier-sync' ) },
					...meta.categories.map( ( c ) => ( {
						value: String( c.id ),
						label: c.name,
					} ) ),
				] }
				onChange={ set( 'category_id' ) }
			/>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				type="number"
				min={ 0 }
				label={ __( 'Supplier RRP (optional)', 'woo-supplier-sync' ) }
				value={ input.old_price }
				onChange={ set( 'old_price' ) }
			/>

			<div className="wss-calc__result" aria-live="polite">
				{ error && <p className="wss-inline-error">{ error }</p> }
				{ result && (
					<>
						<p className="wss-calc__price">
							{ result.sale_price !== null && (
								<del>
									{ money(
										result.regular_price,
										result.currency
									) }
								</del>
							) }
							<strong>
								{ money( result.final_price, result.currency ) }
							</strong>
						</p>
						<dl className="wss-calc__steps">
							<div>
								<dt>
									{ __(
										'Cost in store currency',
										'woo-supplier-sync'
									) }
								</dt>
								<dd>
									{ money( result.cost, result.currency ) }
								</dd>
							</div>
							<div>
								<dt>{ __( 'Rule', 'woo-supplier-sync' ) }</dt>
								<dd>
									{ result.rule.id === 'default'
										? __(
												'Everything else',
												'woo-supplier-sync'
											)
										: result.rule.label || result.rule.id }
								</dd>
							</div>
							<div>
								<dt>{ __( 'Margin', 'woo-supplier-sync' ) }</dt>
								<dd>
									{ money( result.margin, result.currency ) }
									{ result.margin_percent !== null &&
										` (${ result.margin_percent }%)` }
								</dd>
							</div>
						</dl>
						{ result.margin_floor_applied && (
							<p className="wss-calc__note">
								{ __(
									'The markup was below the minimum margin, so the margin floor set the price.',
									'woo-supplier-sync'
								) }
							</p>
						) }
					</>
				) }
			</div>
		</aside>
	);
}
