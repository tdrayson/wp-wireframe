/**
 * WP Wireframe — post field groups in the block editor.
 *
 * Reads `window.wireframePostFields` and renders each group in its position.
 */
import { registerPlugin } from '@wordpress/plugins';
import PostFieldsProvider from './post-fields/PostFieldsProvider';
import ContentArea from './post-fields/ContentArea';
import MetaBoxGroup from './post-fields/MetaBoxGroup';
import SidebarGroup from './post-fields/SidebarGroup';
import './styles/post-fields.scss';

const PLUGIN_NAME = 'wireframe-post-fields';

const POSITIONS = {
	content: ContentArea,
	meta_box: MetaBoxGroup,
	sidebar: SidebarGroup,
};

/**
 * Render every group the server sent, each in its own field store.
 */
function Root() {
	const data = window.wireframePostFields;

	if ( ! data?.groups ) {
		return null;
	}

	return data.groups.map( ( group ) => {
		const Position = POSITIONS[ group.position ];

		if ( ! Position ) {
			return null;
		}

		return (
			<PostFieldsProvider key={ group.id } group={ group }>
				<Position
					group={ group }
					supportsTitle={ data.supportsTitle }
					debug={ data.debug }
				/>
			</PostFieldsProvider>
		);
	} );
}

registerPlugin( PLUGIN_NAME, { render: Root } );
