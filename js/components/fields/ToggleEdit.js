/**
 * ToggleEdit — toggle switch for boolean fields.
 */
import { ToggleControl } from '@wordpress/components';
import { getFieldHelp } from './useFieldError';

export default function ToggleEdit( { data, field, onChange, error } ) {
	const value = !! data[ field.id ];
	const disabled = !! field.readOnly;

	return (
		<ToggleControl
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
