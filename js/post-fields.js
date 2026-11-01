/**
 * WP Wireframe — post field groups in the block editor.
 *
 * Reads `window.wireframePostFields` and renders each group whose location matches the edited post, in its position.
 */
import { getPlugin, registerPlugin } from '@wordpress/plugins';
import registerBindings from './post-fields/bindings';
import PostFieldsProvider from './post-fields/PostFieldsProvider';
import ContentArea from './post-fields/ContentArea';
import MetaBoxGroup from './post-fields/MetaBoxGroup';
import SidebarGroup from './post-fields/SidebarGroup';
import useLiveContext from './post-fields/useLiveContext';
import useGroupApplies from './post-fields/useGroupApplies';
import useCanvasLock from './post-fields/useCanvasLock';
import stripHiddenMeta, {
	useRevertHiddenEdits,
} from './post-fields/stripHiddenMeta';
import './styles/post-fields.scss';

const PLUGIN_NAME = 'wireframe-post-fields';

const POSITIONS = {
	content: ContentArea,
	meta_box: MetaBoxGroup,
	sidebar: SidebarGroup,
};

/**
 * Render every group that applies to the edited post, each in its own field store; groups from every bundled copy.
 */
function Root() {
	const data = window.wireframePostFields;
	const context = useLiveContext( data );
	const groups = useGroupApplies( data.groups, context );

	useCanvasLock(
		data,
		groups.some( ( group ) => group.position === 'content' )
	);
	useRevertHiddenEdits( groups );

	return groups.map( ( group ) => {
		const Position = POSITIONS[ group.position ];

		if ( ! Position ) {
			return null;
		}

		return (
			<PostFieldsProvider key={ group.key } group={ group }>
				<Position
					group={ group }
					supportsTitle={ data.supportsTitle }
					debug={ data.debug }
				/>
			</PostFieldsProvider>
		);
	} );
}

if ( window.wireframePostFields?.groups && ! getPlugin( PLUGIN_NAME ) ) {
	stripHiddenMeta( window.wireframePostFields );
	registerBindings( window.wireframePostFields.groups );
	registerPlugin( PLUGIN_NAME, { render: Root } );
}
