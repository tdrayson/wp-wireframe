/**
 * NumberEdit — numeric input with min/max/step support.
 */
import { __experimentalNumberControl as NumberControl } from '@wordpress/components';
import { getFieldHelp } from './useFieldError';

export default function NumberEdit( { data, field, onChange, error } ) {
	const { _args = {} } = field;
	const value = data[ field.id ] ?? field.defaultValue ?? 0;

	return (
		<NumberControl
			__next40pxDefaultSize
			label={ field.label }
			help={ getFieldHelp( field.description, error ) }
			value={ value }
			onChange={ ( newVal ) => {
				const parsed = _args.integer ? parseInt( newVal, 10 ) : parseFloat( newVal );
				onChange( { [ field.id ]: isNaN( parsed ) ? 0 : parsed } );
			} }
			min={ _args.min }
			max={ _args.max }
			step={ _args.step || 1 }
			spinControls="native"
			className={ error ? 'has-error' : undefined }
		/>
	);
}
