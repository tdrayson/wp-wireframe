/**
 * MediaEdit — WordPress Media Library picker.
 *
 * Supports single and multiple file selection.
 * Stored as an array of attachment IDs.
 */
import { Button, BaseControl } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function MediaEdit( { data, field, onChange } ) {
	const { _args = {} } = field;
	const value = data[ field.id ] ?? field.defaultValue ?? [];
	const ids = Array.isArray( value ) ? value : ( value ? [ value ] : [] );
	const multiple = _args.multiple || false;
	const mimeTypes = _args.mime_types || '';
	const disabled = !! field.readOnly;

	const [ previews, setPreviews ] = useState( [] );

	useEffect( () => {
		if ( ids.length === 0 ) {
			setPreviews( [] );
			return;
		}

		const controller = new AbortController();

		Promise.all(
			ids.map( ( id ) =>
				wp.apiFetch( {
					path: `/wp/v2/media/${ id }`,
					signal: controller.signal,
				} ).catch( () => null )
			)
		).then( ( results ) => {
			if ( ! controller.signal.aborted ) {
				setPreviews(
					results
						.filter( Boolean )
						.map( ( att ) => ( {
							id: att.id,
							url: att.media_details?.sizes?.thumbnail?.source_url || att.source_url,
							title: att.title?.rendered || att.slug,
						} ) )
				);
			}
		} );

		return () => controller.abort();
	}, [ ids.join( ',' ) ] );

	const openMediaLibrary = () => {
		const frame = wp.media( {
			title: _args.media_title || __( 'Select Media', 'wp-wireframe' ),
			button: {
				text: _args.media_button || __( 'Use Selection', 'wp-wireframe' ),
			},
			multiple,
			library: mimeTypes ? { type: mimeTypes } : {},
		} );

		frame.on( 'select', () => {
			const selection = frame.state().get( 'selection' ).toJSON();
			const newIds = selection.map( ( att ) => att.id );
			onChange( { [ field.id ]: multiple ? newIds : newIds } );
		} );

		frame.open();
	};

	const removeItem = ( removeId ) => {
		const newIds = ids.filter( ( id ) => id !== removeId );
		onChange( { [ field.id ]: newIds } );
	};

	const clearAll = () => {
		onChange( { [ field.id ]: [] } );
	};

	return (
		<BaseControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ field.description }
			id={ `wireframe-media-${ field.id }` }
		>
			{ previews.length > 0 && (
				<div className="wireframe-media__previews">
					{ previews.map( ( preview ) => (
						<div key={ preview.id } className="wireframe-media__preview">
							<img src={ preview.url } alt={ preview.title } />
							{ ! disabled && (
								<Button
									isDestructive
									variant="link"
									className="wireframe-media__remove"
									onClick={ () => removeItem( preview.id ) }
									aria-label={ __( 'Remove', 'wp-wireframe' ) }
								>
									&times;
								</Button>
							) }
						</div>
					) ) }
				</div>
			) }
			{ ! disabled && (
				<div className="wireframe-media__actions">
					<Button __next40pxDefaultSize variant="secondary" onClick={ openMediaLibrary }>
						{ ids.length > 0
							? __( 'Replace', 'wp-wireframe' )
							: __( 'Select', 'wp-wireframe' ) }
					</Button>
					{ ids.length > 0 && (
						<Button variant="link" isDestructive onClick={ clearAll }>
							{ __( 'Clear', 'wp-wireframe' ) }
						</Button>
					) }
				</div>
			) }
		</BaseControl>
	);
}
