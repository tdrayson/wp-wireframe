/**
 * WP Wireframe — post field groups on the classic edit screen.
 *
 * Reads `window.wireframePostFields` and mounts each group into the container PHP printed for it.
 */
import { createRoot } from '@wordpress/element';
import ClassicGroup from './post-fields/classic/ClassicGroup';
import './styles/post-fields.scss';
import './styles/post-fields-classic.scss';

/**
 * Find a group's container: its box, or the content area after the title.
 *
 * @param {Object} group Group from `window.wireframePostFields.groups`.
 * @return {Element|null} The container, or null when PHP printed none.
 */
function containerOf( group ) {
	const id =
		group.position === 'content'
			? `wireframe-content-${ group.id }`
			: `wireframe-group-${ group.id }`;

	return document.getElementById( id );
}

const data = window.wireframePostFields;

for ( const group of data?.groups || [] ) {
	const container = containerOf( group );
	const node = container?.querySelector( '.wireframe-group' );
	const input = container?.querySelector(
		`input[name="wireframe_fields[${ group.id }]"]`
	);

	// Fields mark `required` for display; the server enforces it only on publish, so drafts must still submit.
	if ( input?.form ) {
		input.form.noValidate = true;
	}

	if ( node && input ) {
		createRoot( node ).render(
			<ClassicGroup
				group={ group }
				input={ input }
				isNew={ Boolean( data.isNew ) }
				errors={ data.errors }
				rejected={ data.rejected }
				postType={ data.postType }
			/>
		);
	}
}
