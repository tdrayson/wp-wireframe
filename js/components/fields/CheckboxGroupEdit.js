/**
 * CheckboxGroupEdit — multiple checkboxes for multi-select fields.
 *
 * Stores an array of selected option values.
 *
 * @param {Object}   props
 * @param {Object}   props.data     Current settings values.
 * @param {Object}   props.field    Mapped field descriptor with `elements`.
 * @param {Function} props.onChange  Callback receiving { fieldId: newValue }.
 * @param {string}   [props.error]  Validation error message.
 */
import { CheckboxControl, BaseControl } from '@wordpress/components';
import { getFieldHelp } from './useFieldError';

export default function CheckboxGroupEdit( { data, field, onChange, error } ) {
	const { _args = {} } = field;
	const value = Array.isArray( data[ field.id ] ) ? data[ field.id ] : ( field.defaultValue || [] );
	const options = field.elements || [];

	/**
	 * Toggle a single option in the selected values array.
	 *
	 * @param {string}  optionValue The value being toggled.
	 * @param {boolean} isChecked   Whether the checkbox was checked or unchecked.
	 */
	const handleChange = ( optionValue, isChecked ) => {
		const updatedValue = isChecked
			? [ ...value, optionValue ]
			: value.filter( ( selectedValue ) => selectedValue !== optionValue );

		onChange( { [ field.id ]: updatedValue } );
	};

	return (
		<BaseControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ getFieldHelp( field.description, error, data ) }
			id={ `wireframe-checkboxes-${ field.id }` }
			className={ error ? 'has-error' : undefined }
		>
			<div className="wireframe-checkbox-group">
				{ options.map( ( option ) => (
					<CheckboxControl
						__nextHasNoMarginBottom
						key={ option.value }
						label={ option.label }
						checked={ value.includes( option.value ) }
						onChange={ ( isChecked ) => handleChange( option.value, isChecked ) }
					/>
				) ) }
			</div>
		</BaseControl>
	);
}
