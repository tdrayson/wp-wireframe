/**
 * A field group on the classic edit screen, in its box or in place of the content editor.
 */
import { useMemo } from '@wordpress/element';
import { FieldStoreContext } from '../../hooks/useFieldStore';
import GroupTabs from '../GroupTabs';
import fullWidth from '../fullWidth';
import useLocalFieldStore from './useLocalFieldStore';

const POSITION_CLASSES = {
	content: 'wireframe-classic-content-area',
	meta_box: 'wireframe-meta-box',
	sidebar: 'wireframe-sidebar-group',
};

/**
 * Render a group's fields over a local store that posts with the classic form.
 *
 * @param {Object}           props
 * @param {Object}           props.group    Group from `window.wireframePostFields.groups`.
 * @param {HTMLInputElement} props.input    The group's hidden `wireframe_fields[{key}]` input.
 * @param {Object}           props.readable Every value the user can read, by field id, for conditions across groups.
 * @param {boolean}          props.isNew    Whether the post is an auto-draft.
 * @param {Object}           props.errors   Errors by field id from the last failed save.
 * @param {Object}           props.rejected Values by field id the last failed save did not write.
 * @param {string}           props.postType Post type being edited.
 */
export default function ClassicGroup( {
	group,
	input,
	readable,
	isNew,
	errors,
	rejected,
	postType,
} ) {
	const store = useLocalFieldStore( group, input, {
		readable,
		isNew,
		errors,
		rejected,
		postType,
	} );
	const isSidebar = group.position === 'sidebar';
	const config = useMemo(
		() => ( isSidebar ? fullWidth( group.config ) : group.config ),
		[ isSidebar, group.config ]
	);

	return (
		<FieldStoreContext.Provider value={ store }>
			<div
				className={ `wireframe-post-fields wireframe-classic-group ${
					POSITION_CLASSES[ group.position ] ?? ''
				}` }
			>
				{ group.position === 'content' && group.title && (
					<h2 className="wireframe-classic-group__heading">
						{ group.title }
					</h2>
				) }
				<GroupTabs config={ config } />
			</div>
		</FieldStoreContext.Provider>
	);
}
