/**
 * Field errors from the editor's last failed save, scoped to one group.
 */
import { useSelect } from '@wordpress/data';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { store as coreStore } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';
import { topLevelFields } from './coerce';

/**
 * Map a `validation_failed` save error onto a group's fields.
 *
 * Errors clear per field once it is edited, and all at once on the next save attempt.
 *
 * @param {Object} group Group from `window.wireframePostFields.groups`.
 * @return {{ errors: Object, clear: Function }} Errors by field id, and `clear( ids )`.
 */
export default function useSaveErrors( group ) {
	const saveError = useSelect( ( select ) => {
		const { getCurrentPostType, getCurrentPostId } = select( editorStore );

		return select( coreStore ).getLastEntitySaveError(
			'postType',
			getCurrentPostType(),
			getCurrentPostId()
		);
	}, [] );

	const [ cleared, setCleared ] = useState( [] );

	useEffect( () => setCleared( [] ), [ saveError ] );

	const errors = useMemo( () => {
		const all =
			saveError?.code === 'validation_failed'
				? saveError.data?.errors || {}
				: {};
		const fields = topLevelFields( group.config );

		return Object.fromEntries(
			Object.entries( all ).filter(
				( [ id ] ) => fields[ id ] && ! cleared.includes( id )
			)
		);
	}, [ saveError, cleared, group.config ] );

	const clear = useCallback( ( ids ) => {
		setCleared( ( previous ) => [ ...previous, ...ids ] );
	}, [] );

	return { errors, clear };
}
