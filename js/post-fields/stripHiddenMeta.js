/**
 * Keeps the edits of groups whose location stopped matching out of a save, since REST refuses to write their fields.
 */
import { select } from '@wordpress/data';
import { addFilter } from '@wordpress/hooks';
import { buildLiveContext } from './useLiveContext';
import { groupApplies } from './useGroupApplies';
import { topLevelFields } from './coerce';

/**
 * Drop the meta of groups that don't apply to the post as it is being saved; the edits stay in the editor.
 *
 * @param {Object} data `window.wireframePostFields`.
 */
export default function stripHiddenMeta( data ) {
	addFilter( 'editor.preSavePost', 'wp-wireframe/post-fields', ( edits ) => {
		if ( ! edits.meta ) {
			return edits;
		}

		const context = buildLiveContext( select, data );
		const hidden = data.groups
			.filter( ( group ) => ! groupApplies( group, context ) )
			.flatMap( ( group ) =>
				Object.keys( topLevelFields( group.config ) )
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
