/**
 * URL helpers for the table-field detail view.
 *
 * Detail state lives in `?entry-{fieldId}=...` so each table on a page
 * gets its own slot and reloads / back-forward / deep links all just work.
 *
 * The write helper also dispatches a synthetic event so other components
 * (notably SettingsPage, which switches into "detail page" mode) can
 * react — browsers don't fire popstate for replaceState/pushState.
 */

export const URL_CHANGE_EVENT = 'wireframe:table-detail-change';

export function readEntryParam( fieldId ) {
	if ( typeof window === 'undefined' ) {
		return '';
	}
	return (
		new URLSearchParams( window.location.search ).get( `entry-${ fieldId }` ) ||
		''
	);
}

export function writeEntryParam( fieldId, entryId ) {
	if ( typeof window === 'undefined' ) {
		return;
	}
	const url = new URL( window.location.href );
	const key = `entry-${ fieldId }`;
	if ( entryId ) {
		url.searchParams.set( key, entryId );
	} else {
		url.searchParams.delete( key );
	}
	window.history.replaceState( null, '', url.toString() );
	window.dispatchEvent( new CustomEvent( URL_CHANGE_EVENT ) );
}

/**
 * Scan a normalized config for the first table field whose detail param
 * is set in the URL. Returns null when no detail view should be active.
 */
export function findActiveDetailField( config ) {
	if ( typeof window === 'undefined' ) {
		return null;
	}

	const params = new URLSearchParams( window.location.search );

	for ( const tab of config?.tabs || [] ) {
		for ( const section of tab.sections || [] ) {
			for ( const field of section.fields || [] ) {
				if ( field.type !== 'table' ) {
					continue;
				}
				if ( ! field.args?.detail_view ) {
					continue;
				}
				const entryId = params.get( `entry-${ field.id }` );
				if ( entryId ) {
					return { field, entryId, tabId: tab.id };
				}
			}
		}
	}

	return null;
}
