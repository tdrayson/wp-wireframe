/**
 * TextareaEdit — multi-line text input.
 */
import { TextareaControl } from '@wordpress/components';
import { getFieldHelp } from './useFieldError';

export default function TextareaEdit( { data, field, onChange, error } ) {
	const { _args = {} } = field;
	const value = data[ field.id ] ?? field.defaultValue ?? '';

	return (
		<TextareaControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ getFieldHelp( field.description, error ) }
			value={ value }
			onChange={ ( newVal ) => onChange( { [ field.id ]: newVal } ) }
			rows={ _args.rows || 4 }
			placeholder={ _args.placeholder || '' }
			className={ error ? 'has-error' : undefined }
		/>
	);
}
