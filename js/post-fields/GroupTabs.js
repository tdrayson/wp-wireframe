/**
 * A group's tabs with local active-tab state.
 */
import { useState } from '@wordpress/element';
import FieldTabs, { useVisibleTabs } from '../components/FieldTabs';

/**
 * Track the active tab of a config, falling back to the first visible tab.
 *
 * @param {Object} config The group's normalised config.
 * @return {{ tabs: Object[], active: string|undefined, current: Object|null, select: Function }} Visible tabs, the active id and tab, and a setter.
 */
export function useActiveTab( config ) {
	const tabs = useVisibleTabs( config );
	const [ selected, select ] = useState( null );
	const current =
		tabs.find( ( tab ) => tab.id === selected ) ?? tabs[ 0 ] ?? null;

	return { tabs, active: current?.id, current, select };
}

/**
 * Render a group config through FieldTabs.
 *
 * @param {Object} props
 * @param {Object} props.config The group's normalised config.
 */
export default function GroupTabs( { config } ) {
	const { active, select } = useActiveTab( config );

	return (
		<FieldTabs config={ config } activeTab={ active } onSelect={ select } />
	);
}
