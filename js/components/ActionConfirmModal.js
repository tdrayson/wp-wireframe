/**
 * ActionConfirmModal — the "Are you sure?" step for an action button.
 */
import { Button, Modal, Flex, FlexItem } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Render the confirm dialog for a button, or nothing when none is pending.
 *
 * @param {Object}      props
 * @param {Object|null} props.button    Button awaiting confirmation (with a resolved `confirm`).
 * @param {Function}    props.onConfirm Runs the action.
 * @param {Function}    props.onCancel  Dismisses the dialog.
 */
export default function ActionConfirmModal( { button, onConfirm, onCancel } ) {
	if ( ! button || ! button.confirm ) {
		return null;
	}

	return (
		<Modal
			title={ button.confirm.title || __( 'Are you sure?', 'wp-wireframe' ) }
			onRequestClose={ onCancel }
		>
			<p>{ button.confirm.message }</p>
			<Flex justify="flex-end" gap={ 2 }>
				<FlexItem>
					<Button variant="tertiary" onClick={ onCancel }>
						{ button.confirm.cancelLabel || __( 'Cancel', 'wp-wireframe' ) }
					</Button>
				</FlexItem>
				<FlexItem>
					<Button
						variant="primary"
						isDestructive={ button.destructive }
						onClick={ onConfirm }
					>
						{ button.confirm.buttonLabel || button.label }
					</Button>
				</FlexItem>
			</Flex>
		</Modal>
	);
}
