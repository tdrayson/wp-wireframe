/**
 * Field help text resolver.
 *
 * Combines the field description with a validation error message.
 * If an error is present, it renders below the description in red.
 *
 * @module getFieldHelp
 */
import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Build the `help` prop value for a WordPress field component.
 *
 * @param {string|undefined} description The field's help text from config.
 * @param {string|undefined} error       A validation error message, if any.
 * @return {import('@wordpress/element').ReactNode|undefined}
 */
export function getFieldHelp( description, error ) {
	if ( ! description && ! error ) {
		return undefined;
	}

	if ( ! error ) {
		return description;
	}

	const errorElement = createElement(
		'span',
		{
			className: 'wireframe-field-error',
			role: 'alert',
		},
		typeof error === 'string' ? error : __( 'Invalid value.', 'wp-wireframe' )
	);

	if ( ! description ) {
		return errorElement;
	}

	return createElement( Fragment, null, description, createElement( 'br' ), errorElement );
}
