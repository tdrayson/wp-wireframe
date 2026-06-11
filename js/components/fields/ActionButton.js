/**
 * ActionButton — renders one or more buttons that POST the in-flight form
 * values to the server-side action hook.
 *
 * Each button maps to a server route:
 *   POST {restRoot}/action/{pageId}/{fieldId}/{actionId}
 *
 * The PHP `ActionController` sanitizes the submitted values, then fires:
 *   apply_filters(
 *     "{prefix}/action/{pageId}/{fieldId}/{actionId}",
 *     Unhandled::get(),
 *     $values,
 *     $request
 *   )
 *
 * Response shape (any of):
 *   { status, message?, html? }   // status: success | error | warning | info
 *   { success: bool }             // implicit status
 *   { download: { filename, mime, content, encoding? } }  // triggers a download
 *
 * Behaviour:
 *  - Always fires a WP `core/notices` snackbar when `status` + `message`
 *    are present.
 *  - Renders an inline result panel only when `html` is non-empty — short
 *    responses get the snackbar alone, rich responses get both.
 *
 * Config (pure data, no PHP callables):
 *
 *   [
 *       'id'      => 'billing_tools',
 *       'type'    => 'action',
 *       'label'   => 'Billing tools',
 *       'buttons' => [
 *           ['id' => 'recalculate', 'label' => 'Recalc',     'variant' => 'primary'],
 *           ['id' => 'export',      'label' => 'Export CSV'],
 *           ['id' => 'wipe',        'label' => 'Wipe',       'destructive' => true, 'confirm' => '…'],
 *       ],
 *   ]
 *
 * Sugar: omit `buttons` for the single-button case. The button id falls
 * back to the field id, the label to `args.button_label` or `field.label`.
 */
