/**
 * CSS `grid-column` value for a field cell in the 12-column grid.
 *
 * @param {Object} fieldConfig Field config; reads `columns` and `new_row`.
 * @return {string} `span N`, or `1 / span N` when `new_row` is set.
 */
export function gridColumn( fieldConfig ) {
	const span = `span ${ fieldConfig.columns || 12 }`;
	return fieldConfig.new_row ? `1 / ${ span }` : span;
}
