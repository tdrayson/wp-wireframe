/**
 * SelectEdit — dropdown select field.
 *
 * Supports single select (default) and multi-select via `args.multiple`.
 * When `multiple: true`, renders a searchable token/tag input instead
 * of a native dropdown. Stored as a string (single) or array (multi).
 *
 * @param {Object}   props
 * @param {Object}   props.data     Current settings values.
 * @param {Object}   props.field    Mapped field descriptor with `elements`.
 * @param {Function} props.onChange  Callback receiving { fieldId: newValue }.
 * @param {string}   [props.error]  Validation error message.
 */
import { SelectControl, FormTokenField, BaseControl } from '@wordpress/components';
import { useMemo } from '@wordpress/element';
import { getFieldHelp } from './useFieldError';

export default function SelectEdit( { data, field, onChange, error } ) {
	const { _args = {} } = field;
	const isMultiple = Boolean( _args.multiple );

	if ( isMultiple ) {
		return (
			<MultiSelect
				data={ data }
				field={ field }
				onChange={ onChange }
				error={ error }
			/>
		);
	}

	const value = data[ field.id ] ?? field.defaultValue ?? '';

	const options = ( field.elements || [] ).map( ( element ) => ( {
		label: element.label,
		value: element.value,
	} ) );

	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ field.label }
			help={ getFieldHelp( field.description, error, data ) }
			value={ value }
			options={ options }
			onChange={ ( newValue ) => onChange( { [ field.id ]: newValue } ) }
			className={ error ? 'has-error' : undefined }
		/>
	);
}

/**
 * MultiSelect — searchable token/tag input for multiple selections.
 *
 * Uses FormTokenField internally. Converts between stored keys
 * and display labels.
 */
function MultiSelect( { data, field, onChange, error } ) {
	const { _args = {} } = field;
	const options = field.elements || [];

	const selectedKeys = Array.isArray( data[ field.id ] )
		? data[ field.id ]
		: ( field.defaultValue || [] );

	const { keyToLabel, labelToKey, allLabels } = useMemo( () => {
		const keyMap = {};
		const labelMap = {};

		for ( const option of options ) {
			keyMap[ option.value ] = option.label;
			labelMap[ option.label ] = option.value;
		}

		return {
			keyToLabel: keyMap,
			labelToKey: labelMap,
			allLabels: options.map( ( option ) => option.label ),
		};
	}, [ options ] );

	const displayTokens = selectedKeys
		.map( ( key ) => keyToLabel[ key ] )
		.filter( Boolean );

	const handleChange = ( tokens ) => {
		const newKeys = tokens
			.map( ( label ) => labelToKey[ label ] )
			.filter( Boolean );

		onChange( { [ field.id ]: newKeys } );
	};

	return (
		<BaseControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ getFieldHelp( field.description, error, data ) }
			id={ `wireframe-multiselect-${ field.id }` }
			className={ error ? 'has-error' : undefined }
		>
			<FormTokenField
				__next40pxDefaultSize
				__experimentalShowHowTo={ false }
				__experimentalExpandOnFocus
				__experimentalAutoSelectFirstMatch
				value={ displayTokens }
				suggestions={ allLabels }
				onChange={ handleChange }
				placeholder={ _args.placeholder || '' }
				maxLength={ _args.max ? Number( _args.max ) : undefined }
			/>
		</BaseControl>
	);
}
