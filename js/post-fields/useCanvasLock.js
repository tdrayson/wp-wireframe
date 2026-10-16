/**
 * Template locking for the block canvas a `content` group replaces.
 */
import { useEffect, useRef } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';

/**
 * Keep the canvas fully locked while a `content` group applies, and give back its own lock when none does.
 *
 * @param {boolean} locked Whether a `content` group applies.
 */
export default function useCanvasLock( locked ) {
	const current = useSelect(
		( select ) => select( editorStore ).getEditorSettings().templateLock,
		[]
	);
	const { updateEditorSettings } = useDispatch( editorStore );
	const unlocked = useRef( current === 'all' ? false : current ?? false );
	const wanted = locked ? 'all' : unlocked.current;

	useEffect( () => {
		if ( current !== wanted ) {
			updateEditorSettings( { templateLock: wanted } );
		}
	}, [ current, wanted, updateEditorSettings ] );
}
