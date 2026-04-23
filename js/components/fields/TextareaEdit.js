/**
 * TextareaEdit — multi-line text input.
 */
import { TextareaControl } from '@wordpress/components';
import { getFieldHelp } from './useFieldError';
import CopyButton from './CopyButton';

export default function TextareaEdit( { data, field, onChange, error } ) {
	const { _args = {} } = field;
	const value = data[ field.id ] ?? field.defaultValue ?? '';
	const readOnly = !! _args.readonly;
	const copyable = !! _args.copyable;

	const control = (
		<TextareaControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ getFieldHelp( field.description, error, data ) }
			value={ value }
			onChange={ ( newVal ) => onChange( { [ field.id ]: newVal } ) }
			rows={ _args.rows || 4 }
			placeholder={ _args.placeholder || '' }
			readOnly={ readOnly }
			className={ error ? 'has-error' : undefined }
		/>
	);

	if ( ! copyable ) {
		return control;
	}

	return (
		<div className="wireframe-field--copyable wireframe-field--copyable-textarea">
			{ control }
			<CopyButton
				value={ value }
				className="wireframe-field__copy-btn"
			/>
		</div>
	);
}
