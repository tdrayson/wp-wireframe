/**
 * WP Wireframe — Admin Settings App
 *
 * Finds the mount point by the data-object-name attribute,
 * reads the localized data from window, and renders the app.
 */
import { createRoot } from '@wordpress/element';
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
	root.render( <App data={ data } /> );
} );
