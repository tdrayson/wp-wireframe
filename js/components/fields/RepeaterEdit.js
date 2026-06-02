/**
 * RepeaterEdit — repeatable field group with add/remove/reorder.
 */
import { Button, BaseControl } from '@wordpress/components';
import { useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { mapField } from '../../utils/mapConfig';
import { interpolate } from '../../utils/interpolate';
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

	const [ collapsedRows, setCollapsedRows ] = useState(
		() => startCollapsed ? rows.map( ( _, i ) => i ) : []
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
		const newRows = rows.filter( ( _, i ) => i !== index );
		updateRows( newRows );
		setCollapsedRows( ( prev ) => prev.filter( ( i ) => i !== index ).map( ( i ) => i > index ? i - 1 : i ) );
	};

	const duplicateRow = ( index ) => {
		const newRows = [ ...rows ];
		newRows.splice( index + 1, 0, { ...rows[ index ] } );
		updateRows( newRows );
	};

	const updateRow = ( index, edits ) => {
		const newRows = rows.map( ( row, i ) =>
			i === index ? { ...row, ...edits } : row
		);
		updateRows( newRows );
	};

	const toggleCollapse = ( index ) => {
		setCollapsedRows( ( prev ) =>
			prev.includes( index )
				? prev.filter( ( i ) => i !== index )
				: [ ...prev, index ]
		);
	};

	const moveRow = ( from, to ) => {
		if ( to < 0 || to >= rows.length ) return;
		const newRows = [ ...rows ];
		const [ moved ] = newRows.splice( from, 1 );
		newRows.splice( to, 0, moved );
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
			<div className="wireframe-repeater">
				{ rows.length === 0 && _args.empty_message && (
					<p className="wireframe-repeater__empty">{ _args.empty_message }</p>
				) }

				{ rows.map( ( row, index ) => {
					const isCollapsed = collapsedRows.includes( index );

					return (
						<div key={ index } className={ `wireframe-repeater__row ${ isCollapsed ? 'is-collapsed' : '' }` }>
							<div className="wireframe-repeater__row-header">
								{ collapsible && (
									<button
										className="wireframe-repeater__toggle"
										onClick={ () => toggleCollapse( index ) }
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
												onClick={ () => moveRow( index, index - 1 ) }
												aria-label={ __( 'Move up', 'wp-wireframe' ) }
											>
												&uarr;
											</Button>
											<Button
												size="small"
												variant="tertiary"
												disabled={ index === rows.length - 1 }
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
										const subField = mapField( subConfig );
										const EditComponent = customEditComponents[ subConfig.type ];
										const columns = subConfig.columns || 12;

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
												style={ { gridColumn: `span ${ columns }` } }
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
