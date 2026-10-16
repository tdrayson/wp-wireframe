/**
 * A `meta_box` group, rendered into the container box PHP registered for it.
 */
import { createPortal, useEffect, useState } from '@wordpress/element';
import GroupTabs from './GroupTabs';

/**
 * Render a `meta_box` group into its container box once the box is in the DOM.
 *
 * @param {Object} props
 * @param {Object} props.group Group from `window.wireframePostFields.groups`.
 */
export default function MetaBoxGroup( { group } ) {
	const [ target, setTarget ] = useState( null );

	useEffect( () => {
		setTarget(
			document
				.getElementById( `wireframe-group-${ group.id }` )
				?.querySelector( '.wireframe-group' ) ?? null
		);
	}, [ group.id ] );

	if ( ! target ) {
		return null;
	}

	return createPortal(
		<div className="wireframe-post-fields wireframe-meta-box">
			<GroupTabs config={ group.config } />
		</div>,
		target
	);
}
