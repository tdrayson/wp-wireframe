/**
 * ImageCheckboxesEdit — visual multi-select with thumbnail images.
 */
import { BaseControl } from '@wordpress/components';

export default function ImageCheckboxesEdit( { data, field, onChange } ) {
	const { _args = {} } = field;
	const value = Array.isArray( data[ field.id ] ) ? data[ field.id ] : ( field.defaultValue || [] );
	const options = field.elements || [];

	const toggle = ( optionValue ) => {
		const newValue = value.includes( optionValue )
			? value.filter( ( v ) => v !== optionValue )
			: [ ...value, optionValue ];
		onChange( { [ field.id ]: newValue } );
	};

	return (
		<BaseControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ field.description }
			id={ `wireframe-image-checkboxes-${ field.id }` }
		>
			<div className="wireframe-image-choice wireframe-image-choice--multi" role="group" aria-label={ field.label }>
				{ options.map( ( option ) => (
					<button
						key={ option.value }
						type="button"
						role="checkbox"
						aria-checked={ value.includes( option.value ) }
						className={ `wireframe-image-choice__option ${ value.includes( option.value ) ? 'is-selected' : '' }` }
						onClick={ () => toggle( option.value ) }
					>
						{ option._image && (
							<img
								src={ option._image }
								alt={ option.label }
								className="wireframe-image-choice__image"
							/>
						) }
						<span className="wireframe-image-choice__label">{ option.label }</span>
					</button>
				) ) }
			</div>
		</BaseControl>
	);
}
