import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { isActive, startRun } from '../actions';
import { DryRunTag, Progress, RunBar, useMeta } from '../components';
import {
	number,
	relativeTime,
	runSummary,
	SCHEDULE_LABELS,
	TYPE_LABELS,
} from '../format';
import type { Supplier } from '../types';

interface Props {
	supplier: Supplier;
	navigate: ( path: string ) => void;
	onStarted: () => void;
}

export function SupplierRow( { supplier, navigate, onStarted }: Props ) {
	const meta = useMeta();
	const [ busy, setBusy ] = useState< 'dry' | 'live' | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const active = supplier.active_run;

	const run = async ( dry: boolean ) => {
		setBusy( dry ? 'dry' : 'live' );
		setError( null );
		try {
			const started = await startRun( supplier.id, dry );
			onStarted();
			if ( dry ) {
				navigate( `runs/${ started.id }` );
			}
		} catch ( e ) {
			setError( e instanceof Error ? e.message : String( e ) );
		} finally {
			setBusy( null );
		}
	};

	return (
		<article
			className={ `wss-supplier${ supplier.enabled ? '' : ' is-disabled' }` }
			aria-labelledby={ `wss-supplier-${ supplier.id }` }
		>
			<div className="wss-supplier__id">
				<h3 id={ `wss-supplier-${ supplier.id }` }>
					<a href={ `#/suppliers/${ supplier.id }` }>
						{ supplier.name }
					</a>
				</h3>
				<p className="wss-supplier__meta">
					<span className={ `wss-type is-${ supplier.type }` }>
						{ TYPE_LABELS[ supplier.type ] }
					</span>
					<span>
						{ supplier.enabled
							? ( SCHEDULE_LABELS[ supplier.schedule ] ??
								supplier.schedule )
							: __( 'Paused', 'woo-supplier-sync' ) }
					</span>
					<span>
						{ sprintf(
							/* translators: %s: number of offers. */ __(
								'%s offers',
								'woo-supplier-sync'
							),
							number( supplier.offers ?? 0 )
						) }
					</span>
				</p>
			</div>

			<div className="wss-supplier__status" aria-live="polite">
				<LastRun supplier={ supplier } />
				{ supplier.next_run && supplier.enabled && (
					<p className="wss-supplier__next wss-muted">
						{ sprintf(
							/* translators: %s: relative time. */ __(
								'Next run %s',
								'woo-supplier-sync'
							),
							relativeTime( supplier.next_run )
						) }
					</p>
				) }
				{ error && <p className="wss-inline-error">{ error }</p> }
			</div>

			{ meta.caps.run && (
				<div className="wss-supplier__actions">
					<Button
						variant="secondary"
						onClick={ () => run( true ) }
						isBusy={ busy === 'dry' }
						disabled={ !! busy || isActive( active ) }
					>
						{ __( 'Preview changes', 'woo-supplier-sync' ) }
					</Button>
					<Button
						variant="primary"
						onClick={ () => run( false ) }
						isBusy={ busy === 'live' }
						disabled={ !! busy || isActive( active ) }
					>
						{ __( 'Sync now', 'woo-supplier-sync' ) }
					</Button>
				</div>
			) }
		</article>
	);
}

function LastRun( { supplier }: { supplier: Supplier } ) {
	const active = supplier.active_run;
	const last = supplier.last_run;

	if ( active && isActive( active ) ) {
		return (
			<>
				<p className="wss-supplier__line">
					{ active.dry_run && <DryRunTag /> }
					<a href={ `#/runs/${ active.id }` }>
						{ runSummary( active ) }
					</a>
				</p>
				<Progress run={ active } />
			</>
		);
	}

	if ( ! last ) {
		return (
			<p className="wss-supplier__line wss-muted">
				{ __( 'Not synced yet', 'woo-supplier-sync' ) }
			</p>
		);
	}

	return (
		<>
			<p className="wss-supplier__line">
				{ last.dry_run && <DryRunTag /> }
				<a
					href={ `#/runs/${ last.id }` }
					className={ last.status === 'failed' ? 'is-failed' : '' }
				>
					{ runSummary( last ) }
				</a>
				<span className="wss-muted">
					{ relativeTime( last.finished_at ?? last.created_at ) }
				</span>
			</p>
			<RunBar stats={ last.stats } compact />
		</>
	);
}
