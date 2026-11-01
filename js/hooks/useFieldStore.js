/**
 * Generic field store.
 *
 * The slice of state field components need, independent of where values
 * are persisted (settings REST routes, post meta, ...).
 *
 * @module useFieldStore
 */
import { createContext, useContext } from '@wordpress/element';

/** @type {import('@wordpress/element').Context} */
export const FieldStoreContext = createContext( null );

/**
 * Access the field store. Must be called within a `FieldStoreContext.Provider`.
 *
 * `populate` is the `{ prefix, context }` that `fetch` selects search with.
 *
 * @return {{ config: Object, values: Object, setMultiple: Function, errors: Object, populate: { prefix: string, context: string } }} The field store.
 */
export function useFieldStore() {
	const context = useContext( FieldStoreContext );

	if ( ! context ) {
		throw new Error(
			'useFieldStore must be used within a FieldStoreContext provider'
		);
	}

	return context;
}
