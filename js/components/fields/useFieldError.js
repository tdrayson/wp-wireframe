/**
 * Field help text resolver.
 *
 * Combines the field description with a validation error message.
 * If an error is present, it renders below the description in red.
 *
 * Descriptions may include `{field_id}` tokens which are interpolated
 * against the current form `data` (e.g. "URL: https://example.com/{slug}").
 *
 * @module getFieldHelp
 */
import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { interpolate } from '../../utils/interpolate';

/**
 * Build the `help` prop value for a WordPress field component.
 *
 * @param {string|undefined} description The field's help text from config.
 * @param {string|undefined} error       A validation error message, if any.
 * @param {Object}           [data]      Current form values, used to resolve `{field_id}` tokens.
 * @return {import('@wordpress/element').ReactNode|undefined}
 */
export function getFieldHelp( description, error, data ) {
	const resolved = interpolate( description, data );

	if ( ! resolved && ! error ) {
		return undefined;
	}

	if ( ! error ) {
		return resolved;
	}

	const errorElement = createElement(
		'span',
		{
			className: 'wireframe-field-error',
			role: 'alert',
		},
		typeof error === 'string' ? error : __( 'Invalid value.', 'wp-wireframe' )
	);

	if ( ! resolved ) {
		return errorElement;
	}

	return createElement( Fragment, null, resolved, createElement( 'br' ), errorElement );
}
