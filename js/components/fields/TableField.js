/**
 * TableField — paginated data table powered by @wordpress/dataviews.
 *
 * Stateless. The framework dispatches two generic REST routes per page:
 *   GET  /{prefix}/v1/table/{pageId}/{fieldId}                  → data_callback
 *   POST /{prefix}/v1/table/{pageId}/{fieldId}/action/{action}  → action callback
 *
 * The consuming plugin only supplies PHP callable names in the field config;
 * the controller resolves + invokes them server-side.
 */
import { DataViews } from '@wordpress/dataviews';
import { BaseControl } from '@wordpress/components';
import { useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	archive,
	arrowRight,
	atSymbol,
	backup,
	check,
	chevronRight,
	close,
	cog,
	copy,
	download,
	edit,
	envelope,
	external,
	help,
	info,
	key,
	lock,
	more,
	plus,
	seen,
	share,
	starFilled,
	trash,
	unlock,
	unseen,
	update,
	upload,
} from '@wordpress/icons';
import { useSettings } from '../../hooks/useSettings';
import { writeEntryParam } from '../../utils/tableDetailUrl';

/**
 * Explicit icon map — entries must be statically imported above, otherwise
 * webpack tree-shakes them out of the bundle (dynamic lookups on a
 * namespace import return undefined at runtime).
 */
const ICONS = {
	archive,
	arrowRight,
	atSymbol,
	backup,
	check,
	chevronRight,
	close,
	cog,
	copy,
	download,
	edit,
	envelope,
	external,
	help,
	info,
	key,
	lock,
	more,
	plus,
	seen,
	share,
	starFilled,
	trash,
	unlock,
	unseen,
	update,
	upload,
};

/**
 * Resolve an icon name (kebab or camelCase) to a @wordpress/icons export.
 *
 * DataViews renders each bulk action as its own icon-only button (no
 * "more actions" dropdown) and drops any action whose icon is falsy, so
 * every action needs one. When the consumer hasn't supplied one we fall
 * back based on intent: destructive → trash, otherwise a generic cog.
 */
function resolveIcon( name, { isDestructive = false } = {} ) {
	if ( ! name ) {
		return isDestructive ? trash : cog;
	}

	if ( typeof name !== 'string' ) {
		return name;
	}

	const camel = name.replace( /-([a-z])/g, ( _, c ) => c.toUpperCase() );
	return ICONS[ camel ] || ICONS[ name ] || cog;
}

/**
 * Parse "{prefix}/v1/settings/{pageId}" → { namespace: "{prefix}/v1", pageId }.
 */
function splitRestBase( restBase ) {
	const match = restBase.match( /^(.+\/v\d+)\/settings\/(.+)$/ );

	if ( ! match ) {
		return { namespace: '', pageId: 'default' };
	}

	return { namespace: match[ 1 ], pageId: match[ 2 ] };
}

/**
 * Translate a field config entry into a DataViews field descriptor.
 */
function toDataViewsField( f ) {
	const out = {
		id: f.id,
		label: f.label || f.id,
		type: f.type || 'text',
		enableSorting: !! f.sortable,
		enableHiding: !! f.hideable,
		enableGlobalSearch: !! f.searchable,
	};

	if ( f.elements && typeof f.elements === 'object' ) {
		out.elements = Object.entries( f.elements ).map( ( [ value, label ] ) => ( {
			value,
			label,
		} ) );
	}

	return out;
}

