/**
 * A `meta_box` group, rendered into the container box PHP registered for it.
 */
import { createPortal, useEffect, useState } from '@wordpress/element';
import GroupTabs from './GroupTabs';

const HIDDEN_CLASS = 'wireframe-group-inapplicable';

/**
 * Render a `meta_box` group into its container box once the box is in the DOM, showing the box while mounted.
 *
 * @param {Object} props
 * @param {Object} props.group Group from `window.wireframePostFields.groups`.
 */
export default function MetaBoxGroup( { group } ) {
	const [ target, setTarget ] = useState( null );

	useEffect( () => {
		const box = document.getElementById( `wireframe-group-${ group.id }` );

		if ( ! box ) {
			return;
		}

		box.classList.remove( HIDDEN_CLASS );
		setTarget( box.querySelector( '.wireframe-group' ) );

		return () => box.classList.add( HIDDEN_CLASS );
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
