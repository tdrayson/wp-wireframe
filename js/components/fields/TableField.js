/**
 * TableField — paginated data table using @wordpress/dataviews.
 *
 * Stateless field — displays data from a REST endpoint.
 * Supports search, column sorting, pagination, and row actions.
 */
import { DataViews } from '@wordpress/dataviews';
import { BaseControl, Button } from '@wordpress/components';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

export default function TableField( { field } ) {
	const { _args = {} } = field;
	const tableConfig = _args.table_config || {};
	const {
		endpoint = '',
		columns = [],
		per_page: perPage = 10,
		search: enableSearch = false,
		pagination: enablePagination = true,
	} = tableConfig;

	const [ items, setItems ] = useState( [] );
	const [ totalItems, setTotalItems ] = useState( 0 );
	const [ loading, setLoading ] = useState( false );
	const [ view, setView ] = useState( {
		type: 'table',
		perPage,
		page: 1,
		search: '',
		sort: {},
		filters: [],
		fields: columns.filter( ( c ) => c.type !== 'actions' ).map( ( c ) => c.id ),
	} );

	// Build DataViews fields from column config.
	const fields = columns
		.filter( ( col ) => col.type !== 'actions' )
		.map( ( col ) => ( {
			id: col.id,
			label: col.name,
			enableSorting: col.sort || false,
			enableHiding: false,
		} ) );

	// Build actions from column config.
	const actionColumn = columns.find( ( col ) => col.type === 'actions' );
	const actions = ( actionColumn?.actions || [] ).map( ( action ) => ( {
		id: action.label.toLowerCase().replace( /\s+/g, '-' ),
		label: action.label,
		icon: action.icon ? () => (
			<span
				className="wireframe-table__action-icon"
				dangerouslySetInnerHTML={ { __html: action.icon } }
			/>
		) : undefined,
		isDestructive: action.style === 'danger',
		callback: async ( itemIds, { registry } ) => {
			if ( action.confirm && ! window.confirm( action.confirm ) ) {
				return;
			}
			try {
				const item = items.find( ( i ) => itemIds.includes( i.id ) );
				let url = action.endpoint || '';
				if ( item ) {
					url = url.replace( /\{(\w+)\}/g, ( _, key ) => item[ key ] ?? '' );
				}
				await apiFetch( {
					url,
					method: action.method || 'POST',
				} );
				if ( action.success_message ) {
					// eslint-disable-next-line no-alert
					alert( action.success_message );
				}
			} catch ( error ) {
				// eslint-disable-next-line no-alert
				alert( error.message || __( 'Action failed.', 'wp-wireframe' ) );
			}
		},
	} ) );

	// Fetch data when view changes.
	useEffect( () => {
		if ( ! endpoint ) return;

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

		const separator = endpoint.includes( '?' ) ? '&' : '?';
		const url = `${ endpoint }${ separator }${ params.toString() }`;

		apiFetch( { url, signal: controller.signal } )
			.then( ( response ) => {
				if ( Array.isArray( response ) ) {
					setItems( response );
					setTotalItems( response.length );
				} else if ( response?.data ) {
					setItems( response.data );
					setTotalItems( response.total ?? response.data.length );
				}
			} )
			.catch( ( error ) => {
				if ( error.name !== 'AbortError' ) {
					console.error( 'Table fetch failed:', error );
				}
			} )
			.finally( () => {
				if ( ! controller.signal.aborted ) {
					setLoading( false );
				}
			} );

		return () => controller.abort();
	}, [ endpoint, view.page, view.perPage, view.search, view.sort?.field, view.sort?.direction ] );

	return (
		<BaseControl
			label={ field.label }
			help={ field.description }
			id={ `wireframe-table-${ field.id }` }
		>
			<div className="wireframe-table">
				<DataViews
					data={ items }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					actions={ actions.length > 0 ? actions : undefined }
					paginationInfo={
						enablePagination
							? { totalItems, totalPages: Math.ceil( totalItems / view.perPage ) }
							: undefined
					}
					search={ enableSearch }
					isLoading={ loading }
					getItemId={ ( item ) => item.id }
				/>
			</div>
		</BaseControl>
	);
}
