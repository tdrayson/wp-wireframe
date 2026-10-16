/**
 * The location context of the post being edited, rebuilt as the editor's edits change.
 */
import { useMemo } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';

/**
 * Build the location context from the editor's edited post and the params PHP localised, non-REST taxonomies' terms included.
 *
 * @param {Function} select Registry `select`.
 * @param {Object}   data   `window.wireframePostFields`.
 * @return {Object} Values location rules are evaluated against.
 */
export function buildLiveContext( select, data ) {
	const { getEditedPostAttribute } = select( editorStore );
	const liveTerms = ( data.taxonomies || [] ).flatMap(
		( { restBase } ) => getEditedPostAttribute( restBase ) || []
	);
	const terms = [ ...( data.context?.post_terms || [] ), ...liveTerms ];

	return {
		...data.context,
		post_type: data.postType,
		post_id: data.postId,
		user_role: data.context?.user_role || [],
		post_status: getEditedPostAttribute( 'status' ) || '',
		post_template: getEditedPostAttribute( 'template' ) || '',
		post_terms: [ ...new Set( terms.map( Number ) ) ],
		post_parent: Number( getEditedPostAttribute( 'parent' ) ) || 0,
		post_format: getEditedPostAttribute( 'format' ) || 'standard',
	};
}

/**
 * Get the live location context, stable until one of its values changes.
 *
 * @param {Object} data `window.wireframePostFields`.
 * @return {Object} Values location rules are evaluated against.
 */
export default function useLiveContext( data ) {
	// Serialised so the term list, rebuilt on every call, doesn't make each store update a new context.
	const key = useSelect(
		( select ) => JSON.stringify( buildLiveContext( select, data ) ),
		[ data ]
	);

	return useMemo( () => JSON.parse( key ), [ key ] );
}
