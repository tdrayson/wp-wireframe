<?php

declare(strict_types=1);

namespace Wireframe\Rest;

use Wireframe\App;
use Wireframe\Framework\ConfigLoader;
use Wireframe\Framework\Unhandled;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller for `table` fields.
 *
 * Registers generic routes per page so consuming plugins don't have to
 * wire their own endpoints. Each dispatch slot is hooks-first with a
 * legacy callback fallback for back-compat:
 *
 *   GET  /{prefix}/v1/table/{pageId}/{fieldId}                 → data
 *   POST /{prefix}/v1/table/{pageId}/{fieldId}/action/{action} → row / bulk action
 *   GET  /{prefix}/v1/table/{pageId}/{fieldId}/entry/{id}      → detail view
 *
 * Filter names (per page, per field):
 *   {prefix}/table/{pageId}/{fieldId}/data
 *   {prefix}/table/{pageId}/{fieldId}/{actionId}
 *   {prefix}/table/{pageId}/{fieldId}/detail/fetch
 *   {prefix}/table/{pageId}/{fieldId}/detail/render
 *   {prefix}/table/{pageId}/{fieldId}/detail/title
 *
 * Each filter is called with `Unhandled::get()` as the default value; if no
 * listener attaches, the controller falls back to the legacy callback keys
 * (`args.data_callback`, `args.actions[].callback`, `args.detail_view.*`).
 * New consumers should prefer the hook form — the callback fallback exists
 * only so pre-existing tables keep working.
 */
final class TableController
{
    /**
     * Register REST routes for every table field across every booted plugin.
     */
    public static function register(): void
    {
        foreach (App::pages() as $internalId => $page) {
            $namespace = App::restNamespace($page['prefix']);
            $base      = '/table/' . $page['page_id'] . '/(?P<field>[a-zA-Z0-9_.-]+)';

            register_rest_route($namespace, $base, [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => fn(WP_REST_Request $r) => self::getData($r, $internalId),
                    'permission_callback' => fn() => SettingsController::checkPermission($internalId),
                ],
            ]);

            register_rest_route($namespace, $base . '/action/(?P<action>[a-zA-Z0-9_-]+)', [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => fn(WP_REST_Request $r) => self::runAction($r, $internalId),
                    'permission_callback' => fn() => SettingsController::checkPermission($internalId),
                ],
            ]);

