/**
 * RepeaterEdit — repeatable field group with add/remove/reorder.
 */
import { Button, BaseControl } from '@wordpress/components';
import { useState, useCallback, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { gridColumn } from '../../utils/gridColumn';
import { mapField } from '../../utils/mapConfig';
import { interpolate } from '../../utils/interpolate';
import { evaluateCondition } from '../../utils/conditions';
import { getFieldHelp } from './useFieldError';
import { customEditComponents } from './index';

export default function RepeaterEdit( { data, field, onChange } ) {
	const { _args = {} } = field;
	const rows = Array.isArray( data[ field.id ] ) ? data[ field.id ] : [];
	const subfields = _args.subfields || [];
	const minRows = _args.min_rows ?? 0;
	const maxRows = _args.max_rows ?? Infinity;
	const readOnly = !! field.readOnly;
	const canAdd = ! readOnly && rows.length < maxRows && ( _args.add_row !== false );
	const canDelete = ! readOnly && rows.length > minRows && ( _args.delete_row !== false );
	const canDuplicate = ! readOnly && ( _args.duplicate_row || false );
	const canSort = ! readOnly && _args.sortable;
	const collapsible = _args.collapsible || false;
	const startCollapsed = _args.collapsed || false;
	const titleTemplate = _args.title_template || '';

	// Rows carry no id, so keep a parallel list of keys; also absorbs external changes to the row count.
	const nextRowKey = useRef( 0 );
	const rowKeys = useRef( [] );
	while ( rowKeys.current.length < rows.length ) {
		rowKeys.current.push( nextRowKey.current++ );
	}
	rowKeys.current.length = rows.length;

	const listRef = useRef( null );
	const focusAfterMove = useRef( null );

	// Moved rows remount, so hand focus back to the moved row's arrow (or the other one if now disabled).
	useEffect( () => {
		if ( ! focusAfterMove.current ) {
			return;
		}
		const { rowKey, direction } = focusAfterMove.current;
		focusAfterMove.current = null;
		const buttons = [ ...( listRef.current?.querySelectorAll( `[data-row-key="${ rowKey }"] [data-move]` ) ?? [] ) ];
		const target = buttons.find( ( b ) => b.dataset.move === direction && ! b.disabled )
			?? buttons.find( ( b ) => ! b.disabled );
		target?.focus();
	} );

	const [ collapsedRows, setCollapsedRows ] = useState(
		() => startCollapsed ? [ ...rowKeys.current ] : []
	);

	const updateRows = useCallback(
		( newRows ) => onChange( { [ field.id ]: newRows } ),
		[ field.id, onChange ]
	);

	const addRow = () => {
		const emptyRow = {};
		subfields.forEach( ( subConfig ) => {
			emptyRow[ subConfig.id ] = subConfig.default ?? '';
		} );
		updateRows( [ ...rows, emptyRow ] );
	};

	const removeRow = ( index ) => {
		rowKeys.current.splice( index, 1 );
		updateRows( rows.filter( ( _, i ) => i !== index ) );
	};

	const duplicateRow = ( index ) => {
		const newRows = [ ...rows ];
		newRows.splice( index + 1, 0, { ...rows[ index ] } );
		rowKeys.current.splice( index + 1, 0, nextRowKey.current++ );
		updateRows( newRows );
	};

	const updateRow = ( index, edits ) => {
		const newRows = rows.map( ( row, i ) =>
			i === index ? { ...row, ...edits } : row
		);
		updateRows( newRows );
	};

	const toggleCollapse = ( rowKey ) => {
		setCollapsedRows( ( prev ) =>
			prev.includes( rowKey )
				? prev.filter( ( k ) => k !== rowKey )
				: [ ...prev, rowKey ]
		);
	};

	const moveRow = ( from, to ) => {
		if ( to < 0 || to >= rows.length ) return;
		const newRows = [ ...rows ];
		const [ moved ] = newRows.splice( from, 1 );
		newRows.splice( to, 0, moved );
		const [ movedKey ] = rowKeys.current.splice( from, 1 );
		rowKeys.current.splice( to, 0, movedKey );
		focusAfterMove.current = { rowKey: movedKey, direction: to < from ? 'up' : 'down' };
		updateRows( newRows );
	};

	const getRowTitle = ( row, index ) => {
		if ( ! titleTemplate ) {
			return `#${ index + 1 }`;
		}
		return interpolate( titleTemplate, row ) || `#${ index + 1 }`;
	};

	return (
		<BaseControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ getFieldHelp( field.description, undefined, data ) }
			id={ `wireframe-repeater-${ field.id }` }
		>
			<div className="wireframe-repeater" ref={ listRef }>
				{ rows.length === 0 && _args.empty_message && (
					<p className="wireframe-repeater__empty">{ _args.empty_message }</p>
				) }

				{ rows.map( ( row, index ) => {
					const rowKey = rowKeys.current[ index ];
					const isCollapsed = collapsedRows.includes( rowKey );

					return (
						// Index in the key remounts moved rows rather than moving their DOM: a moved TinyMCE iframe reloads blank.
						<div key={ `${ rowKey }-${ index }` } data-row-key={ rowKey } className={ `wireframe-repeater__row ${ isCollapsed ? 'is-collapsed' : '' }` }>
							<div className="wireframe-repeater__row-header">
								{ collapsible && (
									<button
										className="wireframe-repeater__toggle"
										onClick={ () => toggleCollapse( rowKey ) }
										aria-label={ isCollapsed ? __( 'Expand', 'wp-wireframe' ) : __( 'Collapse', 'wp-wireframe' ) }
									>
										{ isCollapsed ? '\u25B6' : '\u25BC' }
									</button>
								) }
								<span className="wireframe-repeater__row-title">
									{ getRowTitle( row, index ) }
								</span>
								<div className="wireframe-repeater__row-actions">
									{ canSort && (
										<>
											<Button
												size="small"
												variant="tertiary"
												disabled={ index === 0 }
												data-move="up"
												onClick={ () => moveRow( index, index - 1 ) }
												aria-label={ __( 'Move up', 'wp-wireframe' ) }
											>
												&uarr;
											</Button>
											<Button
												size="small"
												variant="tertiary"
												disabled={ index === rows.length - 1 }
												data-move="down"
												onClick={ () => moveRow( index, index + 1 ) }
												aria-label={ __( 'Move down', 'wp-wireframe' ) }
											>
												&darr;
											</Button>
										</>
									) }
									{ canDuplicate && (
										<Button
											size="small"
											variant="tertiary"
											onClick={ () => duplicateRow( index ) }
											disabled={ rows.length >= maxRows }
										>
											{ __( 'Duplicate', 'wp-wireframe' ) }
										</Button>
									) }
									{ canDelete && (
										<Button
											size="small"
											variant="tertiary"
											isDestructive
											onClick={ () => removeRow( index ) }
										>
											{ __( 'Remove', 'wp-wireframe' ) }
										</Button>
									) }
								</div>
							</div>

							{ ! isCollapsed && (
								<div className="wireframe-repeater__row-body wireframe-grid">
									{ subfields.map( ( subConfig ) => {
										// Subfield conditions evaluate against the row, not page-level settings.
										if ( subConfig.conditions && ! evaluateCondition( subConfig.conditions, row ) ) {
											return null;
										}

										const subField = mapField( subConfig );
										const EditComponent = customEditComponents[ subConfig.type ];

										if ( ! EditComponent ) return null;

										// Subfields inherit the parent repeater's readOnly state —
										// access control treats the repeater as one editable unit.
										const subFieldWithReadOnly = readOnly
											? { ...subField, readOnly: true }
											: subField;

										return (
											<div
												key={ subField.id }
												className="wireframe-grid__cell"
												style={ { gridColumn: gridColumn( subConfig ) } }
											>
												<EditComponent
													data={ row }
													field={ subFieldWithReadOnly }
													onChange={ ( edits ) => updateRow( index, edits ) }
												/>
											</div>
										);
									} ) }
								</div>
							) }
						</div>
					);
				} ) }

				{ canAdd && (
					<Button
						__next40pxDefaultSize
						variant="secondary"
						className="wireframe-repeater__add"
						onClick={ addRow }
					>
						{ _args.add_label || __( 'Add Row', 'wp-wireframe' ) }
					</Button>
				) }
			</div>
		</BaseControl>
	);
}
