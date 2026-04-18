/**
 * ImportButton — uploads a JSON file to replace settings.
 */
import { Button, BaseControl } from '@wordpress/components';
import { useRef, useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useSettings } from '../../hooks/useSettings';

export default function ImportButton( { field } ) {
	const { _args = {} } = field;
	const fileInputRef = useRef( null );
	const [ importing, setImporting ] = useState( false );
	const { setMultiple, restBase } = useSettings();

	const handleImport = useCallback( async ( event ) => {
		const file = event.target.files?.[ 0 ];
		if ( ! file ) return;

		setImporting( true );

		try {
			const text = await file.text();
			const imported = JSON.parse( text );

			if ( typeof imported !== 'object' || Array.isArray( imported ) ) {
				throw new Error( __( 'Invalid settings file format.', 'wp-wireframe' ) );
			}

			const response = await apiFetch( {
				path: restBase,
				method: 'POST',
				data: imported,
			} );

			if ( response.values ) {
				setMultiple( response.values );
			}

			// eslint-disable-next-line no-alert
			alert( __( 'Settings imported successfully.', 'wp-wireframe' ) );
		} catch ( error ) {
			// eslint-disable-next-line no-alert
			alert( error.message || __( 'Import failed.', 'wp-wireframe' ) );
		} finally {
			setImporting( false );
			if ( fileInputRef.current ) {
				fileInputRef.current.value = '';
			}
		}
	}, [ setMultiple, restBase ] );

	return (
		<BaseControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ field.description }
			id={ `wireframe-import-${ field.id }` }
		>
			<input
				ref={ fileInputRef }
				type="file"
				accept=".json"
				onChange={ handleImport }
				style={ { display: 'none' } }
			/>
			<Button
				__next40pxDefaultSize
				variant="secondary"
				onClick={ () => fileInputRef.current?.click() }
				isBusy={ importing }
				disabled={ importing }
			>
				{ _args.button_label || __( 'Import', 'wp-wireframe' ) }
			</Button>
		</BaseControl>
	);
}
