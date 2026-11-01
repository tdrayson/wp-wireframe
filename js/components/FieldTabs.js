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
		() =>
			getTabs( config ).filter( ( tab ) => isTabVisible( tab, values ) ),
		[ config, values ]
	);
}

/**
 * The tab bar, shown only when there is more than one tab.
 *
 * @param {Object}   props
 * @param {Object[]} props.tabs      Visible tabs.
 * @param {string}   props.activeTab Id of the active tab.
 * @param {Function} props.onSelect  Called with a tab id when a tab is clicked.
 */
export function TabNav( { tabs, activeTab, onSelect } ) {
	if ( tabs.length <= 1 ) {
		return null;
	}

	return (
		<div className="wireframe-page__tabs-container">
			<nav className="wireframe-page__tabs" role="tablist">
				{ tabs.map( ( tab ) => (
					<button
						key={ tab.id }
						type="button"
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
	);
}

/**
 * A tab's section cards.
 *
 * @param {Object}      props
 * @param {Object|null} props.tab The tab to render, or null for none.
 */
export function TabSections( { tab } ) {
	const sections = tab ? getSections( tab ) : [];

	return (
		<div className="wireframe-page__content">
			{ sections.map( ( section ) => (
				<SettingsSection key={ section.id } section={ section } />
			) ) }
		</div>
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
	const currentTab = tabs.find( ( tab ) => tab.id === activeTab ) ?? null;

	return (
		<>
			<TabNav
				tabs={ tabs }
				activeTab={ activeTab }
				onSelect={ onSelect }
			/>
			<TabSections tab={ currentTab } />
		</>
	);
}
