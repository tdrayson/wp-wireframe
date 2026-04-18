/**
 * WysiwygEdit — TinyMCE WYSIWYG editor.
 *
 * Uses wp.editor (WordPress's bundled TinyMCE) initialized
 * into a textarea element. Falls back to a plain textarea if
 * wp.editor is not available.
 */
import { BaseControl } from '@wordpress/components';
import { useEffect, useRef } from '@wordpress/element';

export default function WysiwygEdit( { data, field, onChange } ) {
	const { _args = {} } = field;
	const value = data[ field.id ] ?? field.defaultValue ?? '';
	const editorId = `wireframe-editor-${ field.id }`;
	const textareaRef = useRef( null );
	const initializedRef = useRef( false );
	const onChangeRef = useRef( onChange );
	onChangeRef.current = onChange;

	useEffect( () => {
		if ( initializedRef.current || ! textareaRef.current ) {
			return;
		}

		if ( typeof wp === 'undefined' || ! wp.editor?.initialize ) {
			return;
		}

		initializedRef.current = true;

		wp.editor.initialize( editorId, {
			tinymce: {
				wpautop: true,
				setup: ( editor ) => {
					editor.on( 'change keyup', () => {
						const content = editor.getContent();
						onChangeRef.current( { [ field.id ]: content } );
					} );
				},
			},
			quicktags: true,
			mediaButtons: true,
		} );

		return () => {
			if ( wp.editor?.remove ) {
				wp.editor.remove( editorId );
			}
			initializedRef.current = false;
		};
	}, [ editorId, field.id ] );

	return (
		<BaseControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ field.description }
			id={ editorId }
		>
			<textarea
				ref={ textareaRef }
				id={ editorId }
				defaultValue={ value }
				rows={ _args.rows || 8 }
				style={ { width: '100%' } }
			/>
		</BaseControl>
	);
}
