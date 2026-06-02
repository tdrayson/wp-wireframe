/**
 * Conditions evaluator.
 *
 * Evaluates conditional visibility rules against current field values.
 * Supports the same DSL as the WP Extended framework:
 *   { all: [ ...rules ] }   — AND combinator
 *   { any: [ ...rules ] }   — OR combinator
 *   { field, operator, value } — single rule
 *
 * Operators: equals, not_equals, truthy, falsy, in, not_in,
 *            between, starts_with, ends_with, contains,
 *            not_contains, is_empty, is_not_empty, gt, gte, lt, lte.
 */

/**
 * Evaluate a single condition rule.
 *
 * @param {Object} rule   The rule: { field, operator, value }.
 * @param {Object} values Current field values.
 * @return {boolean}
 */
function evaluateRule( rule, values ) {
	const { field, operator, value: expected } = rule;
	const actual = values[ field ];

	switch ( operator ) {
		case 'equals':
			// eslint-disable-next-line eqeqeq
			return actual == expected;
		case 'not_equals':
			// eslint-disable-next-line eqeqeq
			return actual != expected;
		case 'truthy':
			return !! actual;
		case 'falsy':
			return ! actual;
		case 'in':
			return Array.isArray( expected ) && expected.includes( actual );
		case 'not_in':
			return Array.isArray( expected ) && ! expected.includes( actual );
		case 'between': {
			const [ min, max ] = Array.isArray( expected ) ? expected : [ 0, 0 ];
			const num = Number( actual );
			return num >= min && num <= max;
		}
		case 'starts_with':
			return typeof actual === 'string' && actual.startsWith( expected );
		case 'ends_with':
			return typeof actual === 'string' && actual.endsWith( expected );
		case 'contains':
			if ( typeof actual === 'string' ) {
				return actual.includes( expected );
			}
			if ( Array.isArray( actual ) ) {
				return actual.includes( expected );
			}
			return false;
		case 'not_contains':
			if ( typeof actual === 'string' ) {
				return ! actual.includes( expected );
			}
			if ( Array.isArray( actual ) ) {
				return ! actual.includes( expected );
			}
			return true;
		case 'is_empty':
			return actual === '' || actual === null || actual === undefined ||
				( Array.isArray( actual ) && actual.length === 0 );
		case 'is_not_empty':
			return actual !== '' && actual !== null && actual !== undefined &&
				! ( Array.isArray( actual ) && actual.length === 0 );
		case 'gt':
			return Number( actual ) > Number( expected );
		case 'gte':
			return Number( actual ) >= Number( expected );
		case 'lt':
			return Number( actual ) < Number( expected );
		case 'lte':
			return Number( actual ) <= Number( expected );
		default:
			return true;
	}
}

/**
 * Evaluate a condition node (may be a combinator or single rule).
 *
 * @param {Object} condition The condition object.
 * @param {Object} values    Current field values.
 * @return {boolean}
 */
export function evaluateCondition( condition, values ) {
	if ( ! condition || typeof condition !== 'object' ) {
		return true;
	}

	// AND combinator.
	if ( Array.isArray( condition.all ) ) {
		return condition.all.every( ( rule ) => evaluateCondition( rule, values ) );
	}

	// OR combinator.
	if ( Array.isArray( condition.any ) ) {
		return condition.any.some( ( rule ) => evaluateCondition( rule, values ) );
	}

	// Single rule.
	if ( condition.field && condition.operator ) {
		return evaluateRule( condition, values );
	}

	return true;
}

/**
 * Check if a field is visible given current values.
 *
 * @param {Object} fieldConfig The field config { type, args }.
 * @param {Object} values      Current field values.
 * @return {boolean}
 */
export function isFieldVisible( fieldConfig, values ) {
	const conditions = fieldConfig?.conditions;
	if ( ! conditions ) {
		return true;
	}
	return evaluateCondition( conditions, values );
}

/**
 * Check if a section is visible given current values.
 *
 * @param {Object} sectionConfig Section config.
 * @param {Object} values        Current field values.
 * @return {boolean}
 */
export function isSectionVisible( sectionConfig, values ) {
	const conditions = sectionConfig?.conditions;
	if ( ! conditions ) {
		return true;
	}
	return evaluateCondition( conditions, values );
}

/**
 * Check if a tab is visible given current values.
 *
 * Mirrors `isSectionVisible` — tabs support the same `conditions` shape as
 * sections and fields, evaluated against current values.
 *
 * @param {Object} tabConfig Tab config.
 * @param {Object} values    Current field values.
 * @return {boolean}
 */
export function isTabVisible( tabConfig, values ) {
	const conditions = tabConfig?.conditions;
	if ( ! conditions ) {
		return true;
	}
	return evaluateCondition( conditions, values );
}
