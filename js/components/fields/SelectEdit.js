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
 * Search a `fetch` field's options as the user types, debounced, keeping every option seen so far.
 *
 * Starts from the field's elements, which hold the labels of the stored values.
 *
 * @param {Object} field Mapped field descriptor.
 * @return {{ known: Array, results: Array, loading: boolean, setSearch: Function }} Options seen, the latest results, and a search setter.
 */
function useOptionSearch( field ) {
	const { populate: endpoint } = useFieldStore();
	const path = field._args.populate.field;
	const [ search, setSearch ] = useState( null );
	const [ results, setResults ] = useState( [] );
	const [ known, setKnown ] = useState( () => field.elements || [] );
	const [ loading, setLoading ] = useState( false );

	useEffect( () => {
		if ( search === null || ! endpoint?.prefix ) {
			return undefined;
		}

		let active = true;
		const timer = setTimeout( () => {
			setLoading( true );
			searchOptions( { ...endpoint, field: path, search } )
				.then( ( found ) => {
					if ( active ) {
						setResults( found );
						setKnown( ( previous ) =>
							mergeOptions( previous, found )
						);
					}
				} )
				.catch( () => active && setResults( [] ) )
				.finally( () => active && setLoading( false ) );
		}, SEARCH_DELAY );

		return () => {
			active = false;
			clearTimeout( timer );
		};
	}, [ search, endpoint, path ] );

	return { known, results, loading, setSearch };
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
	const { known, loading, setSearch } = useOptionSearch( field );
	const disabled = !! field.readOnly;
	const stored = data[ field.id ] ?? field.defaultValue ?? null;

	return (
		<ComboboxControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ field.label }
			help={ getFieldHelp( field.description, error, data ) }
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
	const { known, results, setSearch } = useOptionSearch( field );
	const disabled = !! field.readOnly;
	const selected = Array.isArray( data[ field.id ] )
		? data[ field.id ]
		: field.defaultValue || [];

	const labelOf = ( value ) =>
		known.find( ( option ) => String( option.value ) === String( value ) )
			?.label;
	const valueOf = ( label ) =>
		known.find( ( option ) => option.label === label )?.value;

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
			help={ getFieldHelp( field.description, error, data ) }
			id={ `wireframe-multiselect-${ field.id }` }
			className={ error ? 'has-error' : undefined }
		>
			<FormTokenField
				__next40pxDefaultSize
				__experimentalShowHowTo={ false }
				__experimentalExpandOnFocus
				__experimentalAutoSelectFirstMatch
				__experimentalValidateInput={ ( token ) =>
					valueOf( token ) !== undefined
				}
				value={ selected.map( labelOf ).filter( Boolean ) }
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
			className={ error ? 'has-error' : undefined }
		>
			<FormTokenField
				__next40pxDefaultSize
				__experimentalShowHowTo={ false }
				__experimentalExpandOnFocus
				__experimentalAutoSelectFirstMatch
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
