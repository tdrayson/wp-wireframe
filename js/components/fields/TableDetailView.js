/**
 * TableDetailView — single-entry detail page rendered from a server-side
 * `render_callback`. Used when a table action declares `opens_detail: true`.
 *
 * Dispatches to:
 *   GET  /{prefix}/v1/table/{pageId}/{fieldId}/entry/{id}
 *   POST /{prefix}/v1/table/{pageId}/{fieldId}/action/{actionId}  (footer actions)
 *
 * Layout intentionally reads as a "page" — large title up top, a text-link
 * Back affordance below it, the consumer-rendered body, and an optional
 * action footer (Re-send / Delete style buttons).
 */
import { Button, Spinner } from '@wordpress/components';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

export default function TableDetailView( {
	entryId,
	tableBase,
	defaultTitle,
	actions = [],
	onEntryRemoved,
} ) {
	const [ html, setHtml ] = useState( '' );
	const [ title, setTitle ] = useState( defaultTitle || '' );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ runningAction, setRunningAction ] = useState( '' );

	const { createSuccessNotice, createErrorNotice } = useDispatch( noticesStore );

	useEffect( () => {
		const controller = new AbortController();
		setLoading( true );
		setError( '' );

		apiFetch( {
			path: `${ tableBase }/entry/${ encodeURIComponent( entryId ) }`,
			signal: controller.signal,
		} )
			.then( ( response ) => {
				setHtml( String( response?.html ?? '' ) );
				if ( response?.title ) {
					setTitle( String( response.title ) );
				}
			} )
			.catch( ( err ) => {
				if ( err.name === 'AbortError' ) {
					return;
				}
				const message = err.message || __( 'Failed to load entry.', 'wp-wireframe' );
				setError( message );
				createErrorNotice( message, { type: 'snackbar' } );
			} )
			.finally( () => {
				if ( ! controller.signal.aborted ) {
					setLoading( false );
				}
			} );

		return () => controller.abort();
	}, [ tableBase, entryId, createErrorNotice ] );

	const runAction = useCallback(
		async ( action ) => {
			if ( action.confirm && ! window.confirm( action.confirm ) ) {
				return;
			}

			setRunningAction( action.id );

			try {
				const response = await apiFetch( {
					path: `${ tableBase }/action/${ action.id }`,
					method: 'POST',
					data: { ids: [ entryId ] },
				} );

				createSuccessNotice(
					response?.message || __( 'Action completed.', 'wp-wireframe' ),
					{ type: 'snackbar' }
				);

				// Destructive actions remove the entry — bounce back to the list.
				if ( action.is_destructive && typeof onEntryRemoved === 'function' ) {
					onEntryRemoved();
				}
			} catch ( err ) {
				createErrorNotice(
					err.message || __( 'Action failed.', 'wp-wireframe' ),
					{ type: 'snackbar' }
				);
			} finally {
				setRunningAction( '' );
			}
		},
		[ tableBase, entryId, createSuccessNotice, createErrorNotice, onEntryRemoved ]
	);

	return (
		<div className="wireframe-table-detail">
			{ title && (
				<h2 className="wireframe-table-detail__title">{ title }</h2>
			) }

			<div className="wireframe-table-detail__body">
				{ loading && (
					<div className="wireframe-table-detail__loading">
						<Spinner />
					</div>
				) }

				{ ! loading && error && (
					<p className="wireframe-table-detail__error">{ error }</p>
				) }

				{ ! loading && ! error && (
					// eslint-disable-next-line react/no-danger
					<div dangerouslySetInnerHTML={ { __html: html } } />
				) }
			</div>

			{ ! loading && ! error && actions.length > 0 && (
				<div className="wireframe-table-detail__footer">
					{ actions.map( ( action ) => (
						<Button
							key={ action.id }
							variant="secondary"
							isDestructive={ !! action.is_destructive }
							onClick={ () => runAction( action ) }
							disabled={ runningAction !== '' }
							isBusy={ runningAction === action.id }
						>
							{ action.label }
						</Button>
					) ) }
				</div>
			) }
		</div>
	);
}
