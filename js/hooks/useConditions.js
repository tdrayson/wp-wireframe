/**
 * useConditions hook.
 *
 * Provides functions to check field and section visibility
 * based on the current settings values.
 */
import { useCallback } from '@wordpress/element';
import { useSettings } from './useSettings';
import { isFieldVisible, isSectionVisible } from '../utils/conditions';

export function useConditions() {
	const { values, config } = useSettings();

	const checkField = useCallback(
		( fieldId, fieldConfig ) => {
			return isFieldVisible( fieldConfig, values );
		},
		[ values ]
	);

	const checkSection = useCallback(
		( sectionConfig ) => {
			return isSectionVisible( sectionConfig, values );
		},
		[ values ]
	);

	return { checkField, checkSection };
}
