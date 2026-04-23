/**
 * Replace `{field_id}` tokens in a template string with values from `data`.
 *
 * Missing keys resolve to an empty string. Non-string templates pass through
 * unchanged so callers can hand in undefined/null safely.
 *
 * @param {string} template Template string containing `{key}` tokens.
 * @param {Object} data     Source object for token values.
 * @return {string} Interpolated string.
 */
export function interpolate( template, data ) {
	if ( typeof template !== 'string' || ! template.includes( '{' ) ) {
		return template;
	}

	const source = data || {};

	return template.replace( /\{(\w+)\}/g, ( _, key ) => {
		const value = source[ key ];
		return value == null ? '' : String( value );
	} );
}
