/**
 * HtmlDisplay — read-only HTML display block.
 *
 * Supports variants: plain, info, success, warning, error.
 */
import { RawHTML } from '@wordpress/element';

const VARIANT_CLASSES = {
	plain: 'wireframe-html--plain',
	info: 'wireframe-html--info',
	success: 'wireframe-html--success',
	warning: 'wireframe-html--warning',
	error: 'wireframe-html--error',
};

export default function HtmlDisplay( { field } ) {
	const { _args = {} } = field;
	const variant = _args.variant || 'plain';
	const content = _args.content || '';

	return (
		<div className={ `wireframe-html ${ VARIANT_CLASSES[ variant ] || '' }` }>
			<RawHTML>{ content }</RawHTML>
		</div>
	);
}
