/**
 * ColorEdit — color swatch + hex input that opens a picker in a dropdown.
 *
 * @param {Object}   props
 * @param {Object}   props.data     Current settings values.
 * @param {Object}   props.field    Mapped field descriptor.
 * @param {Function} props.onChange  Callback receiving { fieldId: newValue }.
 * @param {string}   [props.error]  Validation error message.
 */
import {
	ColorPicker,
	ColorIndicator,
	BaseControl,
	Dropdown,
	TextControl,
} from '@wordpress/components';
import { getFieldHelp } from './useFieldError';

export default function ColorEdit( { data, field, onChange, error } ) {
	const { _args = {} } = field;
	const value = data[ field.id ] ?? field.defaultValue ?? '#000000';
	const disabled = !! field.readOnly;

	return (
		<BaseControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ getFieldHelp( field.description, error, data ) }
			id={ `wireframe-color-${ field.id }` }
			className={ error ? 'has-error' : undefined }
		>
			{ disabled ? (
				<button
					type="button"
					className="wireframe-color-swatch is-readonly"
					disabled
				>
					<ColorIndicator colorValue={ value } />
					<span className="wireframe-color-swatch__value">{ value }</span>
				</button>
			) : (
				<Dropdown
					className="wireframe-color-dropdown"
					contentClassName="wireframe-color-dropdown__popover"
					renderToggle={ ( { isOpen, onToggle } ) => (
						<button
							type="button"
							className="wireframe-color-swatch"
							onClick={ onToggle }
							aria-expanded={ isOpen }
						>
							<ColorIndicator colorValue={ value } />
							<span className="wireframe-color-swatch__value">{ value }</span>
						</button>
					) }
					renderContent={ () => (
						<ColorPicker
							color={ value }
							onChange={ ( newValue ) => onChange( { [ field.id ]: newValue } ) }
							enableAlpha={ _args.enableAlpha || false }
						/>
					) }
				/>
			) }
		</BaseControl>
	);
}
