/**
 * The `wireframe/field` block bindings source, read-only, formatted like the PHP `Bindings` source.
 */
import {
	getBlockBindingsSource,
	getBlockType,
	registerBlockBindingsSource,
} from '@wordpress/blocks';
import { escapeHTML } from '@wordpress/escape-html';
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';
import { topLevelFields } from './coerce';

const SOURCE = 'wireframe/field';
const CHOICE_TYPES = [
	'select',
	'radio',
	'checkboxes',
	'image_radio',
	'image_checkboxes',
];

/**
 * Index every group's fields by id, repeater subfields as `{id}.{sub}`.
 *
 * @param {Array} groups Groups from `window.wireframePostFields`.
 * @return {Object<string, Object>} Field configs.
 */
function indexFields( groups ) {
	const fields = {};

	for ( const group of groups ) {
		for ( const [ id, field ] of Object.entries(
			topLevelFields( group.config )
		) ) {
			fields[ id ] = field;

			for ( const sub of field.args?.subfields || [] ) {
				fields[ `${ id }.${ sub.id }` ] = sub;
			}
		}
	}

	return fields;
}

/**
 * Get the config to format a key with, or null unless its top-level field is public and the key names a real field.
 *
 * @param {Object<string, Object>} fields Fields from indexFields().
 * @param {string}                 key    Field id or dot path.
 * @return {Object|null} Field config; the subfield's for a dot path.
 */
function resolveField( fields, key ) {
	const parts = key.split( '.' );
	const top = fields[ parts[ 0 ] ];

	if ( ! top || ! top.public ) {
		return null;
	}

	if ( parts.length === 1 ) {
		return top;
	}

	if ( parts.length > 3 ) {
		return null;
	}

	return fields[ `${ parts[ 0 ] }.${ parts[ parts.length - 1 ] }` ] || null;
}

/**
 * Read a key's value from the edited meta; a dot path walks into the repeater's rows.
 *
 * @param {Object<string, *>} meta  Edited post meta.
 * @param {string}            key   Field id or dot path.
 * @param {Object}            field Config of the value's field.
 * @return {*} Value, or the field default when unset.
 */
function readValue( meta, key, field ) {
	const [ top, ...path ] = key.split( '.' );
	const rows = Array.isArray( meta[ top ] ) ? meta[ top ] : [];
	let value = meta[ top ];

	if ( path.length > 0 ) {
		const [ row, sub ] = path.length === 2 ? path : [ '0', path[ 0 ] ];
		value = rows[ Number( row ) ]?.[ sub ];
	}

	const empty = value === undefined || value === null || value === '';

	return empty ? field.default ?? '' : value;
}

/**
 * Get the labels of a choice field's selected values.
 *
 * @param {Object} field Choice field config.
 * @param {*}      value Selected value or values.
 * @return {string[]} Labels.
 */
function choiceLabels( field, value ) {
	const options = field.args?.options || {};
	const labelOf = ( option ) =>
		typeof option === 'object' && option !== null ? option.label : option;
	const lookup = ( item ) => {
		if ( Array.isArray( options ) ) {
			return options.find(
				( option ) => String( option.value ) === String( item )
			)?.label;
		}

		return labelOf( options[ item ] );
	};

	return [ value ?? [] ]
		.flat()
		.filter( ( item ) => item !== '' )
		.map( ( item ) => String( lookup( item ) ?? item ) );
}

/**
 * Get the URLs of a file field's attachments, the first alone for a `url` attribute.
 *
 * @param {*}        value     Attachment ID or IDs.
 * @param {string}   attribute Bound attribute.
 * @param {Function} select    Registry select.
 * @return {string} URLs.
 */
function fileUrls( value, attribute, select ) {
	const urls = [ value ?? [] ]
		.flat()
		.filter( Boolean )
		.map(
			( id ) =>
				select( 'core' ).getEntityRecord( 'postType', 'attachment', id )
					?.source_url
		)
		.filter( Boolean );

	return attribute === 'url' ? urls[ 0 ] ?? '' : urls.join( ', ' );
}

/**
 * Format a stored date or time with the given or the site's format.
 *
 * @param {string} type   `date` or `time`.
 * @param {*}      value  Stored `Y-m-d` or `H:i`.
 * @param {string} format PHP date format; the site's when empty.
 * @return {string} Text.
 */
function moment( type, value, format ) {
	if ( typeof value !== 'string' || value === '' ) {
		return '';
	}

	const formats = getDateSettings().formats;
	const date = type === 'date' ? value : `1970-01-01T${ value }`;

	return dateI18n( format || formats[ type ], date );
}

