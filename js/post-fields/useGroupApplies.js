/**
 * Location rules evaluated in the editor, mirroring `Location::matches()` in PHP.
 */
import { useMemo } from '@wordpress/element';
import { evaluateCondition } from '../utils/conditions';

/**
 * Rewrite `in`/`not_in` on list-valued params (user roles, terms) as any/none of `contains`.
 *
 * @param {Object} node    Rule tree node.
 * @param {Object} context Location context.
 * @return {Object} The node, rewritten where it tests membership of a list.
 */
function expandListMembership( node, context ) {
	for ( const combinator of [ 'all', 'any' ] ) {
		if ( Array.isArray( node?.[ combinator ] ) ) {
			return {
				[ combinator ]: node[ combinator ].map( ( child ) =>
					expandListMembership( child, context )
				),
			};
		}
	}

	const { field, operator, value } = node || {};
	const isMembership = operator === 'in' || operator === 'not_in';

	if (
		! isMembership ||
		! Array.isArray( context[ field ] ) ||
		! Array.isArray( value )
	) {
		return node;
	}

	const rules = value.map( ( item ) => ( {
		field,
		operator: operator === 'in' ? 'contains' : 'not_contains',
		value: item,
	} ) );

	return { [ operator === 'in' ? 'any' : 'all' ]: rules };
}

/**
 * Whether a group's location matches the live context.
 *
 * @param {Object} group   Group from `window.wireframePostFields.groups`.
 * @param {Object} context Context from `buildLiveContext()`.
 * @return {boolean} True when the group should render.
 */
export function groupApplies( group, context ) {
	return evaluateCondition(
		expandListMembership( group.location, context ),
		context
	);
}

/**
 * Get the groups whose location matches the live context.
 *
 * @param {Object[]} groups  Groups from `window.wireframePostFields.groups`.
 * @param {Object}   context Context from `buildLiveContext()`.
 * @return {Object[]} The groups that should render.
 */
export default function useGroupApplies( groups, context ) {
	return useMemo(
		() => groups.filter( ( group ) => groupApplies( group, context ) ),
		[ groups, context ]
	);
}
