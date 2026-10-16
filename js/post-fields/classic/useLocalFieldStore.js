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
 * Keep the entries of a map keyed by field id that belong to a group's top-level fields.
 *
 * @param {Object} map    Entries by field id, such as errors or rejected values from the last failed save.
 * @param {Object} config The group's normalised config.
 * @return {Object} The group's entries.
 */
function ownEntries( map, config ) {
	const fields = topLevelFields( config );

	return Object.fromEntries(
		Object.entries( asObject( map ) ).filter( ( [ id ] ) => fields[ id ] )
	);
}

/**
 * Provide `config`, `values`, `setMultiple` and `errors` for a group, mirroring edited values as JSON into its hidden input.
 *
 * Values start from everything the user can read, so conditions see other groups' fields as the block editor does.
 * Only edited keys are posted, as the block editor sends only changed meta; a new post posts everything it shows,
 * and values a failed save rejected start out edited.
 *
 * @param {Object}           group           Group from `window.wireframePostFields.groups`, with its `values`.
 * @param {HTMLInputElement} input           The group's `wireframe_fields[{key}]` input.
 * @param {Object}           screen          Screen-wide data from the payload.
 * @param {Object}           screen.readable Every value the user can read, by field id.
 * @param {boolean}          screen.isNew    Whether the post is an auto-draft.
 * @param {Object}           screen.errors   Errors by field id from the last failed save.
 * @param {Object}           screen.rejected Values by field id the last failed save did not write.
 * @param {string}           screen.postType Post type being edited.
 * @return {{ config: Object, values: Object, setMultiple: Function, errors: Object, populate: Object }} The field store.
 */
export default function useLocalFieldStore(
	group,
	input,
	{ readable, isNew, errors, rejected, postType }
) {
	const [ values, setValues ] = useState( () => ( {
		...asObject( readable ),
		...asObject( group.values ),
	} ) );
	const [ edits, setEdits ] = useState( () =>
		isNew ? asObject( group.values ) : ownEntries( rejected, group.config )
	);
	const [ fieldErrors, setFieldErrors ] = useState( () =>
		ownEntries( errors, group.config )
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

	const populate = useMemo(
		() => ( { prefix: group.prefix, context: `post:${ postType }` } ),
		[ group.prefix, postType ]
	);

	return useMemo(
		() => ( {
			config: group.config,
			values,
			setMultiple,
			errors: fieldErrors,
			populate,
		} ),
		[ group.config, values, setMultiple, fieldErrors, populate ]
	);
}