import { Button, BaseControl, Modal, Flex, FlexItem } from '@wordpress/components';
import { RawHTML, useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { useSettings } from '../../hooks/useSettings';
import { downloadFile } from '../../utils/downloadFile';

const VARIANT_CLASSES = {
	success: 'wireframe-action__result--success',
	error: 'wireframe-action__result--error',
	warning: 'wireframe-action__result--warning',
	info: 'wireframe-action__result--info',
};

// WordPress `createNotice( status, ... )` only honours these statuses.
const SNACKBAR_STATUSES = new Set( [ 'success', 'error', 'warning', 'info' ] );

/**
 * Pick a response status from any of the supported response shapes.
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
 */
function resolveConfirm( raw ) {
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
 * Normalize the field's button list into the array form.
 *
 * Sugar: a field with no `buttons` key gets a single implicit button whose
 * id is the literal string `'run'` (so the hook reads as `…/{fieldId}/run`
 * rather than `…/{fieldId}/{fieldId}`). Label comes from
 * `args.button_label`, then `field.label`, then a generic fallback.
 */
function resolveButtons( field, args ) {
	if ( Array.isArray( args.buttons ) && args.buttons.length > 0 ) {
		return args.buttons
			.filter( ( button ) => button && typeof button.id === 'string' && button.id !== '' )
			.map( ( button ) => ( {
				id: button.id,
				label: button.label || field.label || __( 'Run', 'wp-wireframe' ),
				variant: button.variant || 'secondary',
				destructive: Boolean( button.destructive ),
				confirm: resolveConfirm( button.confirm ),
			} ) );
	}

	return [
		{
			id: 'run',
			label:
				args.button_label ||
				field.label ||
				__( 'Run', 'wp-wireframe' ),
			variant: args.variant || 'secondary',
			destructive: Boolean( args.destructive ),
			confirm: resolveConfirm( args.confirm ),
		},
	];
}

export default function ActionButton( { field } ) {
	const args = field._args || {};
	const buttons = resolveButtons( field, args );

	const { values, restRoot, pageId } = useSettings();
	const { createNotice } = useDispatch( noticesStore );

	const [ runningId, setRunningId ] = useState( null );

	// Result panels are keyed by button id, so each button owns its own
	// slot. Clicking button A only ever updates A's panel — B's previous
	// rich result stays visible. A button's own subsequent click overwrites
	// (returns html → replaces) or clears (returns no html → removes) only
	// its own slot.
	const [ results, setResults ] = useState( {} );
	const [ confirmingButton, setConfirmingButton ] = useState( null );

	const performAction = useCallback(
		async ( button ) => {
			setRunningId( button.id );

			const endpoint = `/${ restRoot }/action/${ pageId }/${ encodeURIComponent(
				field.id
			) }/${ encodeURIComponent( button.id ) }`;

			try {
				const response = await apiFetch( {
					path: endpoint,
					method: 'POST',
					data: { values },
				} );

				const status = resolveStatus( response );
				const message = response?.message || '';
				const html = response?.html || '';

				if ( message ) {
					createNotice( status, message, { type: 'snackbar' } );
				}

				// Optional file download — handler returned a `download`
				// artifact (e.g. a generated CSV or report).
				if ( response?.download?.filename ) {
					downloadFile( response.download );
				}

				// Update only this button's slot. Other buttons' panels are
				// untouched.
				setResults( ( previous ) => {
					const next = { ...previous };

					if ( html ) {
						next[ button.id ] = { status, message, html };
					} else {
						delete next[ button.id ];
					}

					return next;
				} );
			} catch ( requestError ) {
				const message =
					requestError?.message ||
					__( 'Action failed.', 'wp-wireframe' );

				createNotice( 'error', message, { type: 'snackbar' } );
				// Don't touch any result slots on error — whatever was
				// showing before stays visible.
			} finally {
				setRunningId( null );
			}
		},
		[ restRoot, pageId, field.id, values, createNotice ]
	);

	const handleClick = useCallback(
		( button ) => {
			if ( button.confirm ) {
				setConfirmingButton( button );
				return;
			}

			performAction( button );
		},
		[ performAction ]
	);

	const handleConfirm = useCallback( () => {
		if ( ! confirmingButton ) {
			return;
		}

		const button = confirmingButton;
		setConfirmingButton( null );
		performAction( button );
	}, [ confirmingButton, performAction ] );

	// Each button's text IS the accessible name of its action, so when
	// `field.label` is empty we deliberately omit BaseControl's <label>
	// element entirely (rather than passing an NBSP, which would render
	// an empty-but-present label in the a11y tree). A CSS spacer with
	// aria-hidden keeps the visual alignment with sibling labelled fields.
	const hasLabel = typeof field.label === 'string' && field.label !== '';

	return (
		<BaseControl
			__nextHasNoMarginBottom
			label={ hasLabel ? field.label : undefined }
			help={ field.description || undefined }
			id={ `wireframe-action-${ field.id }` }
		>
			<div className="wireframe-action">
				{ ! hasLabel && (
					<div
						className="wireframe-action__label-spacer"
						aria-hidden="true"
					/>
				) }
				<Flex className="wireframe-action__buttons" gap={ 2 } wrap justify="flex-start">
					{ buttons.map( ( button ) => (
						<FlexItem key={ button.id }>
							<Button
								__next40pxDefaultSize
								variant={ button.variant }
								isDestructive={ button.destructive }
								onClick={ () => handleClick( button ) }
								isBusy={ runningId === button.id }
								disabled={ runningId !== null }
							>
								{ button.label }
							</Button>
						</FlexItem>
					) ) }
				</Flex>

				{ buttons.map( ( button ) => {
					const result = results[ button.id ];

					if ( ! result || ! result.html ) {
						return null;
					}

					return (
						<div
							key={ `result-${ button.id }` }
							className={ `wireframe-action__result ${
								VARIANT_CLASSES[ result.status ] || ''
							}` }
							role={ result.status === 'error' ? 'alert' : 'status' }
						>
							<RawHTML>{ result.html }</RawHTML>
						</div>
					);
				} ) }
			</div>

			{ confirmingButton && confirmingButton.confirm && (
				<Modal
					title={
						confirmingButton.confirm.title ||
						__( 'Are you sure?', 'wp-wireframe' )
					}
					onRequestClose={ () => setConfirmingButton( null ) }
				>
					<p>{ confirmingButton.confirm.message }</p>
					<Flex justify="flex-end" gap={ 2 }>
						<FlexItem>
							<Button
								variant="tertiary"
								onClick={ () => setConfirmingButton( null ) }
							>
								{ confirmingButton.confirm.cancelLabel ||
									__( 'Cancel', 'wp-wireframe' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button
								variant="primary"
								isDestructive={ confirmingButton.destructive }
								onClick={ handleConfirm }
							>
								{ confirmingButton.confirm.buttonLabel ||
									confirmingButton.label }
							</Button>
						</FlexItem>
					</Flex>
				</Modal>
			) }
		</BaseControl>
	);
}
