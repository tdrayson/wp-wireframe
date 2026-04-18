/**
 * CodeEditorEdit — CodeMirror-powered code editor.
 *
 * Uses wp.codeEditor (WordPress's bundled CodeMirror).
 * Falls back to a plain textarea if not available.
 */
import { BaseControl } from '@wordpress/components';
import { useEffect, useRef } from '@wordpress/element';

const MODE_MAP = {
	css: 'text/css',
	js: 'text/javascript',
	javascript: 'text/javascript',
	html: 'text/html',
	php: 'application/x-httpd-php',
	json: 'application/json',
	xml: 'application/xml',
	sql: 'text/x-sql',
};

export default function CodeEditorEdit( { data, field, onChange } ) {
	const { _args = {} } = field;
	const value = data[ field.id ] ?? field.defaultValue ?? '';
	const textareaRef = useRef( null );
	const editorRef = useRef( null );
	const onChangeRef = useRef( onChange );
	onChangeRef.current = onChange;

	useEffect( () => {
		if ( ! textareaRef.current ) {
			return;
		}

		if ( typeof wp === 'undefined' || ! wp.codeEditor?.initialize ) {
			return;
		}

		const mode = MODE_MAP[ _args.mode ] || 'text/plain';

		const settings = wp.codeEditor.defaultSettings
			? { ...wp.codeEditor.defaultSettings }
			: {};

		if ( settings.codemirror ) {
			settings.codemirror = {
				...settings.codemirror,
				mode,
			};
		}

		const instance = wp.codeEditor.initialize( textareaRef.current, settings );
		editorRef.current = instance;

		if ( instance.codemirror ) {
			instance.codemirror.on( 'change', () => {
				const content = instance.codemirror.getValue();
				onChangeRef.current( { [ field.id ]: content } );
			} );
		}

		return () => {
			if ( instance.codemirror ) {
				instance.codemirror.toTextArea();
			}
			editorRef.current = null;
		};
	}, [ field.id, _args.mode ] );

	return (
		<BaseControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ field.description }
			id={ `wireframe-code-${ field.id }` }
		>
			<textarea
				ref={ textareaRef }
				id={ `wireframe-code-${ field.id }` }
				defaultValue={ value }
				rows={ _args.rows || 10 }
				style={ { width: '100%', fontFamily: 'var(--wpds-typography-font-family-mono, monospace)', fontSize: 'var(--wpds-typography-font-size-md, 13px)' } }
			/>
		</BaseControl>
	);
}
