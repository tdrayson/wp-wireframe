/**
 * RadioEdit — radio button group for single-choice fields.
 */
import { RadioControl } from '@wordpress/components';
import { getFieldHelp } from './useFieldError';
import { optionValue } from '../../utils/populate';

export default function RadioEdit( { data, field, onChange, error } ) {
	const value = optionValue(
		field.elements,
		data[ field.id ] ?? field.defaultValue ?? ''
	);
	const disabled = !! field.readOnly;

	const options = ( field.elements || [] ).map( ( el ) => ( {
		label: el.label,
		value: el.value,
		disabled,
	} ) );

	return (
		<RadioControl
			label={ field.label }
			help={ getFieldHelp( field.description, error, data ) }
			selected={ value }
			options={ options }
			onChange={
				disabled
					? undefined
					: ( newVal ) =>
							onChange( {
								[ field.id ]: optionValue(
									field.elements,
									newVal
								),
							} )
			}
			className={ `${ error ? 'has-error' : '' } ${ disabled ? 'is-readonly' : '' }`.trim() || undefined }
		/>
	);
}
