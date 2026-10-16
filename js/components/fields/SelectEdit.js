/**
 * SelectEdit — dropdown select field.
 *
 * Supports single select (default) and multi-select via `args.multiple`.
 * When `multiple: true`, renders a searchable token/tag input instead
 * of a native dropdown. Stored as a string (single) or array (multi).
 * A populated select with `fetch` searches the server as you type.
 *
 * @param {Object}   props
 * @param {Object}   props.data     Current settings values.
 * @param {Object}   props.field    Mapped field descriptor with `elements`.
 * @param {Function} props.onChange  Callback receiving { fieldId: newValue }.
 * @param {string}   [props.error]  Validation error message.
 */
import {
	SelectControl,
	FormTokenField,
	BaseControl,
	ComboboxControl,
} from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { getFieldHelp } from './useFieldError';
import { useFieldStore } from '../../hooks/useFieldStore';
import { mergeOptions, optionValue, searchOptions } from '../../utils/populate';

const SEARCH_DELAY = 300;

export default function SelectEdit( { data, field, onChange, error } ) {
	const { _args = {} } = field;
	const isMultiple = Boolean( _args.multiple );

	if ( _args.populate?.fetch ) {
		const Search = isMultiple ? MultiSearch : SingleSearch;

		return (
			<Search
				data={ data }
				field={ field }
				onChange={ onChange }
				error={ error }
			/>
		);
	}

	if ( isMultiple ) {
		return (
			<MultiSelect
				data={ data }
				field={ field }
				onChange={ onChange }
				error={ error }
			/>
		);
	}

	const value = data[ field.id ] ?? field.defaultValue ?? '';
	const disabled = !! field.readOnly;

	const options = ( field.elements || [] ).map( ( element ) => ( {
		label: element.label,
		value: element.value,
	} ) );

	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ field.label }
			help={ getFieldHelp( field.description, error, data ) }
			value={ value }
			options={ options }
			onChange={
				disabled
					? undefined
					: ( newValue ) =>
							onChange( {
								[ field.id ]: optionValue(
									field.elements,
									newValue
								),
							} )
			}
			disabled={ disabled }
			className={ error ? 'has-error' : undefined }
		/>
	);
}

/**
 * Get the debounce for a search: none for the empty one a focus starts, so the first page shows at once.
 *
 * @param {string} search Search term.
 * @return {number} Delay in milliseconds.
 */
function delayFor( search ) {
	return search === '' ? 0 : SEARCH_DELAY;
}

/**
 * Search a `fetch` field's options as the user types, debounced, keeping every option seen so far.
 *
 * Starts from the field's elements, which hold the labels of the stored values.
 *
 * @param {Object} field Mapped field descriptor.
 * @return {{ known: Array, results: Array, loading: boolean, pending: boolean, failure: (string|undefined), setSearch: Function }} Options seen, the latest results, whether no search has settled, a failed search's message, and a search setter.
 */
function useOptionSearch( field ) {
	const { populate: endpoint } = useFieldStore();
	const path = field._args.populate.field;
	const [ search, setSearch ] = useState( null );
	const [ results, setResults ] = useState( [] );
	const [ known, setKnown ] = useState( () => field.elements || [] );
	const [ loading, setLoading ] = useState( false );
	const [ failed, setFailed ] = useState( false );

	useEffect( () => {
		if ( search === null || ! endpoint?.prefix ) {
			return undefined;
		}

		let active = true;

		// Loading from the keystroke, so the empty state never shows for a search still pending.
		setLoading( true );

		const timer = setTimeout( () => {
			searchOptions( { ...endpoint, field: path, search } )
				.then( ( found ) => {
					if ( active ) {
						setFailed( false );
						setResults( found );
						setKnown( ( previous ) =>
							mergeOptions( previous, found )
						);
					}
				} )
				.catch( () => {
					if ( active ) {
						setFailed( true );
						setResults( [] );
					}
				} )
				.finally( () => active && setLoading( false ) );
		}, delayFor( search ) );

		return () => {
			active = false;
			clearTimeout( timer );
		};
	}, [ search, endpoint, path ] );

	const failure = failed
		? __( 'Search failed. Try again.', 'wp-wireframe' )
		: undefined;

	return {
		known,
		results,
		loading,
		pending: loading || search === null,
		failure,
		setSearch,
	};
}

/**
 * Get the classes of a token field's wrapper: its own label is screen-reader only, and the empty state waits for a search.
 *
 * @param {string}  [error] Validation error message.
 * @param {boolean} pending Whether no search has settled yet.
 * @return {string} Class names.
 */
function tokenFieldClass( error, pending ) {
	return [
		'wireframe-token-field',
		error && 'has-error',
		pending && 'is-pending',
	]
		.filter( Boolean )
		.join( ' ' );
}

/**
 * Single `fetch` select: a combobox whose options come from the search route.
 *
 * @param {Object}   props
 * @param {Object}   props.data     Current values.
 * @param {Object}   props.field    Mapped field descriptor.
 * @param {Function} props.onChange Callback receiving { fieldId: newValue }.
 * @param {string}   [props.error]  Validation error message.
 */
