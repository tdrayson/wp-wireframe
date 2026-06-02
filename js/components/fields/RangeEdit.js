/**
 * RangeEdit — slider/range input.
 */
import { RangeControl } from '@wordpress/components';
import { getFieldHelp } from './useFieldError';

export default function RangeEdit( { data, field, onChange, error } ) {
	const { _args = {} } = field;
	const value = data[ field.id ] ?? field.defaultValue ?? 0;
	const disabled = !! field.readOnly;

	return (
		<RangeControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ field.label }
			help={ getFieldHelp( field.description, error, data ) }
			value={ value }
			onChange={ disabled ? undefined : ( newVal ) => onChange( { [ field.id ]: newVal } ) }
			min={ _args.min ?? 0 }
			max={ _args.max ?? 100 }
			step={ _args.step ?? 1 }
			withInputField
			showTooltip
			marks={ _args.marks }
			allowReset={ _args.allowReset || false }
			renderTooltipContent={ _args.suffix ? ( val ) => `${ val }${ _args.suffix }` : undefined }
			disabled={ disabled }
			className={ error ? 'has-error' : undefined }
		/>
	);
}
