import { api } from './api';
import type { Run } from './types';

export function startRun(
	supplierId: number,
	dryRun: boolean,
	force = false
): Promise< Run > {
	return api.post< Run >( `/suppliers/${ supplierId }/runs`, {
		dry_run: dryRun,
		force,
	} );
}

export function isActive( run: Run | null | undefined ): boolean {
	return !! run && ( run.status === 'queued' || run.status === 'running' );
}
