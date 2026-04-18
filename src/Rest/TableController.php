<?php

declare(strict_types=1);

namespace Wireframe\Rest;

use Wireframe\App;
use Wireframe\Framework\ConfigLoader;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller for `table` fields.
 *
 * Registers two generic routes per page so consuming plugins never have to
 * wire their own endpoints — they supply PHP callables in the field config
 * and this controller dispatches to them.
 *
 *   GET  /{prefix}/v1/table/{pageId}/{fieldId}                 → data_callback
 *   POST /{prefix}/v1/table/{pageId}/{fieldId}/action/{action} → actions[].callback
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
     * Dispatch a data fetch to the field's `data_callback`.
     *
     * The callback receives a normalized query array and returns
     * `['items' => array, 'total' => int]` (or a WP_Error).
     */
    private static function getData(WP_REST_Request $request, string $internalId): WP_REST_Response|WP_Error
    {
        $page    = App::page($internalId);
        $fieldId = (string) $request['field'];

        $field = self::findField($page, $fieldId);

        if ($field instanceof WP_Error) {
            return $field;
        }

        $callback = $field['args']['data_callback'] ?? null;

        if (!is_callable($callback)) {
            return new WP_Error(
                'wireframe_invalid_callback',
                sprintf('Table "%s" is missing a callable data_callback.', $fieldId),
                ['status' => 500]
            );
        }

        $query = [
            'page'     => max(1, (int) ($request->get_param('page') ?? 1)),
            'per_page' => max(1, (int) ($request->get_param('per_page') ?? 10)),
            'search'   => (string) ($request->get_param('search') ?? ''),
            'orderby'  => (string) ($request->get_param('orderby') ?? ''),
            'order'    => strtolower((string) ($request->get_param('order') ?? 'asc')) === 'desc' ? 'desc' : 'asc',
            'filters'  => self::decodeFilters($request->get_param('filters')),
        ];

        $result = call_user_func($callback, $query);

        if ($result instanceof WP_Error) {
            return $result;
        }

        if (!is_array($result)) {
            return new WP_Error(
                'wireframe_invalid_response',
                'data_callback must return an array with items and total.',
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'items' => array_values($result['items'] ?? []),
            'total' => (int) ($result['total'] ?? 0),
        ]);
    }

    /**
     * Dispatch a row action to its registered callback.
     *
     * Action callbacks receive `(array $ids, WP_REST_Request $request)` and
     * return an associative array (merged into the response) or a WP_Error.
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

        $callback = $action['callback'] ?? null;

        if (!is_callable($callback)) {
            return new WP_Error(
                'wireframe_invalid_callback',
                sprintf('Action "%s" has no callable callback.', $actionId),
                ['status' => 500]
            );
        }

        $body = $request->get_json_params();
        $ids  = is_array($body['ids'] ?? null) ? array_values($body['ids']) : [];

        $result = call_user_func($callback, $ids, $request);

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
     * Resolves `args.detail_view.fetch_callback($id)` for the entry payload,
     * then `args.detail_view.render_callback($entry, $request)` for the HTML.
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

        $detail = $field['args']['detail_view'] ?? null;

        if (!is_array($detail)) {
            return new WP_Error(
                'wireframe_no_detail_view',
                sprintf('Table "%s" has no detail_view configured.', $fieldId),
                ['status' => 404]
            );
        }

        $fetch  = $detail['fetch_callback']  ?? null;
        $render = $detail['render_callback'] ?? null;

        if (!is_callable($fetch) || !is_callable($render)) {
            return new WP_Error(
                'wireframe_invalid_callback',
                sprintf('Table "%s" detail_view requires both fetch_callback and render_callback.', $fieldId),
                ['status' => 500]
            );
        }

        $entry = call_user_func($fetch, $entryId, $request);

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

        $html = call_user_func($render, $entry, $request);

        if ($html instanceof WP_Error) {
            return $html;
        }

        $title = $detail['title'] ?? '';

        if (is_callable($title)) {
            $title = call_user_func($title, $entry, $request);
        }

        return new WP_REST_Response([
            'html'  => (string) $html,
            'title' => (string) $title,
            'entry' => $entry,
        ]);
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
