/**
 * A `content` group, rendered in place of the block canvas.
 */
import {
	createPortal,
	useEffect,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { TabNav, TabSections } from '../components/FieldTabs';
import { useActiveTab } from './GroupTabs';

const CANVAS_REGION = '.interface-interface-skeleton__content';
const FALLBACK_REGIONS = [ '.edit-post-layout__metaboxes', '#poststuff' ];
const BODY_CLASS = 'wireframe-content-mode';

/**
 * Find where to mount: the canvas region, else the meta box area.
 *
 * @return {{ node: Element|null, isCanvas: boolean }} The mount node, and whether it is the canvas.
 */
function findTarget() {
	const canvas = document.querySelector( CANVAS_REGION );

	if ( canvas ) {
		return { node: canvas, isCanvas: true };
	}

	const fallback = FALLBACK_REGIONS.map( ( selector ) =>
		document.querySelector( selector )
	).find( Boolean );

	if ( SCRIPT_DEBUG ) {
		// eslint-disable-next-line no-console
		console.warn(
			`wp-wireframe: ${ CANVAS_REGION } not found; rendering the content group in the meta box area.`
		);
	}

	return { node: fallback ?? null, isCanvas: false };
}

/**
 * The post title as a large, borderless, auto-growing field, like the canvas title.
 */
function TitleField() {
	const title = useSelect(
		( select ) => select( editorStore ).getEditedPostAttribute( 'title' ),
		[]
	);
	const { editPost } = useDispatch( editorStore );
	const ref = useRef( null );

	useLayoutEffect( () => {
		const field = ref.current;

		field.style.height = 'auto';
		field.style.height = `${ field.scrollHeight }px`;
	}, [ title ] );

	return (
		<textarea
			ref={ ref }
			rows={ 1 }
			className="wireframe-content-area__title"
			aria-label={ __( 'Add title', 'wp-wireframe' ) }
			placeholder={ __( 'Add title', 'wp-wireframe' ) }
			value={ title ?? '' }
			onChange={ ( event ) =>
				editPost( {
					title: event.target.value.replace( /[\r\n]+/g, ' ' ),
				} )
			}
			onKeyDown={ ( event ) =>
				event.key === 'Enter' && event.preventDefault()
			}
		/>
	);
}

/**
 * @param {Object}  props
 * @param {Object}  props.group         Group from `window.wireframePostFields.groups`.
 * @param {boolean} props.supportsTitle Whether the post type supports a title.
 */
export default function ContentArea( { group, supportsTitle } ) {
	const [ target, setTarget ] = useState( null );
	const { tabs, active, current, select } = useActiveTab( group.config );
	const { setIsListViewOpened } = useDispatch( editorStore );

	useEffect( () => setTarget( findTarget() ), [] );

	useEffect( () => {
		if ( ! target?.isCanvas ) {
			return;
		}

		// The list view's toggle is hidden in content mode, so a saved open state would strand it open.
		setIsListViewOpened( false );
		document.body.classList.add( BODY_CLASS );

		return () => document.body.classList.remove( BODY_CLASS );
	}, [ target, setIsListViewOpened ] );

	if ( ! target?.node ) {
		return null;
	}

	const hasHeader = supportsTitle || group.title || tabs.length > 1;

	return createPortal(
		<div className="wireframe-post-fields wireframe-content-area">
			{ hasHeader && (
				<div className="wireframe-content-area__header">
					<div className="wireframe-content-area__column">
						{ supportsTitle && <TitleField /> }
						{ group.title && (
							<h2 className="wireframe-content-area__heading">
								{ group.title }
							</h2>
						) }
						<TabNav
							tabs={ tabs }
							activeTab={ active }
							onSelect={ select }
						/>
					</div>
				</div>
			) }
			<div className="wireframe-content-area__column">
				<TabSections tab={ current } />
			</div>
		</div>,
		target.node
	);
}
