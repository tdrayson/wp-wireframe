/**
 * ExportButton — downloads settings as a JSON file.
 */
import { Button, BaseControl } from '@wordpress/components';
import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useSettings } from '../../hooks/useSettings';

export default function ExportButton( { field } ) {
	const { _args = {} } = field;
	const { restBase } = useSettings();

	const handleExport = useCallback( async () => {
		try {
			const response = await apiFetch( {
				path: restBase,
				method: 'GET',
			} );

			const blob = new Blob(
				[ JSON.stringify( response.values, null, 2 ) ],
				{ type: 'application/json' }
			);
			const url = URL.createObjectURL( blob );
			const a = document.createElement( 'a' );
			a.href = url;
			a.download = `${ _args.filename || 'settings' }.json`;
			a.click();
			URL.revokeObjectURL( url );
		} catch ( error ) {
			// eslint-disable-next-line no-alert
			alert( error.message || __( 'Export failed.', 'wp-wireframe' ) );
		}
	}, [ _args.filename, restBase ] );

	return (
		<BaseControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ field.description }
			id={ `wireframe-export-${ field.id }` }
		>
			<Button __next40pxDefaultSize variant="secondary" onClick={ handleExport }>
				{ _args.button_label || __( 'Export', 'wp-wireframe' ) }
			</Button>
		</BaseControl>
	);
}
