/**
 * FieldTabs — tab nav plus the active tab's section cards.
 *
 * Renders no header or save/reset chrome; the host screen owns those.
 */
import { useMemo } from '@wordpress/element';
import { useFieldStore } from '../hooks/useFieldStore';
import { isTabVisible } from '../utils/conditions';
import { getTabs, getSections } from '../utils/mapConfig';
import SettingsSection from './SettingsSection';

/**
 * Tabs whose `conditions` pass against the current values.
 *
 * @param {Object} config The full config (tabs → sections → fields).
 * @return {Object[]} Visible tab configs.
 */
export function useVisibleTabs( config ) {
	const { values } = useFieldStore();

	return useMemo(
		() => getTabs( config ).filter( ( tab ) => isTabVisible( tab, values ) ),
		[ config, values ]
	);
}

/**
 * @param {Object}   props
 * @param {Object}   props.config    The full config.
 * @param {string}   props.activeTab Id of the active tab.
 * @param {Function} props.onSelect  Called with a tab id when a tab is clicked.
 */
export default function FieldTabs( { config, activeTab, onSelect } ) {
	const tabs = useVisibleTabs( config );
	const currentTab = tabs.find( ( tab ) => tab.id === activeTab );
	const sections = currentTab ? getSections( currentTab ) : [];

	return (
		<>
			{ tabs.length > 1 && (
				<div className="wireframe-page__tabs-container">
					<nav className="wireframe-page__tabs" role="tablist">
						{ tabs.map( ( tab ) => (
							<button
								key={ tab.id }
								role="tab"
								aria-selected={ tab.id === activeTab }
								className={ `wireframe-page__tab ${
									tab.id === activeTab ? 'is-active' : ''
								}` }
								onClick={ () => onSelect( tab.id ) }
							>
								{ tab.title }
							</button>
						) ) }
					</nav>
				</div>
			) }

			<div className="wireframe-page__content">
				{ sections.map( ( section ) => (
					<SettingsSection key={ section.id } section={ section } />
				) ) }
			</div>
		</>
	);
}
