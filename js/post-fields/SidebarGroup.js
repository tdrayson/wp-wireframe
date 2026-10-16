/**
 * A `sidebar` group, rendered as a panel in the document settings sidebar.
 */
import { useEffect, useMemo } from '@wordpress/element';
import { useRegistry } from '@wordpress/data';
import {
	PluginDocumentSettingPanel as EditorPanel,
	store as editorStore,
} from '@wordpress/editor';
import { FieldStoreContext, useFieldStore } from '../hooks/useFieldStore';
import GroupTabs from './GroupTabs';

// WP 6.5 only exports the panel from edit-post.
const PluginDocumentSettingPanel =
	EditorPanel ?? window.wp?.editPost?.PluginDocumentSettingPanel;

const PLUGIN_NAME = 'wireframe-post-fields';
const PREFERENCES_STORE = 'core/preferences';
const PREFERENCE_SCOPE = 'wp-wireframe';
const SEEDED_KEY = 'seededPanels';

/**
 * Drop `columns` from every field, since the panel is too narrow for a grid.
 *
 * @param {Object} config Normalised config.
 * @return {Object} The config without `columns`.
 */
function fullWidth( config ) {
	return {
		...config,
		tabs: ( config.tabs || [] ).map( ( tab ) => ( {
			...tab,
			sections: ( tab.sections || [] ).map( ( section ) => ( {
				...section,
				fields: ( section.fields || [] ).map(
					( { columns, ...field } ) => field
				),
			} ) ),
		} ) ),
	};
}

/**
 * Open a non-collapsed panel the first time this user sees it; afterwards their own open state is kept.
 *
 * @param {string}  panelName Editor panel name.
 * @param {boolean} collapsed The group's `display.collapsed`.
 */
function useSeedPanel( panelName, collapsed ) {
	const registry = useRegistry();

	useEffect( () => {
		const preferences = registry.select( PREFERENCES_STORE );
		const seeded = preferences.get( PREFERENCE_SCOPE, SEEDED_KEY ) || [];

		if ( seeded.includes( panelName ) ) {
			return;
		}

		if (
			! collapsed &&
			! registry.select( editorStore ).isEditorPanelOpened( panelName )
		) {
			registry
				.dispatch( editorStore )
				.toggleEditorPanelOpened( panelName );
		}

		registry
			.dispatch( PREFERENCES_STORE )
			.set( PREFERENCE_SCOPE, SEEDED_KEY, [ ...seeded, panelName ] );
	}, [ registry, panelName, collapsed ] );
}

/**
 * @param {Object} props
 * @param {Object} props.group Group from `window.wireframePostFields.groups`.
 */
export default function SidebarGroup( { group } ) {
	const name = `wireframe-${ group.id }`;
	const store = useFieldStore();
	const config = useMemo( () => fullWidth( group.config ), [ group.config ] );

	useSeedPanel( `${ PLUGIN_NAME }/${ name }`, group.collapsed );

	if ( ! PluginDocumentSettingPanel ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name={ name }
			title={ group.title }
			className="wireframe-post-fields wireframe-sidebar-group"
		>
			{ /* The panel's fill renders in the sidebar slot's tree, outside this provider. */ }
			<FieldStoreContext.Provider value={ store }>
				<GroupTabs config={ config } />
			</FieldStoreContext.Provider>
		</PluginDocumentSettingPanel>
	);
}
