/**
 * Helpers for populated option lists: the search route and typed option values.
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Search a `fetch` field's options through `GET {prefix}/v1/populate`.
 *
 * @param {Object} params
 * @param {string} params.prefix  REST prefix of the page or field group.
 * @param {string} params.context `settings:{pageId}` or `post:{postType}`.
 * @param {string} params.field   Field id, or `{repeater}.{subfield}`.
 * @param {string} params.search  Search term.
 * @return {Promise<Array<{value: (number|string), label: string}>>} Matching options, at most 20.
 */
export function searchOptions( { prefix, context, field, search } ) {
	const query = new URLSearchParams( { context, field, search } );

	return apiFetch( { path: `/${ prefix }/v1/populate?${ query }` } );
}

/**
 * Add options to a list, skipping values it already has.
 *
 * @param {Array<{value: *, label: string}>} known Options so far.
 * @param {Array<{value: *, label: string}>} found Options to add.
 * @return {Array<{value: *, label: string}>} The merged list.
 */
export function mergeOptions( known, found ) {
	const seen = new Set( known.map( ( option ) => String( option.value ) ) );

	return [
		...known,
		...found.filter( ( option ) => ! seen.has( String( option.value ) ) ),
	];
}

/**
 * Map a control's string value back to its option's value, which keeps the stored type.
 *
 * @param {Array<{value: *}>} options Field elements.
 * @param {*}                 raw     Value from the control.
 * @return {*} The option's value, or `raw` when no option matches.
 */
export function optionValue( options = [], raw ) {
	const match = options.find(
		( option ) => String( option.value ) === String( raw )
	);

	return match ? match.value : raw;
}
