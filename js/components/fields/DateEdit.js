/**
 * DateEdit — date picker field with WordPress DatePicker.
 *
 * Displays the formatted date using the site's date format settings.
 * Stores the value as ISO YYYY-MM-DD.
 *
 * @param {Object}   props
 * @param {Object}   props.data     Current settings values.
 * @param {Object}   props.field    Mapped field descriptor.
 * @param {Function} props.onChange  Callback receiving { fieldId: newValue }.
 * @param {string}   [props.error]  Validation error message.
 */
import { DatePicker, BaseControl, Button, Dropdown } from '@wordpress/components';
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import { __ } from '@wordpress/i18n';
import { getFieldHelp } from './useFieldError';

/**
 * Extract the date portion (YYYY-MM-DD) from an ISO datetime string.
 *
 * @param {string} isoDatetime Full ISO string from DatePicker.
 * @return {string} Date-only string, or empty if falsy.
 */
function extractDateFromISO( isoDatetime ) {
	if ( ! isoDatetime ) {
		return '';
	}
	return isoDatetime.split( 'T' )[ 0 ];
}

export default function DateEdit( { data, field, onChange, error } ) {
	const { _args = {} } = field;
	const value = data[ field.id ] ?? field.defaultValue ?? '';

	const displayValue = value
		? dateI18n( getDateSettings().formats.date, value )
		: __( 'Select date', 'wp-wireframe' );

	return (
		<BaseControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ getFieldHelp( field.description, error ) }
			id={ `wireframe-date-${ field.id }` }
			className={ error ? 'has-error' : undefined }
		>
			<div className="wireframe-date__controls">
				<Dropdown
					renderToggle={ ( { isOpen, onToggle } ) => (
						<Button
							__next40pxDefaultSize
							variant="secondary"
							onClick={ onToggle }
							aria-expanded={ isOpen }
						>
							{ displayValue }
						</Button>
					) }
					renderContent={ () => (
						<DatePicker
							currentDate={ value || undefined }
							onChange={ ( selectedDate ) => {
								onChange( { [ field.id ]: extractDateFromISO( selectedDate ) } );
							} }
						/>
					) }
				/>
				{ value && (
					<Button
						variant="link"
						isDestructive
						onClick={ () => onChange( { [ field.id ]: '' } ) }
					>
						{ __( 'Clear', 'wp-wireframe' ) }
					</Button>
				) }
			</div>
		</BaseControl>
	);
}
