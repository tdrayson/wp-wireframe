/**
 * WP Wireframe — Admin Settings App
 *
 * Finds the mount point by the data-object-name attribute,
 * reads the localized data from window, and renders the app.
 *
 * The app is wrapped in `SlotFillProvider` so any `@wordpress/components`
 * widget that relies on the slot/fill system (Popover, Dropdown portals,
 * ComboboxControl, SnackbarList, etc.) has the context it expects.
 * Without it, WP logs a console warning and popovers / portals can
 * misposition.
 */
import { createRoot } from '@wordpress/element';
import { SlotFillProvider } from '@wordpress/components';
import App from './App';
import './styles/settings.scss';

document.addEventListener( 'DOMContentLoaded', () => {
	// Find any mount point with a data-object-name attribute.
	const container = document.querySelector( '[data-object-name]' );

	if ( ! container ) {
		return;
	}

	const objectName = container.getAttribute( 'data-object-name' );
	const data = window[ objectName ];

	if ( ! data ) {
		return;
	}

	const root = createRoot( container );
	root.render(
		<SlotFillProvider>
			<App data={ data } />
		</SlotFillProvider>
	);
} );
