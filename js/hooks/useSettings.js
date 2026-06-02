/**
 * Settings state management.
 *
 * Provides a React context with current values, dirty tracking,
 * save/reset actions, per-field errors, and update functions.
 *
 * @module useSettings
 */
import {
	createContext,
	useContext,
	useState,
	useCallback,
	useMemo,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

/** @type {import('@wordpress/element').Context} */
const SettingsContext = createContext( null );

/**
 * Walk the config structure and collect labels for fields that have errors.
 *
 * @param {Object} config      The full settings config (tabs → sections → fields).
 * @param {Object} fieldErrors Map of fieldId → error message from the server.
 * @return {string[]} Human-readable labels for the fields with errors.
 */
function collectErrorLabels( config, fieldErrors ) {
	const labels = [];

	for ( const tab of ( config.tabs || [] ) ) {
		for ( const section of ( tab.sections || [] ) ) {
			for ( const fieldConfig of ( section.fields || [] ) ) {
				const fieldId = fieldConfig.id;
				if ( fieldErrors[ fieldId ] ) {
					labels.push( fieldConfig.label || fieldId );
				}
			}
		}
	}

	return labels;
}

/**
 * Context provider that manages settings state for the entire admin page.
 *
 * @param {Object}  props
 * @param {Object}  props.config          The full PHP settings config.
 * @param {Object}  props.initialValues   Resolved values (saved + defaults) from PHP.
 * @param {boolean} props.hasSavedInitial Whether any settings have been persisted.
 * @param {import('@wordpress/element').ReactNode} props.children
 */
export function SettingsProvider( { config, initialValues, hasSavedInitial, canSave = true, canReset = true, prefix = 'wireframe', pageId = 'default', children } ) {
	const restBase = `${ prefix }/v1/settings/${ pageId }`;
	const [ values, setValues ] = useState( () => ( { ...initialValues } ) );
	const [ savedSnapshot, setSavedSnapshot ] = useState( () => ( { ...initialValues } ) );
	const [ saving, setSaving ] = useState( false );
	const [ errors, setErrors ] = useState( {} );
	const [ hasSaved, setHasSaved ] = useState( () => Boolean( hasSavedInitial ) );
	const { createSuccessNotice, createErrorNotice } = useDispatch( noticesStore );

	/**
	 * Update a single field value and clear its error.
	 *
	 * @param {string} fieldId The field identifier.
	 * @param {*}      value   The new value.
	 */
	const setValue = useCallback( ( fieldId, value ) => {
		setValues( ( previous ) => ( { ...previous, [ fieldId ]: value } ) );

		setErrors( ( previous ) => {
			if ( ! previous[ fieldId ] ) {
				return previous;
			}

			const updated = { ...previous };
			delete updated[ fieldId ];
			return updated;
		} );
	}, [] );

	/**
	 * Update multiple field values at once.
	 *
	 * @param {Object} edits Map of fieldId → new value.
	 */
	const setMultiple = useCallback( ( edits ) => {
		setValues( ( previous ) => ( { ...previous, ...edits } ) );
	}, [] );

	/**
	 * Whether current values differ from the last saved snapshot.
	 *
	 * @type {boolean}
	 */
	const isDirty = useMemo( () => {
		return JSON.stringify( values ) !== JSON.stringify( savedSnapshot );
	}, [ values, savedSnapshot ] );

	/**
	 * Persist current values to the server via REST API.
	 * Sets per-field errors and shows a snackbar on success or failure.
	 */
	const save = useCallback( async () => {
		setSaving( true );
		setErrors( {} );

		try {
			const response = await apiFetch( {
				path: restBase,
				method: 'POST',
				data: values,
			} );

			if ( response.values ) {
				setValues( { ...response.values } );
				setSavedSnapshot( { ...response.values } );
			}

			createSuccessNotice( __( 'Settings saved.', 'wp-wireframe' ), { type: 'snackbar' } );
			setHasSaved( true );
		} catch ( requestError ) {
			handleSaveError( requestError );
		} finally {
			setSaving( false );
		}
	}, [ values, createSuccessNotice, createErrorNotice ] );

	/**
	 * Handle a failed save request — set field errors and show a snackbar.
	 *
	 * @param {Error} requestError The error from apiFetch.
	 */
	function handleSaveError( requestError ) {
		if ( ! requestError.data?.errors ) {
			createErrorNotice(
				requestError.message || __( 'Failed to save settings.', 'wp-wireframe' ),
				{ type: 'snackbar' }
			);
			return;
		}

		const fieldErrors = requestError.data.errors;
		setErrors( fieldErrors );

		const labels = collectErrorLabels( config, fieldErrors );

		const summary = labels.length > 0
			/* translators: %s: comma-separated list of field labels with errors */
			? sprintf( __( 'Failed to save, please check: %s', 'wp-wireframe' ), labels.join( ', ' ) )
			: __( 'Failed to save. Please fix the highlighted errors.', 'wp-wireframe' );

		createErrorNotice( summary, { type: 'snackbar' } );
	}

	/**
	 * Reset all settings to their declared defaults via REST API.
	 */
	const reset = useCallback( async () => {
		setSaving( true );

		try {
			const response = await apiFetch( {
				path: restBase,
				method: 'DELETE',
			} );

			if ( response.values ) {
				setValues( { ...response.values } );
				setSavedSnapshot( { ...response.values } );
			}

			createSuccessNotice( __( 'Settings reset to defaults.', 'wp-wireframe' ), { type: 'snackbar' } );
			setHasSaved( false );
		} catch ( requestError ) {
			createErrorNotice(
				requestError.message || __( 'Failed to reset settings.', 'wp-wireframe' ),
				{ type: 'snackbar' }
			);
		} finally {
			setSaving( false );
		}
	}, [ createSuccessNotice, createErrorNotice ] );

	const contextValue = useMemo( () => ( {
		config,
		values,
		setValue,
		setMultiple,
		isDirty,
		saving,
		errors,
		save,
		reset,
		hasSaved,
		canSave,
		canReset,
		restBase,
	} ), [ config, values, setValue, setMultiple, isDirty, saving, errors, save, reset, hasSaved, canSave, canReset, restBase ] );

	return (
		<SettingsContext.Provider value={ contextValue }>
			{ children }
		</SettingsContext.Provider>
	);
}

/**
 * Access the settings context.
 *
 * Must be called within a `<SettingsProvider>`.
 *
 * @return {{ config: Object, values: Object, setValue: Function, setMultiple: Function, isDirty: boolean, saving: boolean, errors: Object, save: Function, reset: Function, hasSaved: boolean, canSave: boolean, canReset: boolean, restBase: string }}
 */
export function useSettings() {
	const context = useContext( SettingsContext );

	if ( ! context ) {
		throw new Error( 'useSettings must be used within a SettingsProvider' );
	}

	return context;
}
