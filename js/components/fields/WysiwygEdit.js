/**
 * WysiwygEdit — TinyMCE WYSIWYG editor.
 *
 * Wraps WordPress's bundled editor via `wp.editor.initialize()`, exposing its
 * native settings through the field's `args` so authors configure it the same
 * way they would `wp_editor()`:
 *
 *   args: {
 *     rows: 8,                  // textarea height
 *     media_buttons: false,     // hide the "Add Media" button
 *     wpautop: true,            // auto-paragraphs in the Visual tab
 *     tinymce: {                // Visual tab settings, or `false` to disable it
 *       toolbar1: 'bold italic | bullist numlist | link',
 *     },
 *     quicktags: {              // Text tab settings, or `false` to disable it
 *       buttons: 'strong,em,link',
 *     },
 *   }
 *
 * Set `tinymce: false` for a Text-only editor, or `quicktags: false` for a
 * Visual-only editor. Falls back to a plain textarea if wp.editor is missing.
 *
 * These are the only settings `wp.editor.initialize()` consumes; each of
 * `tinymce`/`quicktags` is merged over `wp.editor.getDefaultSettings()`, so a
 * partial config inherits WP's defaults (use TinyMCE-native keys like
 * `toolbar1`/`height` to customise). Note: WP only renders the Visual|Code tab
 * switcher and the "Add Media" button when BOTH tabs are enabled — disabling
 * either drops that chrome. (wp-admin/js/editor.js initialize().)
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
	const readOnly = !! field.readOnly;

	useEffect( () => {
		if ( readOnly ) {
			return;
		}

		if ( initializedRef.current || ! textareaRef.current ) {
			return;
		}

		if ( typeof wp === 'undefined' || ! wp.editor?.initialize ) {
			return;
		}

		initializedRef.current = true;

		const {
			wpautop = true,
			media_buttons: mediaButtons = true,
			tinymce: tinymceArg,
			quicktags: quicktagsArg,
		} = _args;

		// Visual (TinyMCE) tab. `tinymce: false` disables it for a Text-only
		// editor; an object is merged over the defaults so authors can set
		// `toolbar1`, `plugins`, etc. using TinyMCE's native keys.
		const tinymce =
			tinymceArg === false
				? false
				: {
						wpautop,
						...( tinymceArg && typeof tinymceArg === 'object'
							? tinymceArg
							: {} ),
						setup: ( editor ) => {
							editor.on( 'change keyup', () => {
								onChangeRef.current( {
									[ field.id ]: editor.getContent(),
								} );
							} );
						},
				  };

		// Text (Quicktags) tab. `quicktags: false` disables it for a
		// Visual-only editor; an object passes through native settings
		// (e.g. `{ buttons: 'strong,em,link' }`).
		const quicktags =
			quicktagsArg === false
				? false
				: quicktagsArg && typeof quicktagsArg === 'object'
				? quicktagsArg
				: true;

		wp.editor.initialize( editorId, {
			tinymce,
			quicktags,
			mediaButtons,
		} );

		// Capture edits made in the Text tab (or when the Visual tab is
		// disabled), where TinyMCE's change event never fires — quicktags
		// writes straight to the underlying textarea.
		const textarea = textareaRef.current;
		const handleTextareaInput = () => {
			onChangeRef.current( { [ field.id ]: textarea.value } );
		};
		textarea?.addEventListener( 'input', handleTextareaInput );

		return () => {
			textarea?.removeEventListener( 'input', handleTextareaInput );
			if ( wp.editor?.remove ) {
				wp.editor.remove( editorId );
			}
			initializedRef.current = false;
		};
	}, [ editorId, field.id, readOnly ] );

	return (
		<BaseControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ field.description }
			id={ editorId }
		>
			{ readOnly ? (
				<div
					className="wireframe-wysiwyg-readonly"
					// eslint-disable-next-line react/no-danger
					dangerouslySetInnerHTML={ { __html: value } }
				/>
			) : (
				<textarea
					ref={ textareaRef }
					id={ editorId }
					defaultValue={ value }
					rows={ _args.rows || 8 }
					style={ { width: '100%' } }
				/>
			) }
		</BaseControl>
	);
}
