/**
 * RadioEdit — radio button group for single-choice fields.
 */
import { RadioControl } from '@wordpress/components';
import { getFieldHelp } from './useFieldError';

export default function RadioEdit( { data, field, onChange, error } ) {
	const { _args = {} } = field;
	const value = data[ field.id ] ?? field.defaultValue ?? '';

	const options = ( field.elements || [] ).map( ( el ) => ( {
		label: el.label,
		value: el.value,
	} ) );

	return (
		<RadioControl
			label={ field.label }
			help={ getFieldHelp( field.description, error, data ) }
			selected={ value }
			options={ options }
			onChange={ ( newVal ) => onChange( { [ field.id ]: newVal } ) }
			className={ error ? 'has-error' : undefined }
		/>
	);
}
