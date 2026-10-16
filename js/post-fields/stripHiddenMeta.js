/**
 * Keeps the edits of groups whose location stopped matching out of a save, since REST refuses to write their fields.
 */
import { useEffect, useRef } from '@wordpress/element';
import { select, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { addFilter, applyFiltersAsync } from '@wordpress/hooks';
import { buildLiveContext } from './useLiveContext';
import { groupApplies } from './useGroupApplies';
import { topLevelFields } from './coerce';

// `editor.preSavePost` shipped in WP 6.7, run through the async hooks that arrived with it.
const HAS_PRE_SAVE = typeof applyFiltersAsync === 'function';

/**
 * Get the meta keys of groups' top-level fields.
 *
 * @param {Object[]} groups Groups from `window.wireframePostFields.groups`.
 * @return {string[]} Meta keys.
 */
function metaKeys( groups ) {
	return groups.flatMap( ( group ) =>
		Object.keys( topLevelFields( group.config ) )
	);
}

/**
 * Drop the meta of groups that don't apply to the post as it is being saved; the edits stay in the editor (WP 6.7+).
 *
 * @param {Object} data `window.wireframePostFields`.
 */
export default function stripHiddenMeta( data ) {
	if ( ! HAS_PRE_SAVE ) {
		return;
	}

	addFilter( 'editor.preSavePost', 'wp-wireframe/post-fields', ( edits ) => {
		if ( ! edits.meta ) {
			return edits;
		}

		const context = buildLiveContext( select, data );
		const hidden = metaKeys(
			data.groups.filter( ( group ) => ! groupApplies( group, context ) )
		);

		if ( hidden.length === 0 ) {
			return edits;
		}

		const meta = Object.fromEntries(
			Object.entries( edits.meta ).filter(
				( [ key ] ) => ! hidden.includes( key )
			)
		);

		return { ...edits, meta };
	} );
}

/**
 * Before WP 6.7, revert a group's unsaved meta edits to the saved values when it stops applying.
 *
 * @param {Object[]} applying Groups that apply now.
 */
export function useRevertHiddenEdits( applying ) {
	const { editPost } = useDispatch( editorStore );
	const previous = useRef( applying );

	useEffect( () => {
		const ids = applying.map( ( group ) => group.id );
		const hidden = previous.current.filter(
			( group ) => ! ids.includes( group.id )
		);

		previous.current = applying;

		if ( HAS_PRE_SAVE || hidden.length === 0 ) {
			return;
		}

		const saved = select( editorStore ).getCurrentPostAttribute( 'meta' );
		const keys = metaKeys( hidden ).filter(
			( key ) => key in ( saved || {} )
		);

		if ( keys.length > 0 ) {
			editPost( {
				meta: Object.fromEntries(
					keys.map( ( key ) => [ key, saved[ key ] ] )
				),
			} );
		}
	}, [ applying, editPost ] );
}
