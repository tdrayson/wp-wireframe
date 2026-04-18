/**
 * Translation layer: PHP config → field descriptors for React components.
 *
 * Handles the new sequential-array config format with promoted keys.
 * Fields have common keys (label, description, default, required, etc.)
 * at the top level, and type-specific settings in `args`.
 */
import { customEditComponents } from '../components/fields';

/**
 * Map a single field config to a field descriptor for Edit components.
 *
 * @param {Object} fieldConfig The field object from the config array.
 * @return {Object} Field descriptor with `id`, `label`, `_args`, `_phpType`, etc.
 */
export function mapField( fieldConfig ) {
	const type = fieldConfig.type || 'text';
	const args = fieldConfig.args || {};

	const field = {
		id: fieldConfig.id,
		type: 'text',
		label: fieldConfig.label || fieldConfig.id,
		description: fieldConfig.description || '',
		defaultValue: fieldConfig.default ?? null,
		required: fieldConfig.required || false,
		columns: fieldConfig.columns || 12,
		conditions: fieldConfig.conditions || null,
		_phpType: type,
		_args: args,
	};

	// Map options to elements array.
	if ( args.options && typeof args.options === 'object' ) {
		field.elements = Object.entries( args.options ).map( ( [ value, label ] ) => {
			if ( typeof label === 'object' && label.label ) {
				return { value, label: label.label, _image: label.image };
			}
			return { value, label };
		} );
	}

	// Attach custom Edit component.
	if ( customEditComponents[ type ] ) {
		field.Edit = customEditComponents[ type ];
	}

	return field;
}

/**
 * Get all tabs from the config.
 *
 * Sequential arrays — each tab has an `id` key.
 *
 * @param {Object} config The full normalized config.
 * @return {Array<{ id: string, title: string, sections: Array }>}
 */
export function getTabs( config ) {
	const tabs = config.tabs || [];

	return tabs.map( ( tab ) => ( {
		id: tab.id || 'default',
		title: tab.title || '',
		sections: tab.sections || [],
	} ) );
}

/**
 * Get sections for a specific tab.
 *
 * Sequential arrays — each section has an `id` key.
 *
 * @param {Object} tabConfig A single tab object.
 * @return {Array<{ id: string, title: string, description: string, fields: Array }>}
 */
export function getSections( tabConfig ) {
	const sections = tabConfig.sections || [];

	return sections.map( ( section ) => ( {
		id: section.id || 'default',
		title: section.title || '',
		description: section.description || '',
		fields: section.fields || [],
	} ) );
}
