import { __, sprintf } from '@wordpress/i18n';
import { number, LOCALE } from '../format';
import type { Dashboard } from '../types';

type Day = Dashboard[ 'activity' ][ number ];

const SERIES: Array< {
	key: 'created' | 'updated' | 'unpublished';
	label: string;
} > = [
	{ key: 'created', label: __( 'New', 'woo-supplier-sync' ) },
	{ key: 'updated', label: __( 'Updated', 'woo-supplier-sync' ) },
	{ key: 'unpublished', label: __( 'Off sale', 'woo-supplier-sync' ) },
];

/**
 * Product changes per day, stacked. A plain table carries the same numbers for screen readers.
 */
export function ActivityChart( { days }: { days: Day[] } ) {
	const width = 560;
	const height = 150;
	const gap = 6;
	const barWidth = ( width - gap * ( days.length - 1 ) ) / days.length;
	const max = Math.max(
		1,
		...days.map( ( d ) => d.created + d.updated + d.unpublished )
	);
	const total = days.reduce(
		( sum, d ) => sum + d.created + d.updated + d.unpublished,
		0
	);
	const dayLabel = ( day: string ) =>
		new Date( day + 'T12:00:00Z' ).toLocaleDateString( LOCALE, {
			day: 'numeric',
			month: 'short',
		} );

	if ( total === 0 ) {
		return (
			<p className="wss-muted">
				{ __(
					'No product changes in the last two weeks.',
					'woo-supplier-sync'
				) }
			</p>
		);
	}

	return (
		<figure className="wss-chart">
			<svg
				viewBox={ `0 0 ${ width } ${ height + 22 }` }
				role="img"
				aria-hidden="true"
				focusable="false"
			>
				<line
					x1="0"
					x2={ width }
					y1={ height + 0.5 }
					y2={ height + 0.5 }
					className="wss-chart__axis"
				/>
				{ days.map( ( day, i ) => {
					const x = i * ( barWidth + gap );
					let y = height;
					return (
						<g key={ day.day }>
							<title>
								{ `${ dayLabel( day.day ) }: ` +
									SERIES.map(
										( s ) =>
											`${ s.label } ${ day[ s.key ] }`
									).join( ', ' ) }
							</title>
							{ SERIES.map( ( s ) => {
								const h =
									( day[ s.key ] / max ) * ( height - 8 );
								if ( h <= 0 ) {
									return null;
								}
								y -= h;
								return (
									<rect
										key={ s.key }
										x={ x }
										y={ y }
										width={ barWidth }
										height={ Math.max( h - 1, 1 ) }
										rx="2"
										className={ `wss-chart__bar is-${ s.key }` }
									/>
								);
							} ) }
							{ i % 2 === ( days.length - 1 ) % 2 && (
								<text
									x={ x + barWidth / 2 }
									y={ height + 16 }
									textAnchor="middle"
									className="wss-chart__label"
								>
									{ dayLabel( day.day ) }
								</text>
							) }
						</g>
					);
				} ) }
			</svg>
			<figcaption className="wss-chart__legend">
				{ SERIES.map( ( s ) => (
					<span key={ s.key }>
						<span
							className={ `wss-dot is-${ s.key }` }
							aria-hidden="true"
						/>
						{ s.label }
					</span>
				) ) }
				<span className="wss-muted">
					{ sprintf(
						/* translators: %s: number. */ __(
							'%s changes in total',
							'woo-supplier-sync'
						),
						number( total )
					) }
				</span>
			</figcaption>
			<table className="screen-reader-text">
				<caption>
					{ __( 'Product changes per day', 'woo-supplier-sync' ) }
				</caption>
				<thead>
					<tr>
						<th scope="col">
							{ __( 'Day', 'woo-supplier-sync' ) }
						</th>
						{ SERIES.map( ( s ) => (
							<th key={ s.key } scope="col">
								{ s.label }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ days.map( ( day ) => (
						<tr key={ day.day }>
							<th scope="row">{ dayLabel( day.day ) }</th>
							{ SERIES.map( ( s ) => (
								<td key={ s.key }>{ day[ s.key ] }</td>
							) ) }
						</tr>
					) ) }
				</tbody>
			</table>
		</figure>
	);
}
