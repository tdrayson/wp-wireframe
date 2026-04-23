/**
 * NumberEdit — numeric input with min/max/step support.
 */
import { __experimentalNumberControl as NumberControl } from '@wordpress/components';
import { getFieldHelp } from './useFieldError';
import CopyButton from './CopyButton';

export default function NumberEdit( { data, field, onChange, error } ) {
	const { _args = {} } = field;
	const value = data[ field.id ] ?? field.defaultValue ?? 0;
	const readOnly = !! _args.readonly;
	const copyable = !! _args.copyable;

	const control = (
		<NumberControl
			__next40pxDefaultSize
			label={ field.label }
			help={ getFieldHelp( field.description, error, data ) }
			value={ value }
			onChange={ ( newVal ) => {
				const parsed = _args.integer ? parseInt( newVal, 10 ) : parseFloat( newVal );
				onChange( { [ field.id ]: isNaN( parsed ) ? 0 : parsed } );
			} }
			min={ _args.min }
			max={ _args.max }
			step={ _args.step || 1 }
			spinControls="native"
			readOnly={ readOnly }
			className={ error ? 'has-error' : undefined }
		/>
	);

	if ( ! copyable ) {
		return control;
	}

	return (
		<div className="wireframe-field--copyable">
			{ control }
			<CopyButton
				value={ value }
				className="wireframe-field__copy-btn"
			/>
		</div>
	);
}
