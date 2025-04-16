import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

/**
 * Hash routes keep the app inside one admin page and make every screen linkable,
 * e.g. admin.php?page=supplier-sync#/runs/42 from a Telegram report.
 */
export function useRoute(): [ string[], ( path: string ) => void ] {
	const read = () =>
		window.location.hash
			.replace( /^#\/?/, '' )
			.split( '?' )[ 0 ]!
			.split( '/' )
			.filter( Boolean );
	const [ parts, setParts ] = useState< string[] >( read );

	useEffect( () => {
		const onChange = () => {
			setParts( read() );
			window.scrollTo( { top: 0 } );
		};
		window.addEventListener( 'hashchange', onChange );
		return () => window.removeEventListener( 'hashchange', onChange );
	}, [] );

	const navigate = useCallback( ( path: string ) => {
		window.location.hash = '#/' + path.replace( /^\//, '' );
	}, [] );

	return [ parts, navigate ];
}

interface AsyncState< T > {
	data: T | undefined;
	error: string | null;
	loading: boolean;
	reload: () => void;
	setData: ( data: T ) => void;
}

/**
 * Loads data and optionally keeps polling while `poll` returns true for the current value.
 */
export function useAsync< T >(
	load: () => Promise< T >,
	deps: unknown[],
	poll?: ( data: T ) => boolean,
	interval = 2500
): AsyncState< T > {
	const [ data, setData ] = useState< T >();
	const [ error, setError ] = useState< string | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ tick, setTick ] = useState( 0 );
	const loadRef = useRef( load );
	loadRef.current = load;

	useEffect( () => {
		let cancelled = false;
		let timer: number | undefined;

		const run = async () => {
			try {
				const result = await loadRef.current();
				if ( cancelled ) {
					return;
				}
				setData( result );
				setError( null );
				if ( poll && poll( result ) ) {
					timer = window.setTimeout( run, interval );
				}
			} catch ( e ) {
				if ( ! cancelled ) {
					setError( e instanceof Error ? e.message : String( e ) );
				}
			} finally {
				if ( ! cancelled ) {
					setLoading( false );
				}
			}
		};

		run();
		return () => {
			cancelled = true;
			window.clearTimeout( timer );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ ...deps, tick ] );

	return {
		data,
		error,
		loading,
		reload: () => setTick( ( t ) => t + 1 ),
		setData,
	};
}
