/**
 * CheckboxEdit — single checkbox for boolean fields.
 */
import { CheckboxControl } from '@wordpress/components';
import { getFieldHelp } from './useFieldError';

export default function CheckboxEdit( { data, field, onChange, error } ) {
	const value = !! data[ field.id ];
	const disabled = !! field.readOnly;

	return (
		<CheckboxControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ getFieldHelp( field.description, error, data ) }
			checked={ value }
			onChange={ disabled ? undefined : ( newVal ) => onChange( { [ field.id ]: newVal } ) }
			disabled={ disabled }
			className={ error ? 'has-error' : undefined }
		/>
	);
}
