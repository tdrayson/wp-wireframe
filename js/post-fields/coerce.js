/**
 * Coerce field component output to the REST meta schemas registered by MetaRegistrar.
 */

const BOOLEAN_TYPES = [ 'toggle', 'checkbox' ];
const NUMBER_TYPES = [ 'number', 'range' ];
const MULTI_TYPES = [ 'checkboxes', 'image_checkboxes' ];

/**
 * Index a config's top-level fields by id.
 *
 * @param {Object} config Normalised config (tabs → sections → fields).
 * @return {Object<string, Object>} Field configs by id.
 */
export function topLevelFields( config ) {
	const fields = {};

	for ( const tab of config?.tabs || [] ) {
		for ( const section of tab.sections || [] ) {
			for ( const field of section.fields || [] ) {
				fields[ field.id ] = field;
			}
		}
	}

	return fields;
}

/**
 * Whether a value is an empty field value (`''`, null or undefined).
 *
 * @param {*} value Value to test.
 * @return {boolean} True when blank.
 */
function isBlank( value ) {
	return value === '' || value === null || value === undefined;
}

/**
 * Normalise a value to a list of attachment IDs.
 *
 * @param {*} value An ID, a list of IDs, or blank.
 * @return {number[]} Positive attachment IDs.
 */
function toIds( value ) {
	const list = Array.isArray( value ) ? value : [ value ];

	return list.map( ( id ) => parseInt( id, 10 ) ).filter( ( id ) => id > 0 );
}

/**
 * Coerce one value to its field's schema.
 *
 * Returns `undefined` for a value a repeater row should omit: its row schema has no null.
 *
 * @param {*}       value Value from a field component.
 * @param {Object}  field Field config.
 * @param {boolean} inRow Whether the value is a repeater subfield.
 * @return {*} The coerced value, or undefined to omit it.
 */
function coerceValue( value, field, inRow ) {
	const type = field.type || 'text';

	if ( BOOLEAN_TYPES.includes( type ) ) {
		return Boolean( value );
	}

	if ( NUMBER_TYPES.includes( type ) ) {
		const number = isBlank( value ) ? NaN : Number( value );

		if ( ! Number.isNaN( number ) ) {
			return number;
		}

		return inRow ? undefined : null;
	}

	const multiple = Boolean( field.args?.multiple );

	if ( type === 'file' ) {
		const ids = toIds( isBlank( value ) ? [] : value );

		// Top-level single files are stored as one ID; inside rows the list is kept.
		return multiple || inRow ? ids : ids[ 0 ] ?? 0;
	}

	if ( MULTI_TYPES.includes( type ) || ( type === 'select' && multiple ) ) {
		return isBlank( value ) ? [] : value;
	}

	if ( type === 'repeater' ) {
		return Array.isArray( value )
			? value.map( ( row ) => coerceRow( row, field ) )
			: [];
	}

	return value;
}

/**
 * Coerce each subfield of a repeater row, dropping values the row schema can't hold.
 *
 * @param {Object} row      Row values by subfield id.
 * @param {Object} repeater Repeater field config.
 * @return {Object} The coerced row.
 */
function coerceRow( row, repeater ) {
	const coerced = { ...row };

	for ( const subfield of repeater.args?.subfields || [] ) {
		if ( ! ( subfield.id in coerced ) ) {
			continue;
		}

		const value = coerceValue( coerced[ subfield.id ], subfield, true );

		if ( value === undefined ) {
			delete coerced[ subfield.id ];
			continue;
		}

		coerced[ subfield.id ] = value;
	}

	return coerced;
}

/**
 * Coerce a set of edits to the REST meta schemas of a group's fields.
 *
 * @param {Object} edits  Values by field id, as field components send them.
 * @param {Object} config The group's normalised config.
 * @return {Object} Edits ready for `editPost( { meta } )`.
 */
export default function coerce( edits, config ) {
	const fields = topLevelFields( config );
	const coerced = {};

	for ( const [ id, value ] of Object.entries( edits ) ) {
		coerced[ id ] = fields[ id ]
			? coerceValue( value, fields[ id ], false )
			: value;
	}

	return coerced;
}
