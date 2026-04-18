/**
 * ToggleEdit — toggle switch for boolean fields.
 */
import { ToggleControl } from '@wordpress/components';
import { getFieldHelp } from './useFieldError';

export default function ToggleEdit( { data, field, onChange, error } ) {
	const { _args = {} } = field;
	const value = !! data[ field.id ];

	return (
		<ToggleControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ getFieldHelp( field.description, error ) }
			checked={ value }
			onChange={ ( newVal ) => onChange( { [ field.id ]: newVal } ) }
			className={ error ? 'has-error' : undefined }
		/>
	);
}
