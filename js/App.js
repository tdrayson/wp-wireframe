/**
 * Main App component.
 *
 * Receives the localized data object as a prop from the entry point.
 * Renders the settings page with tabs, sections, and fields.
 *
 * @param {Object} props
 * @param {Object} props.data The localized data object from wp_localize_script.
 */
import { __ } from '@wordpress/i18n';
import SettingsPage from './components/SettingsPage';
import { SettingsProvider } from './hooks/useSettings';

export default function App( { data } ) {
	if ( ! data?.config?.tabs ) {
		return (
			<div className="wireframe-app wireframe-app--error">
				<p>{ __( 'Settings configuration not found.', 'wp-wireframe' ) }</p>
			</div>
		);
	}

	return (
		<SettingsProvider
			config={ data.config }
			initialValues={ data.values }
			hasSavedInitial={ data.hasSaved }
			prefix={ data.prefix }
			pageId={ data.pageId || 'default' }
		>
			<SettingsPage />
		</SettingsProvider>
	);
}
