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
 * Normalize the field's button list into the array form.
 *
 * Sugar: a field with no `buttons` key gets a single implicit button whose
 * id matches the field id and whose label comes from `args.button_label`
 * (then `field.label`, then a generic fallback).
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
				confirm: button.confirm || '',
				confirmTitle: button.confirm_title || '',
				confirmButtonLabel: button.confirm_button_label || '',
			} ) );
	}

	return [
		{
			id: field.id,
			label:
				args.button_label ||
				field.label ||
				__( 'Run', 'wp-wireframe' ),
			variant: args.variant || 'secondary',
			destructive: Boolean( args.destructive ),
			confirm: args.confirm || '',
			confirmTitle: args.confirm_title || '',
			confirmButtonLabel: args.confirm_button_label || '',
		},
	];
}

export default function ActionButton( { field } ) {
	const args = field._args || {};
	const buttons = resolveButtons( field, args );

	const { values, restRoot, pageId } = useSettings();
	const { createNotice } = useDispatch( noticesStore );

	const [ runningId, setRunningId ] = useState( null );
	const [ result, setResult ] = useState( null );
	const [ confirmingButton, setConfirmingButton ] = useState( null );

	const performAction = useCallback(
		async ( button ) => {
			setRunningId( button.id );
			setResult( null );

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

				if ( html ) {
					setResult( { status, message, html } );
				}
			} catch ( requestError ) {
				const message =
					requestError?.message ||
					__( 'Action failed.', 'wp-wireframe' );

				createNotice( 'error', message, { type: 'snackbar' } );
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

	return (
		<BaseControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ field.description }
			id={ `wireframe-action-${ field.id }` }
		>
			<div className="wireframe-action">
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

				{ result && result.html && (
					<div
						className={ `wireframe-action__result ${
							VARIANT_CLASSES[ result.status ] || ''
						}` }
						role={ result.status === 'error' ? 'alert' : 'status' }
					>
						<RawHTML>{ result.html }</RawHTML>
					</div>
				) }
			</div>

			{ confirmingButton && (
				<Modal
					title={
						confirmingButton.confirmTitle ||
						__( 'Are you sure?', 'wp-wireframe' )
					}
					onRequestClose={ () => setConfirmingButton( null ) }
				>
					<p>{ confirmingButton.confirm }</p>
					<Flex justify="flex-end" gap={ 2 }>
						<FlexItem>
							<Button
								variant="tertiary"
								onClick={ () => setConfirmingButton( null ) }
							>
								{ __( 'Cancel', 'wp-wireframe' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button
								variant="primary"
								isDestructive={ confirmingButton.destructive }
								onClick={ handleConfirm }
							>
								{ confirmingButton.confirmButtonLabel ||
									confirmingButton.label }
							</Button>
						</FlexItem>
					</Flex>
				</Modal>
			) }
		</BaseControl>
	);
}
