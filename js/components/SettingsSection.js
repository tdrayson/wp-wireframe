/**
 * SettingsSection — renders a section card with fields.
 *
 * Each section is a card with title, description, and a 12-column
 * CSS grid of fields. Fields come as a sequential array with `id` keys.
 */
import { useCallback } from '@wordpress/element';
import { useSettings } from '../hooks/useSettings';
import { isFieldVisible, isSectionVisible } from '../utils/conditions';
import { mapField } from '../utils/mapConfig';
import { customEditComponents } from './fields';

export default function SettingsSection( { section } ) {
	const { values, setMultiple, errors } = useSettings();

	if ( ! isSectionVisible( section, values ) ) {
		return null;
	}

	const fields = section.fields || [];

	const handleChange = useCallback(
		( edits ) => {
			setMultiple( edits );
		},
		[ setMultiple ]
	);

	// Filter to only visible fields.
	const visibleFields = fields.filter( ( fieldConfig ) =>
		isFieldVisible( fieldConfig, values )
	);

	if ( ! visibleFields.length ) {
		return null;
	}

	return (
		<div className="wireframe-section">
			{ section.title && (
				<h2 className="wireframe-section__title">{ section.title }</h2>
			) }
			{ section.description && (
				<p className="wireframe-section__description">{ section.description }</p>
			) }
			<div className="wireframe-section__body wireframe-grid">
				{ visibleFields.map( ( fieldConfig ) => {
					const field = mapField( fieldConfig );
					const EditComponent = customEditComponents[ fieldConfig.type ];
					const columns = fieldConfig.columns || 12;

					if ( ! EditComponent ) {
						return null;
					}

					return (
						<div
							key={ field.id }
							className="wireframe-grid__cell"
							style={ { gridColumn: `span ${ columns }` } }
						>
							<EditComponent
								data={ values }
								field={ field }
								onChange={ handleChange }
								error={ errors[ field.id ] }
							/>
						</div>
					);
				} ) }
			</div>
		</div>
	);
}