/**
 * Coerce a stored value like PHP's `FILTER_VALIDATE_BOOLEAN`: "1", "true", "on" and "yes" are true.
 *
 * @param {*} value Stored toggle value.
 * @return {boolean} Whether it reads as on.
 */
function toBoolean( value ) {
	if ( typeof value === 'string' ) {
		return [ '1', 'true', 'on', 'yes' ].includes(
			value.trim().toLowerCase()
		);
	}

	return typeof value === 'number' ? value === 1 : Boolean( value );
}

/**
 * Whether the attribute is HTML in the block's markup, so plain text must be escaped for it.
 *
 * @param {Object} select    Registry select.
 * @param {string} clientId  Bound block's client ID.
 * @param {string} attribute Bound attribute.
 * @return {boolean} True for `html` and `rich-text` attributes.
 */
function isMarkup( select, clientId, attribute ) {
	const name = select( 'core/block-editor' ).getBlockName( clientId );
	const source = getBlockType( name )?.attributes?.[ attribute ]?.source;

	return source === 'html' || source === 'rich-text';
}

/**
 * Format a value as plain text, as `ValueFormatter::text()` does.
 *
 * @param {Object}   field     Field config.
 * @param {*}        value     Field value.
 * @param {string}   attribute Bound attribute.
 * @param {string}   format    Optional PHP date format, dates only.
 * @param {Function} select    Registry select.
 * @return {string} Text.
 */
function formatValue( field, value, attribute, format, select ) {
	const type = field.type || 'text';

	if ( type === 'date' ) {
		return moment( 'date', value, format );
	}

	if ( type === 'time' ) {
		return moment( 'time', value );
	}

	if ( type === 'toggle' || type === 'checkbox' ) {
		return toBoolean( value )
			? __( 'Yes', 'wp-wireframe' )
			: __( 'No', 'wp-wireframe' );
	}

	if ( type === 'repeater' ) {
		const count = Array.isArray( value ) ? value.length : 0;

		if ( count === 0 ) {
			return '';
		}

		return sprintf(
			// translators: %d: number of repeater rows.
			_n( '%d row', '%d rows', count, 'wp-wireframe' ),
			count
		);
	}

	if ( type === 'file' ) {
		return fileUrls( value, attribute, select );
	}

	if ( CHOICE_TYPES.includes( type ) ) {
		return choiceLabels( field, value ).join( ', ' );
	}

	return [ value ?? [] ]
		.flat()
		.filter( ( item ) => [ 'string', 'number' ].includes( typeof item ) )
		.join( ', ' );
}

/**
 * Whether another bundle already gave the source its client side; core bootstraps the server's label alone.
 *
 * @return {boolean} True when the source has `getValues`.
 */
function isRegistered() {
	return (
		typeof getBlockBindingsSource === 'function' &&
		typeof getBlockBindingsSource( SOURCE )?.getValues === 'function'
	);
}

/**
 * Register the source on WP 6.7+, reading values from the editor store so bound blocks preview live.
 *
 * @param {Array} groups Groups from `window.wireframePostFields`.
 */
export default function registerBindings( groups ) {
	if ( typeof registerBlockBindingsSource !== 'function' || isRegistered() ) {
		return;
	}

	const fields = indexFields( groups );

	registerBlockBindingsSource( {
		name: SOURCE,
		label: __( 'Wireframe field', 'wp-wireframe' ),
		usesContext: [ 'postId', 'postType' ],
		getValues( { bindings, clientId, context, select } ) {
			const editor = select( 'core/editor' );
			const meta = editor.getEditedPostAttribute( 'meta' );
			const isCurrent =
				! context.postId ||
				context.postId === editor.getCurrentPostId();
			const values = {};

			for ( const [ attribute, binding ] of Object.entries( bindings ) ) {
				const key = binding.args?.key;
				const field =
					typeof key === 'string'
						? resolveField( fields, key )
						: null;

				if ( ! field ) {
					values[ attribute ] = '';
				} else if ( ! meta || ! isCurrent ) {
					values[ attribute ] = field.label || key;
				} else {
					const text = formatValue(
						field,
						readValue( meta, key, field ),
						attribute,
						binding.args.format,
						select
					);

					values[ attribute ] = isMarkup(
						select,
						clientId,
						attribute
					)
						? escapeHTML( text )
						: text;
				}
			}

			return values;
		},
		canUserEditValue: () => false,
	} );
}
