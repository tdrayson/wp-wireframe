/**
 * Shared dispatch for action buttons (the `action` field and page header actions).
 *
 * Handles the confirm step, POSTs the in-flight form values, and applies the
 * common response handling: snackbar for `status` + `message`, browser
 * download for `download`. Rich `html` is handed back to the caller to render.
 *
 * @module useActionRunner
 */
import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { useSettings } from './useSettings';
import { downloadFile } from '../utils/downloadFile';

// WordPress `createNotice( status, ... )` only honours these statuses.
const SNACKBAR_STATUSES = new Set( [ 'success', 'error', 'warning', 'info' ] );

/**
 * Pick a response status from any of the supported response shapes.
 *
 * @param {Object} payload REST response body.
 * @return {string} One of success | error | warning | info.
 */
function resolveStatus( payload ) {
	if ( payload && typeof payload.status === 'string' && SNACKBAR_STATUSES.has( payload.status ) ) {
		return payload.status;
	}

	if ( payload && payload.success === false ) {
		return 'error';
	}

	return 'success';
}

/**
 * Normalize a confirm declaration into a uniform shape, or null.
 *
 * Accepts either:
 *  - string         → shorthand, becomes `{ message }`
 *  - array / object → `{ message, title, button_label, cancel_label }`
 *
 * Returning null means "no confirm" — the button fires immediately.
 *
 * @param {string|Object|undefined} raw Config `confirm` value.
 * @return {Object|null} `{ message, title, buttonLabel, cancelLabel }` or null.
 */
export function resolveConfirm( raw ) {
	if ( ! raw ) {
		return null;
	}

	if ( typeof raw === 'string' ) {
		return {
			message: raw,
			title: '',
			buttonLabel: '',
			cancelLabel: '',
		};
	}

	if ( typeof raw === 'object' ) {
		return {
			message: raw.message || '',
			title: raw.title || '',
			buttonLabel: raw.button_label || '',
			cancelLabel: raw.cancel_label || '',
		};
	}

	return null;
}

/**
 * Run buttons against their REST routes.
 *
 * @param {Function} pathFor    Maps a button to its REST path.
 * @param {Function} [onResult] Called with `( button, { status, message, html } )` after a successful run.
 * @return {Object} `{ runningId, confirmingButton, run, confirm, cancel }`.
 */
export function useActionRunner( pathFor, onResult ) {
	const { values } = useSettings();
	const { createNotice } = useDispatch( noticesStore );
	const [ runningId, setRunningId ] = useState( null );
	const [ confirmingButton, setConfirmingButton ] = useState( null );

	const perform = useCallback(
		async ( button ) => {
			setRunningId( button.id );

			try {
				const response = await apiFetch( {
					path: pathFor( button ),
					method: 'POST',
					data: { values },
				} );

				const status = resolveStatus( response );
				const message = response?.message || '';
				const html = response?.html || '';

				if ( message ) {
					createNotice( status, message, { type: 'snackbar' } );
				}

				if ( response?.download?.filename ) {
					downloadFile( response.download );
				}

				onResult?.( button, { status, message, html } );
			} catch ( requestError ) {
				const message =
					requestError?.message ||
					__( 'Action failed.', 'wp-wireframe' );

				createNotice( 'error', message, { type: 'snackbar' } );
			} finally {
				setRunningId( null );
			}
		},
		[ pathFor, onResult, values, createNotice ]
	);

	const run = useCallback(
		( button ) => {
			if ( button.confirm ) {
				setConfirmingButton( button );
				return;
			}

			perform( button );
		},
		[ perform ]
	);

	const confirm = useCallback( () => {
		if ( ! confirmingButton ) {
			return;
		}

		const button = confirmingButton;
		setConfirmingButton( null );
		perform( button );
	}, [ confirmingButton, perform ] );

	const cancel = useCallback( () => setConfirmingButton( null ), [] );

	return { runningId, confirmingButton, run, confirm, cancel };
}