            register_rest_route($namespace, $base . '/entry/(?P<id>[a-zA-Z0-9_-]+)', [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => fn(WP_REST_Request $r) => self::getEntry($r, $internalId),
                    'permission_callback' => fn() => SettingsController::checkPermission($internalId),
                ],
            ]);
        }
    }

    /**
     * Dispatch a data fetch via the data hook, falling back to `data_callback`.
     *
     * Handler signature: `($query): ['items' => array, 'total' => int]` or
     * `WP_Error`. The filter receives `Unhandled::get()` as the default value
     * so the dispatcher can tell an unhandled filter from a handler that
     * legitimately returned an empty list.
     */
    private static function getData(WP_REST_Request $request, string $internalId): WP_REST_Response|WP_Error
    {
        $page    = App::page($internalId);
        $fieldId = (string) $request['field'];

        $field = self::findField($page, $fieldId);

        if ($field instanceof WP_Error) {
            return $field;
        }

        $query = [
            'page'     => max(1, (int) ($request->get_param('page') ?? 1)),
            'per_page' => max(1, (int) ($request->get_param('per_page') ?? 10)),
            'search'   => (string) ($request->get_param('search') ?? ''),
            'orderby'  => (string) ($request->get_param('orderby') ?? ''),
            'order'    => strtolower((string) ($request->get_param('order') ?? 'asc')) === 'desc' ? 'desc' : 'asc',
            'filters'  => self::decodeFilters($request->get_param('filters')),
        ];

        $hook   = self::tableHook($page, $fieldId, 'data');
        $result = self::dispatchHook($hook, $field['args']['data_callback'] ?? null, [$query]);

        if ($result instanceof Unhandled) {
            return new WP_Error(
                'wireframe_no_handler',
                sprintf('Table "%s" has no handler for "%s" and no data_callback.', $fieldId, $hook),
                ['status' => 500]
            );
        }

        if ($result instanceof WP_Error) {
            return $result;
        }

        if (!is_array($result)) {
            return new WP_Error(
                'wireframe_invalid_response',
                'Table data handler must return an array with items and total.',
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'items' => array_values($result['items'] ?? []),
            'total' => (int) ($result['total'] ?? 0),
        ]);
    }

    /**
     * Dispatch a row action via the per-action hook, falling back to the
     * legacy `args.actions[].callback`.
     *
     * Handler signature: `(array $ids, WP_REST_Request $request)` → array
     * (merged into the response) or `WP_Error`.
     */
    private static function runAction(WP_REST_Request $request, string $internalId): WP_REST_Response|WP_Error
    {
        $page     = App::page($internalId);
        $fieldId  = (string) $request['field'];
        $actionId = (string) $request['action'];

        $field = self::findField($page, $fieldId);

        if ($field instanceof WP_Error) {
            return $field;
        }

        $action = self::findAction($field['args']['actions'] ?? [], $actionId);

        if ($action === null) {
            return new WP_Error(
                'wireframe_unknown_action',
                sprintf('Action "%s" is not registered on table "%s".', $actionId, $fieldId),
                ['status' => 404]
            );
        }

        $body = $request->get_json_params();
        $ids  = is_array($body['ids'] ?? null) ? array_values($body['ids']) : [];

        $hook   = self::tableHook($page, $fieldId, $actionId);
        $result = self::dispatchHook($hook, $action['callback'] ?? null, [$ids, $request]);

        if ($result instanceof Unhandled) {
            return new WP_Error(
                'wireframe_no_handler',
                sprintf('Action "%s" on table "%s" has no handler for "%s" and no callback.', $actionId, $fieldId, $hook),
                ['status' => 500]
            );
        }

        if ($result instanceof WP_Error) {
            return $result;
        }

        if (!is_array($result)) {
            $result = ['success' => (bool) $result];
        }

        return new WP_REST_Response($result);
    }

    /**
     * Fetch and render a single entry for the detail view.
     *
     * Three dispatch slots — each hooks-first, callback-fallback:
     *  - `detail/fetch`  ($entryId, $request) → entry array | WP_Error
     *  - `detail/render` ($entry,  $request) → HTML string | WP_Error
     *  - `detail/title`  ($entry,  $request) → string (optional)
     *
     * Returns `{ html, title, entry }`.
     */
    private static function getEntry(WP_REST_Request $request, string $internalId): WP_REST_Response|WP_Error
    {
        $page    = App::page($internalId);
        $fieldId = (string) $request['field'];
        $entryId = (string) $request['id'];

        $field = self::findField($page, $fieldId);

        if ($field instanceof WP_Error) {
            return $field;
        }

        $detail = $field['args']['detail_view'] ?? [];

        if (!is_array($detail)) {
            $detail = [];
        }

        $fetchHook  = self::tableHook($page, $fieldId, 'detail/fetch');
        $renderHook = self::tableHook($page, $fieldId, 'detail/render');
        $titleHook  = self::tableHook($page, $fieldId, 'detail/title');

        $entry = self::dispatchHook($fetchHook, $detail['fetch_callback'] ?? null, [$entryId, $request]);

        if ($entry instanceof Unhandled) {
            return new WP_Error(
                'wireframe_no_detail_view',
                sprintf('Table "%s" has no handler for "%s" and no detail_view.fetch_callback.', $fieldId, $fetchHook),
                ['status' => 404]
            );
        }

        if ($entry instanceof WP_Error) {
            return $entry;
        }

        if ($entry === null || $entry === [] || $entry === false) {
            return new WP_Error(
                'wireframe_entry_not_found',
                sprintf('Entry "%s" not found.', $entryId),
                ['status' => 404]
            );
        }

        $html = self::dispatchHook($renderHook, $detail['render_callback'] ?? null, [$entry, $request]);

        if ($html instanceof Unhandled) {
            return new WP_Error(
                'wireframe_no_handler',
                sprintf('Table "%s" detail view has no handler for "%s" and no render_callback.', $fieldId, $renderHook),
                ['status' => 500]
            );
        }

        if ($html instanceof WP_Error) {
            return $html;
        }

        $title = self::resolveTitle($titleHook, $detail['title'] ?? '', $entry, $request);

        return new WP_REST_Response([
            'html'  => (string) $html,
            'title' => (string) $title,
            'entry' => $entry,
        ]);
    }

    /**
     * Resolve the detail-view title, preferring hook → callable → string.
     */
    private static function resolveTitle(string $hook, mixed $configValue, array $entry, WP_REST_Request $request): string
    {
        $fallback = is_callable($configValue) ? $configValue : null;
        $result   = self::dispatchHook($hook, $fallback, [$entry, $request]);

        if ($result instanceof Unhandled) {
            return is_string($configValue) ? $configValue : '';
        }

        return is_string($result) ? $result : '';
    }

    /**
     * Build a filter name in the table namespace for a given page/field/verb.
     */
    private static function tableHook(array $page, string $fieldId, string $verb): string
    {
        return App::hookName(
            $page['prefix'],
            'table/' . $page['page_id'] . '/' . $fieldId . '/' . $verb
        );
    }

    /**
     * Hooks-first dispatch with legacy callback fallback.
     *
     * Calls `apply_filters($hook, Unhandled::get(), ...$args)`. If the
     * sentinel comes back unchanged the filter was unhandled, so we fall
     * back to the legacy callable. Returns `Unhandled::get()` when neither
     * responded so the caller can surface a proper 500.
     */
    private static function dispatchHook(string $hook, mixed $fallback, array $args): mixed
    {
        $result = apply_filters($hook, Unhandled::get(), ...$args);

        if (!($result instanceof Unhandled)) {
            return $result;
        }

        if (is_callable($fallback)) {
            return call_user_func($fallback, ...$args);
        }

        return Unhandled::get();
    }

    /**
     * Look up a table field by id in the page's config.
     */
    private static function findField(array $page, string $fieldId): array|WP_Error
    {
        $fields = ConfigLoader::flatFields($page['config']);
        $field  = $fields[$fieldId] ?? null;

        if ($field === null || ($field['type'] ?? '') !== 'table') {
            return new WP_Error(
                'wireframe_field_not_found',
                sprintf('Table field "%s" not found.', $fieldId),
                ['status' => 404]
            );
        }

        return $field;
    }

    /**
     * Find an action entry by id within a field's actions list.
     */
    private static function findAction(array $actions, string $actionId): ?array
    {
        foreach ($actions as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? '') === $actionId) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Normalize filters from the query string into an array.
     */
    private static function decodeFilters(mixed $filters): array
    {
        if (is_array($filters)) {
            return $filters;
        }

        if (is_string($filters) && $filters !== '') {
            $decoded = json_decode($filters, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