function SingleSearch( { data, field, onChange, error } ) {
	const { known, loading, failure, setSearch } = useOptionSearch( field );
	const disabled = !! field.readOnly;
	const stored = data[ field.id ] ?? field.defaultValue ?? null;

	return (
		<ComboboxControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ field.label }
			help={ getFieldHelp( field.description, error || failure, data ) }
			value={ optionValue( known, stored ) }
			options={ known }
			isLoading={ loading }
			onFilterValueChange={ setSearch }
			onChange={
				disabled
					? () => {}
					: ( newValue ) =>
							onChange( { [ field.id ]: newValue ?? null } )
			}
			placeholder={ field._args.placeholder || '' }
			className={ error ? 'has-error' : undefined }
		/>
	);
}

/**
 * Multiple `fetch` select: a token field whose suggestions come from the search route.
 *
 * @param {Object}   props
 * @param {Object}   props.data     Current values.
 * @param {Object}   props.field    Mapped field descriptor.
 * @param {Function} props.onChange Callback receiving { fieldId: newValue }.
 * @param {string}   [props.error]  Validation error message.
 */
function MultiSearch( { data, field, onChange, error } ) {
	const { known, results, pending, failure, setSearch } =
		useOptionSearch( field );
	const disabled = !! field.readOnly;
	const selected = Array.isArray( data[ field.id ] )
		? data[ field.id ]
		: field.defaultValue || [];

	// A stored value with no known label shows, and stays, as its ID.
	const labelOf = ( value ) =>
		known.find( ( option ) => String( option.value ) === String( value ) )
			?.label ?? String( value );
	const valueOf = ( label ) => {
		const option = known.find( ( candidate ) => candidate.label === label );

		return option
			? option.value
			: selected.find( ( value ) => String( value ) === label );
	};

	const handleChange = ( tokens ) => {
		const values = tokens
			.map( valueOf )
			.filter( ( value ) => value !== undefined );

		onChange( { [ field.id ]: values } );
	};

	return (
		<BaseControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ getFieldHelp( field.description, error || failure, data ) }
			id={ `wireframe-multiselect-${ field.id }` }
			className={ tokenFieldClass( error, pending ) }
		>
			<FormTokenField
				__next40pxDefaultSize
				__experimentalShowHowTo={ false }
				__experimentalExpandOnFocus
				__experimentalAutoSelectFirstMatch
				label={ field.label }
				__experimentalValidateInput={ ( token ) =>
					valueOf( token ) !== undefined
				}
				value={ selected.map( labelOf ) }
				suggestions={
					disabled ? [] : results.map( ( option ) => option.label )
				}
				onInputChange={ setSearch }
				onFocus={ () => setSearch( ( previous ) => previous ?? '' ) }
				onChange={ disabled ? () => {} : handleChange }
				placeholder={ field._args.placeholder || '' }
				maxLength={
					field._args.max ? Number( field._args.max ) : undefined
				}
				disabled={ disabled }
			/>
		</BaseControl>
	);
}

/**
 * MultiSelect — searchable token/tag input for multiple selections.
 *
 * Uses FormTokenField internally. Converts between stored keys
 * and display labels.
 */
function MultiSelect( { data, field, onChange, error } ) {
	const { _args = {} } = field;
	const options = field.elements || [];
	const disabled = !! field.readOnly;

	const selectedKeys = Array.isArray( data[ field.id ] )
		? data[ field.id ]
		: ( field.defaultValue || [] );

	const { keyToLabel, labelToKey, allLabels } = useMemo( () => {
		const keyMap = {};
		const labelMap = {};

		for ( const option of options ) {
			keyMap[ option.value ] = option.label;
			labelMap[ option.label ] = option.value;
		}

		return {
			keyToLabel: keyMap,
			labelToKey: labelMap,
			allLabels: options.map( ( option ) => option.label ),
		};
	}, [ options ] );

	const displayTokens = selectedKeys
		.map( ( key ) => keyToLabel[ key ] )
		.filter( Boolean );

	const handleChange = ( tokens ) => {
		const newKeys = tokens
			.map( ( label ) => labelToKey[ label ] )
			.filter( Boolean );

		onChange( { [ field.id ]: newKeys } );
	};

	return (
		<BaseControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ getFieldHelp( field.description, error, data ) }
			id={ `wireframe-multiselect-${ field.id }` }
			className={ tokenFieldClass( error, false ) }
		>
			<FormTokenField
				__next40pxDefaultSize
				__experimentalShowHowTo={ false }
				__experimentalExpandOnFocus
				__experimentalAutoSelectFirstMatch
				label={ field.label }
				value={ displayTokens }
				suggestions={ disabled ? [] : allLabels }
				onChange={ disabled ? () => {} : handleChange }
				placeholder={ _args.placeholder || '' }
				maxLength={ _args.max ? Number( _args.max ) : undefined }
				disabled={ disabled }
			/>
		</BaseControl>
	);
}