export default function TableField( { field } ) {
	const { _args = {} } = field;
	const {
		fields: fieldConfigs = [],
		actions: actionConfigs = [],
		per_page: initialPerPage = 10,
		search: enableSearch = false,
	} = _args;

	const { restBase } = useSettings();
	const { namespace, pageId } = splitRestBase( restBase );
	const tableBase = `${ namespace }/table/${ pageId }/${ field.id }`;

	const { createSuccessNotice, createErrorNotice } = useDispatch( noticesStore );

	// "View entry" actions just write the URL; SettingsPage detects that
	// and swaps the whole page into detail-view mode (no chrome).
	const enterDetail = useCallback(
		( id ) => {
			writeEntryParam( field.id, String( id ) );
		},
		[ field.id ]
	);

	const dvFields = useMemo(
		() => fieldConfigs.map( toDataViewsField ),
		[ fieldConfigs ]
	);

	const defaultFieldIds = useMemo(
		() => dvFields.map( ( f ) => f.id ),
		[ dvFields ]
	);

	// Per the DataViews docs, every layout needs a primaryField so the
	// component knows which column is the canonical row identifier.
	const defaultLayouts = useMemo(
		() => ( {
			table: {
				layout: {
					primaryField: dvFields[ 0 ]?.id || 'id',
				},
			},
		} ),
		[ dvFields ]
	);

	const [ view, setView ] = useState( () => ( {
		type: 'table',
		perPage: initialPerPage,
		page: 1,
		search: '',
		sort: {},
		filters: [],
		fields: defaultFieldIds,
		layout: { primaryField: 'id' },
	} ) );

	const [ items, setItems ] = useState( [] );
	const [ totalItems, setTotalItems ] = useState( 0 );
	const [ loading, setLoading ] = useState( false );
	const [ reloadKey, setReloadKey ] = useState( 0 );

	// Fetch rows whenever the view or reload key changes.
	useEffect( () => {
		if ( ! namespace ) {
			return undefined;
		}

		const controller = new AbortController();
		setLoading( true );

		const params = new URLSearchParams();
		params.set( 'page', String( view.page ) );
		params.set( 'per_page', String( view.perPage ) );

		if ( view.search ) {
			params.set( 'search', view.search );
		}

		if ( view.sort?.field ) {
			params.set( 'orderby', view.sort.field );
			params.set( 'order', view.sort.direction || 'asc' );
		}

		if ( Array.isArray( view.filters ) && view.filters.length > 0 ) {
			params.set( 'filters', JSON.stringify( view.filters ) );
		}

		apiFetch( {
			path: `${ tableBase }?${ params.toString() }`,
			signal: controller.signal,
		} )
			.then( ( response ) => {
				setItems( Array.isArray( response?.items ) ? response.items : [] );
				setTotalItems( Number( response?.total ) || 0 );
			} )
			.catch( ( error ) => {
				if ( error.name === 'AbortError' ) {
					return;
				}
				createErrorNotice(
					error.message || __( 'Failed to load table data.', 'wp-wireframe' ),
					{ type: 'snackbar' }
				);
			} )
			.finally( () => {
				if ( ! controller.signal.aborted ) {
					setLoading( false );
				}
			} );

		return () => controller.abort();
	}, [
		namespace,
		tableBase,
		view.page,
		view.perPage,
		view.search,
		view.sort?.field,
		view.sort?.direction,
		view.filters,
		reloadKey,
		createErrorNotice,
	] );

	const runAction = useCallback(
		async ( actionConfig, selectedItems ) => {
			// View actions short-circuit the server round-trip and just
			// flip the URL into detail mode for the first selected row.
			if ( actionConfig.opens_detail ) {
				const target = selectedItems[ 0 ];
				if ( target ) {
					enterDetail( target.id );
				}
				return;
			}

			if ( actionConfig.confirm && ! window.confirm( actionConfig.confirm ) ) {
				return;
			}

			try {
				const response = await apiFetch( {
					path: `${ tableBase }/action/${ actionConfig.id }`,
					method: 'POST',
					data: { ids: selectedItems.map( ( item ) => item.id ) },
				} );

				createSuccessNotice(
					response?.message || __( 'Action completed.', 'wp-wireframe' ),
					{ type: 'snackbar' }
				);

				// Trigger a data refresh so deletes / mutations are reflected.
				setReloadKey( ( k ) => k + 1 );
			} catch ( error ) {
				createErrorNotice(
					error.message || __( 'Action failed.', 'wp-wireframe' ),
					{ type: 'snackbar' }
				);
			}
		},
		[ tableBase, createSuccessNotice, createErrorNotice, enterDetail ]
	);

	const dvActions = useMemo(
		() =>
			actionConfigs.map( ( action ) => ( {
				id: action.id,
				label: action.label,
				isDestructive: !! action.is_destructive,
				// View actions are inherently single-row.
				supportsBulk: action.opens_detail ? false : !! action.supports_bulk,
				// DataViews omits bulk action buttons that have no icon,
				// so resolve one — falling back based on intent.
				icon: resolveIcon( action.icon, {
					isDestructive: !! action.is_destructive,
				} ),
				callback: ( selected ) => runAction( action, selected ),
			} ) ),
		[ actionConfigs, runAction ]
	);

	return (
		<BaseControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ field.description }
			id={ `wireframe-table-${ field.id }` }
		>
			<div className="wireframe-table">
				<DataViews
					data={ items }
					fields={ dvFields }
					view={ view }
					onChangeView={ setView }
					actions={ dvActions.length > 0 ? dvActions : undefined }
					paginationInfo={ {
						totalItems,
						totalPages: Math.max( 1, Math.ceil( totalItems / view.perPage ) ),
					} }
					defaultLayouts={ defaultLayouts }
					search={ enableSearch }
					isLoading={ loading }
					getItemId={ ( item ) => String( item.id ) }
				/>
			</div>
		</BaseControl>
	);
}
