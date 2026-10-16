/**
 * Field store for one group on the classic edit screen, held in local state.
 */
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import coerce, { topLevelFields } from '../coerce';

/**
 * Normalise a PHP-encoded map, which arrives as `[]` when empty.
 *
 * @param {Object|Array|undefined} map Decoded map.
 * @return {Object} The map, or an empty object.
 */
function asObject( map ) {
	return map && ! Array.isArray( map ) ? map : {};
}

/**
 * Keep the errors that belong to a group's top-level fields.
 *
 * @param {Object} errors Errors by field id, from the last failed save.
 * @param {Object} config The group's normalised config.
 * @return {Object} The group's errors.
 */
function groupErrors( errors, config ) {
	const fields = topLevelFields( config );

	return Object.fromEntries(
		Object.entries( asObject( errors ) ).filter( ( [ id ] ) => fields[ id ] )
	);
}

/**
 * Provide `config`, `values`, `setMultiple` and `errors` for a group, mirroring edited values as JSON into its hidden input.
 *
 * Only edited keys are posted, as the block editor sends only changed meta; a new post posts everything it shows.
 *
 * @param {Object}           group   Group from `window.wireframePostFields.groups`, with its `values`.
 * @param {HTMLInputElement} input   The group's `wireframe_fields[{id}]` input.
 * @param {boolean}          isNew   Whether the post is an auto-draft.
 * @param {Object}           errors  Errors by field id from the last failed save.
 * @return {{ config: Object, values: Object, setMultiple: Function, errors: Object }} The field store.
 */
export default function useLocalFieldStore( group, input, isNew, errors ) {
	const [ values, setValues ] = useState( () => asObject( group.values ) );
	const [ edits, setEdits ] = useState( () =>
		isNew ? asObject( group.values ) : {}
	);
	const [ fieldErrors, setFieldErrors ] = useState( () =>
		groupErrors( errors, group.config )
	);

	useEffect( () => {
		input.value = JSON.stringify( edits );
	}, [ input, edits ] );

	const setMultiple = useCallback(
		( changes ) => {
			const coerced = coerce( changes, group.config );

			setValues( ( previous ) => ( { ...previous, ...coerced } ) );
			setEdits( ( previous ) => ( { ...previous, ...coerced } ) );
			setFieldErrors( ( previous ) => {
				const kept = { ...previous };

				Object.keys( changes ).forEach( ( id ) => delete kept[ id ] );

				return kept;
			} );
		},
		[ group.config ]
	);

	return useMemo(
		() => ( {
			config: group.config,
			values,
			setMultiple,
			errors: fieldErrors,
		} ),
		[ group.config, values, setMultiple, fieldErrors ]
	);
}
