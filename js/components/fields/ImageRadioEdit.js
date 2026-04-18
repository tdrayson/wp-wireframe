/**
 * ImageRadioEdit — visual radio buttons with thumbnail images.
 */
import { BaseControl } from '@wordpress/components';

export default function ImageRadioEdit( { data, field, onChange } ) {
	const { _args = {} } = field;
	const value = data[ field.id ] ?? field.defaultValue ?? '';
	const options = field.elements || [];

	return (
		<BaseControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ field.description }
			id={ `wireframe-image-radio-${ field.id }` }
		>
			<div className="wireframe-image-choice" role="radiogroup" aria-label={ field.label }>
				{ options.map( ( option ) => (
					<button
						key={ option.value }
						type="button"
						role="radio"
						aria-checked={ value === option.value }
						className={ `wireframe-image-choice__option ${ value === option.value ? 'is-selected' : '' }` }
						onClick={ () => onChange( { [ field.id ]: option.value } ) }
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
