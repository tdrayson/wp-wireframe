/**
 * HeaderActions — config-driven `header_actions` buttons, rendered beside Save/Reset.
 *
 * A button with `href` is a plain link. Any other button POSTs the in-flight
 * values to `{restRoot}/header_action/{pageId}/{id}`, which fires the
 * `{prefix}/header_action/{pageId}/{id}` filter and responds like an `action` field.
 */
import { Button, Modal } from '@wordpress/components';
import { RawHTML, useCallback, useState } from '@wordpress/element';
import { useSettings } from '../hooks/useSettings';
import { useActionRunner, resolveConfirm } from '../hooks/useActionRunner';
import ActionConfirmModal from './ActionConfirmModal';

/**
 * Normalize the configured actions, dropping any without an id or label.
 *
 * @param {Array} actions Raw `config.header_actions`.
 * @return {Array} Buttons ready to render.
 */
function resolveActions( actions ) {
	return ( Array.isArray( actions ) ? actions : [] )
		.filter( ( action ) => action?.id && action?.label )
		.map( ( action ) => ( {
			id: String( action.id ),
			label: action.label,
			variant: action.variant || 'secondary',
			destructive: Boolean( action.destructive ),
			href: action.href || '',
			target: action.target || undefined,
			confirm: resolveConfirm( action.confirm ),
		} ) );
}

export default function HeaderActions( { actions } ) {
	const { restRoot, pageId } = useSettings();
	const buttons = resolveActions( actions );
	const [ result, setResult ] = useState( null );

	const pathFor = useCallback(
		( button ) =>
			`/${ restRoot }/header_action/${ pageId }/${ encodeURIComponent( button.id ) }`,
		[ restRoot, pageId ]
	);

	// The header has no room for an inline panel, so rich `html` opens a modal.
	const showResult = useCallback( ( button, { html } ) => {
		setResult( html ? { title: button.label, html } : null );
	}, [] );

	const { runningId, confirmingButton, run, confirm, cancel } =
		useActionRunner( pathFor, showResult );

	return (
		<>
			{ buttons.map( ( button ) =>
				button.href ? (
					<Button
						key={ button.id }
						size="compact"
						variant={ button.variant }
						isDestructive={ button.destructive }
						href={ button.href }
						target={ button.target }
						rel={ button.target === '_blank' ? 'noopener noreferrer' : undefined }
					>
						{ button.label }
					</Button>
				) : (
					<Button
						key={ button.id }
						size="compact"
						variant={ button.variant }
						isDestructive={ button.destructive }
						onClick={ () => run( button ) }
						isBusy={ runningId === button.id }
						disabled={ runningId !== null }
					>
						{ button.label }
					</Button>
				)
			) }

			<ActionConfirmModal
				button={ confirmingButton }
				onConfirm={ confirm }
				onCancel={ cancel }
			/>

			{ result && (
				<Modal title={ result.title } onRequestClose={ () => setResult( null ) }>
					<RawHTML>{ result.html }</RawHTML>
				</Modal>
			) }
		</>
	);
}
