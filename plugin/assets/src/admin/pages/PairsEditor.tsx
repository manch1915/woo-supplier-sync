import { Button } from '@wordpress/components';
import { useEffect, useId, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { closeSmall, plus } from '@wordpress/icons';

interface Props {
	title: string;
	help?: string;
	fromLabel: string;
	toLabel: string;
	value: Record< string, string >;
	suggestions?: string[];
	onChange: ( value: Record< string, string > ) => void;
}

/**
 * Editable "from -> to" list. Rows live in local state so a half-typed key
 * does not collapse into another row.
 */
export function PairsEditor( {
	title,
	help,
	fromLabel,
	toLabel,
	value,
	suggestions = [],
	onChange,
}: Props ) {
	const [ rows, setRows ] = useState< Array< [ string, string ] > >( () =>
		Object.entries( value )
	);
	const id = useId();

	useEffect( () => {
		const fromProps = JSON.stringify( Object.entries( value ) );
		const local = JSON.stringify(
			rows.filter( ( [ k ] ) => k.trim() !== '' )
		);
		if ( fromProps !== local ) {
			setRows( Object.entries( value ) );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ JSON.stringify( value ) ] );

	const commit = ( next: Array< [ string, string ] > ) => {
		setRows( next );
		onChange(
			Object.fromEntries( next.filter( ( [ k ] ) => k.trim() !== '' ) )
		);
	};

	return (
		<div
			className="wss-pairs"
			role="group"
			aria-labelledby={ `${ id }-title` }
		>
			<h3 className="wss-subtitle" id={ `${ id }-title` }>
				{ title }
			</h3>
			{ help && <p className="wss-muted">{ help }</p> }
			{ rows.length > 0 && (
				<div className="wss-pairs__head" aria-hidden="true">
					<span>{ fromLabel }</span>
					<span>{ toLabel }</span>
				</div>
			) }
			{ suggestions.length > 0 && (
				<datalist id={ `${ id }-suggestions` }>
					{ suggestions.map( ( s ) => (
						<option key={ s } value={ s } />
					) ) }
				</datalist>
			) }
			{ rows.map( ( [ from, to ], index ) => (
				<div className="wss-pairs__row" key={ index }>
					<input
						className="wss-input"
						aria-label={ `${ fromLabel } ${ index + 1 }` }
						value={ from }
						onChange={ ( e ) =>
							commit(
								rows.map( ( row, i ) =>
									i === index
										? [ e.target.value, row[ 1 ] ]
										: row
								)
							)
						}
					/>
					<input
						className="wss-input"
						aria-label={ `${ toLabel } ${ index + 1 }` }
						list={
							suggestions.length
								? `${ id }-suggestions`
								: undefined
						}
						value={ to }
						onChange={ ( e ) =>
							commit(
								rows.map( ( row, i ) =>
									i === index
										? [ row[ 0 ], e.target.value ]
										: row
								)
							)
						}
					/>
					<Button
						icon={ closeSmall }
						label={ sprintf(
							/* translators: %s: row key. */ __(
								'Remove %s',
								'woo-supplier-sync'
							),
							from || __( 'row', 'woo-supplier-sync' )
						) }
						onClick={ () =>
							commit( rows.filter( ( _, i ) => i !== index ) )
						}
						size="compact"
					/>
				</div>
			) ) }
			<Button
				variant="tertiary"
				icon={ plus }
				onClick={ () => setRows( [ ...rows, [ '', '' ] ] ) }
				size="compact"
			>
				{ __( 'Add row', 'woo-supplier-sync' ) }
			</Button>
		</div>
	);
}
