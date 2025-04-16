import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const BASE = '/supplier-sync/v1';

export interface Page< T > {
	items: T[];
	total: number;
	pages: number;
}

export class ApiError extends Error {
	constructor(
		message: string,
		readonly status?: number
	) {
		super( message );
	}
}

async function request< T >(
	path: string,
	options: {
		method?: string;
		data?: unknown;
		query?: Record< string, unknown >;
	} = {}
): Promise< T > {
	try {
		return await apiFetch< T >( {
			path: addQueryArgs( BASE + path, options.query ?? {} ),
			method: options.method ?? 'GET',
			data: options.data,
		} );
	} catch ( error ) {
		throw toError( error );
	}
}

export async function getPage< T >(
	path: string,
	query: Record< string, unknown > = {}
): Promise< Page< T > > {
	try {
		const response = await apiFetch< unknown, false >( {
			path: addQueryArgs( BASE + path, query ),
			parse: false,
		} );
		return {
			items: ( await response.json() ) as T[],
			total: Number( response.headers.get( 'X-WP-Total' ) ?? 0 ),
			pages: Number( response.headers.get( 'X-WP-TotalPages' ) ?? 1 ),
		};
	} catch ( error ) {
		throw toError(
			error instanceof Response
				? await error.json().catch( () => ( {} ) )
				: error
		);
	}
}

function toError( error: unknown ): ApiError {
	if ( error && typeof error === 'object' && 'message' in error ) {
		const data = ( error as { data?: { status?: number } } ).data;
		return new ApiError(
			String( ( error as { message: unknown } ).message ),
			data?.status
		);
	}
	return new ApiError(
		'The request failed. Check your connection and try again.'
	);
}

export const api = {
	get: < T >( path: string, query?: Record< string, unknown > ) =>
		request< T >( path, { query } ),
	post: < T >( path: string, data?: unknown ) =>
		request< T >( path, { method: 'POST', data } ),
	put: < T >( path: string, data?: unknown ) =>
		request< T >( path, { method: 'PUT', data } ),
	del: < T >( path: string ) => request< T >( path, { method: 'DELETE' } ),
};
