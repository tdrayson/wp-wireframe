/**
 * ActionButton — runs a server-side callback and renders the result.
 *
 * Dispatches to `POST /{prefix}/v1/action/{pageId}/{fieldId}` with the
 * current in-flight form values. The PHP `ActionController` sanitizes
 * those values through the same per-type handlers Save uses, then calls
 * the field's `args.callback`.
 *
 * The response shape is conventional:
 *   {
 *     status?:  'success' | 'error' | 'warning' | 'info',
 *     message?: string,        // shown as a notice block
 *     html?:    string,        // optional rich result, rendered raw
 *     success?: boolean,       // implicit status fallback
 *   }
 *
 * Anything the callback writes into `html` is rendered verbatim — the
 * consumer's callback is responsible for escaping user-derived content
 * (use `esc_html()` / `wp_kses_post()` server-side).
 */
import { Button, BaseControl, Modal, Flex, FlexItem } from '@wordpress/components';
import { RawHTML, useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useSettings } from '../../hooks/useSettings';

const VARIANT_CLASSES = {
	success: 'wireframe-action__result--success',
	error: 'wireframe-action__result--error',
	warning: 'wireframe-action__result--warning',
	info: 'wireframe-action__result--info',
};

function resolveStatus( payload ) {
	if ( payload && typeof payload.status === 'string' ) {
		return payload.status;
	}

	if ( payload && payload.success === false ) {
		return 'error';
	}

	return 'success';
}

export default function ActionButton( { field } ) {
	const { _args = {} } = field;
	const { values, restRoot, pageId } = useSettings();

	const [ running, setRunning ] = useState( false );
	const [ result, setResult ] = useState( null );
	const [ confirmOpen, setConfirmOpen ] = useState( false );

	const endpoint = `${ restRoot }/action/${ pageId }/${ encodeURIComponent( field.id ) }`;

	const performAction = useCallback( async () => {
		setRunning( true );
		setResult( null );

		try {
			const response = await apiFetch( {
				path: endpoint,
				method: 'POST',
				data: { values },
			} );

			setResult( {
				status: resolveStatus( response ),
				message: response?.message || '',
				html: response?.html || '',
			} );
		} catch ( error ) {
			setResult( {
				status: 'error',
				message:
					error?.message ||
					__( 'Action failed.', 'wp-wireframe' ),
				html: '',
			} );
		} finally {
			setRunning( false );
		}
	}, [ endpoint, values ] );

	const handleClick = useCallback( () => {
		if ( _args.confirm ) {
			setConfirmOpen( true );
			return;
		}

		performAction();
	}, [ _args.confirm, performAction ] );

	const handleConfirm = useCallback( () => {
		setConfirmOpen( false );
		performAction();
	}, [ performAction ] );

	return (
		<BaseControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ field.description }
			id={ `wireframe-action-${ field.id }` }
		>
			<div className="wireframe-action">
				<Button
					__next40pxDefaultSize
					variant={ _args.variant || 'secondary' }
					isDestructive={ Boolean( _args.destructive ) }
					onClick={ handleClick }
					isBusy={ running }
					disabled={ running }
				>
					{ _args.button_label ||
						field.label ||
						__( 'Run', 'wp-wireframe' ) }
				</Button>

				{ result && ( result.message || result.html ) && (
					<div
						className={ `wireframe-action__result ${
							VARIANT_CLASSES[ result.status ] || ''
						}` }
						role={ result.status === 'error' ? 'alert' : 'status' }
					>
						{ result.message && (
							<p className="wireframe-action__message">
								{ result.message }
							</p>
						) }
						{ result.html && (
							<div className="wireframe-action__html">
								<RawHTML>{ result.html }</RawHTML>
							</div>
						) }
					</div>
				) }
			</div>

			{ confirmOpen && (
				<Modal
					title={
						_args.confirm_title ||
						__( 'Are you sure?', 'wp-wireframe' )
					}
					onRequestClose={ () => setConfirmOpen( false ) }
				>
					<p>{ _args.confirm }</p>
					<Flex justify="flex-end" gap={ 2 }>
						<FlexItem>
							<Button
								variant="tertiary"
								onClick={ () => setConfirmOpen( false ) }
							>
								{ __( 'Cancel', 'wp-wireframe' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button
								variant="primary"
								isDestructive={ Boolean( _args.destructive ) }
								onClick={ handleConfirm }
							>
								{ _args.confirm_button_label ||
									_args.button_label ||
									__( 'Run', 'wp-wireframe' ) }
							</Button>
						</FlexItem>
					</Flex>
				</Modal>
			) }
		</BaseControl>
	);
}
