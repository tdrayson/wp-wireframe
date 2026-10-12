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
import { Button, BaseControl, Flex, FlexItem } from '@wordpress/components';
import { RawHTML, useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useSettings } from '../../hooks/useSettings';
import { useActionRunner, resolveConfirm } from '../../hooks/useActionRunner';
import ActionConfirmModal from '../ActionConfirmModal';

const VARIANT_CLASSES = {
	success: 'wireframe-action__result--success',
	error: 'wireframe-action__result--error',
	warning: 'wireframe-action__result--warning',
	info: 'wireframe-action__result--info',
};

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

	const { restRoot, pageId } = useSettings();

	// Result panels are keyed by button id, so each button owns its own
	// slot. Clicking button A only ever updates A's panel — B's previous
	// rich result stays visible. A button's own subsequent click overwrites
	// (returns html → replaces) or clears (returns no html → removes) only
	// its own slot.
	const [ results, setResults ] = useState( {} );

	const pathFor = useCallback(
		( button ) =>
			`/${ restRoot }/action/${ pageId }/${ encodeURIComponent(
				field.id
			) }/${ encodeURIComponent( button.id ) }`,
		[ restRoot, pageId, field.id ]
	);

	const storeResult = useCallback( ( button, { status, message, html } ) => {
		setResults( ( previous ) => {
			const next = { ...previous };

			if ( html ) {
				next[ button.id ] = { status, message, html };
			} else {
				delete next[ button.id ];
			}

			return next;
		} );
	}, [] );

	const { runningId, confirmingButton, run, confirm, cancel } =
		useActionRunner( pathFor, storeResult );

	// Each button's text IS the accessible name of its action, so when
	// `field.label` is empty we deliberately omit BaseControl's <label>
	// element entirely (rather than passing an NBSP, which would render
	// an empty-but-present label in the a11y tree).
	const hasLabel = typeof field.label === 'string' && field.label !== '';

	// Opt-in spacer: reserves a label row's height so an *unlabelled* action
	// lines up with labelled siblings sharing the same row (e.g. a button next
	// to an input). Off by default — a standalone action shouldn't carry the
	// blank gap. Enable with `args.label_spacer => true`. `aria-hidden` keeps
	// it out of the a11y tree.
	const showLabelSpacer = ! hasLabel && !! args.label_spacer;

	return (
		<BaseControl
			__nextHasNoMarginBottom
			label={ hasLabel ? field.label : undefined }
			help={ field.description || undefined }
			id={ `wireframe-action-${ field.id }` }
		>
			<div className="wireframe-action">
				{ showLabelSpacer && (
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
								onClick={ () => run( button ) }
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

			<ActionConfirmModal
				button={ confirmingButton }
				onConfirm={ confirm }
				onCancel={ cancel }
			/>
		</BaseControl>
	);
}
