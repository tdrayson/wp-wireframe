/**
 * TextInputEdit — handles text, email, url, password field types.
 */
import { TextControl } from '@wordpress/components';
import { getFieldHelp } from './useFieldError';

const TYPE_MAP = {
	text: 'text',
	email: 'email',
	url: 'url',
	password: 'password',
};

export default function TextInputEdit( { data, field, onChange, error } ) {
	const { _phpType = 'text', _args = {} } = field;
	const value = data[ field.id ] ?? field.defaultValue ?? '';

	return (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ field.label }
			help={ getFieldHelp( field.description, error ) }
			type={ TYPE_MAP[ _phpType ] || 'text' }
			value={ value }
			onChange={ ( newVal ) => onChange( { [ field.id ]: newVal } ) }
			placeholder={ _args.placeholder || '' }
			required={ field.required || false }
			autoComplete={ _phpType === 'password' ? 'off' : undefined }
			className={ error ? 'has-error' : undefined }
		/>
	);
}
