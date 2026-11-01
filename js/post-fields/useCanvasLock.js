/**
 * Template locking for the block canvas a `content` group replaces.
 */
import { useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';

/**
 * On a post type with a `content` group, lock the canvas fully while it applies and restore the type's own lock otherwise.
 *
 * @param {Object}  data   `window.wireframePostFields`.
 * @param {boolean} locked Whether a `content` group applies.
 */
export default function useCanvasLock( data, locked ) {
	const current = useSelect(
		( select ) => select( editorStore ).getEditorSettings().templateLock,
		[]
	);
	const { updateEditorSettings } = useDispatch( editorStore );
	const controls = data.groups.some(
		( group ) => group.position === 'content'
	);
	const wanted = locked ? 'all' : data.nativeLock ?? false;

	useEffect( () => {
		if ( controls && current !== wanted ) {
			updateEditorSettings( { templateLock: wanted } );
		}
	}, [ controls, current, wanted, updateEditorSettings ] );
}
