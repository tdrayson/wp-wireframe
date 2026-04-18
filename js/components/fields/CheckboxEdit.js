/**
 * CheckboxEdit — single checkbox for boolean fields.
 */
import { CheckboxControl } from '@wordpress/components';
import { getFieldHelp } from './useFieldError';

export default function CheckboxEdit( { data, field, onChange, error } ) {
	const { _args = {} } = field;
	const value = !! data[ field.id ];

	return (
		<CheckboxControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ getFieldHelp( field.description, error ) }
			checked={ value }
			onChange={ ( newVal ) => onChange( { [ field.id ]: newVal } ) }
			className={ error ? 'has-error' : undefined }
		/>
	);
}
