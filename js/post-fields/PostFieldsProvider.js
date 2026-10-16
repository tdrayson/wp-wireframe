/**
 * Field store for one group, backed by the block editor's post meta edits.
 */
import { useCallback, useMemo } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { FieldStoreContext } from '../hooks/useFieldStore';
import coerce from './coerce';
import useSaveErrors from './useSaveErrors';

const NO_META = {};

/**
 * Provide `config`, `values`, `setMultiple` and `errors` for a group's fields.
 *
 * @param {Object}                                 props
 * @param {Object}                                 props.group    Group from `window.wireframePostFields.groups`.
 * @param {import('@wordpress/element').ReactNode} props.children Position component.
 */
export default function PostFieldsProvider( { group, children } ) {
	const { meta, postType } = useSelect(
		( select ) => ( {
			meta: select( editorStore ).getEditedPostAttribute( 'meta' ),
			postType: select( editorStore ).getCurrentPostType(),
		} ),
		[]
	);
	const { editPost } = useDispatch( editorStore );
	const { errors, clear } = useSaveErrors( group );

	const setMultiple = useCallback(
		( edits ) => {
			editPost( { meta: coerce( edits, group.config ) } );
			clear( Object.keys( edits ) );
		},
		[ editPost, clear, group.config ]
	);

	const populate = useMemo(
		() => ( { prefix: group.prefix, context: `post:${ postType }` } ),
		[ group.prefix, postType ]
	);

	const store = useMemo(
		() => ( {
			config: group.config,
			values: meta || NO_META,
			setMultiple,
			errors,
			populate,
		} ),
		[ group.config, meta, setMultiple, errors, populate ]
	);

	return (
		<FieldStoreContext.Provider value={ store }>
			{ children }
		</FieldStoreContext.Provider>
	);
}
