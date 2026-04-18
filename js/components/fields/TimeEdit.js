/**
 * TimeEdit — time input field using the native HTML time input.
 *
 * Stores the value as HH:MM (24-hour format).
 *
 * @param {Object}   props
 * @param {Object}   props.data     Current settings values.
 * @param {Object}   props.field    Mapped field descriptor.
 * @param {Function} props.onChange  Callback receiving { fieldId: newValue }.
 * @param {string}   [props.error]  Validation error message.
 */
import { TextControl } from '@wordpress/components';
import { getFieldHelp } from './useFieldError';

export default function TimeEdit( { data, field, onChange, error } ) {
	const { _args = {} } = field;
	const value = data[ field.id ] ?? field.defaultValue ?? '';

	return (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ field.label }
			help={ getFieldHelp( field.description, error ) }
			type="time"
			value={ value }
			onChange={ ( newValue ) => onChange( { [ field.id ]: newValue } ) }
			className={ error ? 'has-error' : undefined }
		/>
	);
}
