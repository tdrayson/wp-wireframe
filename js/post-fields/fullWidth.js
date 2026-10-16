/**
 * Single-column configs for narrow sidebar panels and boxes.
 */

/**
 * Drop `columns` from every field, since a sidebar is too narrow for a grid.
 *
 * @param {Object} config Normalised config.
 * @return {Object} The config without `columns`.
 */
export default function fullWidth( config ) {
	return {
		...config,
		tabs: ( config.tabs || [] ).map( ( tab ) => ( {
			...tab,
			sections: ( tab.sections || [] ).map( ( section ) => ( {
				...section,
				fields: ( section.fields || [] ).map(
					( { columns, ...field } ) => field
				),
			} ) ),
		} ) ),
	};
}
