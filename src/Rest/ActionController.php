<?php

declare(strict_types=1);

namespace Wireframe\Rest;

use Wireframe\App;
use Wireframe\Framework\Access\AccessResolver;
use Wireframe\Framework\Access\ConfigAccessMap;
use Wireframe\Framework\ConfigLoader;
use Wireframe\Framework\Sanitizer;
use Wireframe\Framework\Unhandled;
use Wireframe\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller for `action` fields and page `header_actions`.
 *
 * One generic route per page dispatches each button press to a per-button
 * WordPress filter:
 *
 *   POST /{prefix}/v1/action/{pageId}/{fieldId}/{actionId}
 *      → apply_filters(
 *            "{$prefix}/action/{$pageId}/{$fieldId}/{$actionId}",
 *            Unhandled::get(),
 *            $values,
 *            $request
 *        )
 *
 *   POST /{prefix}/v1/header_action/{pageId}/{actionId}
 *      → apply_filters("{$prefix}/header_action/{$pageId}/{$actionId}", …same args)
 *
 * The handler receives the page's in-flight form values, sanitized through
 * the same per-type handlers the save flow uses — so it can read sibling
 * field selections without having to re-sanitize them. In RBAC mode the
 * values are scoped to the user's access first (see scopeToAccess()).
 *
 * Security boundaries:
 *  - `permission_callback` reuses `SettingsController::checkPermission()`,
 *    so the route is gated by the page's `capability` (default
 *    `manage_options`) and the standard WP REST nonce.
 *  - Field lookup goes through `ConfigLoader::flatFields()` and 404s unless
 *    the target is actually an `action` field.
 *  - The requested `actionId` must be declared in `args.buttons[]` (or be
 *    the sugar single-button case where it equals the field id).
 *  - Header actions must be declared without an `href`, and 403 when the
 *    user fails the action's `access` rule.
 *  - `Sanitizer::sanitize()` only iterates configured fields, so unknown
 *    keys in the payload are dropped (no mass assignment).
 *  - `$values` never holds fields the user can't view, nor a client-supplied
 *    value for a field the user can only view. `$request` still carries the
 *    raw body, so handlers must read `$values`.
 *  - Config carries no callable references, so there is no callable target
 *    name to leak into the page source.
 */
final class ActionController
{
    public static function register(): void
    {
        foreach (App::pages() as $internalId => $page) {
            $namespace = App::restNamespace($page['prefix']);
            $route     = '/action/' . $page['page_id']
                . '/(?P<field>[a-zA-Z0-9_.-]+)'
                . '/(?P<action>[a-zA-Z0-9_.-]+)';

            register_rest_route($namespace, $route, [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => fn(WP_REST_Request $r) => self::run($r, $internalId),
                    'permission_callback' => fn() => SettingsController::checkPermission($internalId),
                ],
            ]);

            register_rest_route($namespace, '/header_action/' . $page['page_id'] . '/(?P<action>[a-zA-Z0-9_.-]+)', [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => fn(WP_REST_Request $r) => self::runHeaderAction($r, $internalId),
                    'permission_callback' => fn() => SettingsController::checkPermission($internalId),
                ],
            ]);
        }
    }

    private static function run(WP_REST_Request $request, string $internalId): WP_REST_Response|WP_Error
    {
        $page     = App::page($internalId);
        $fieldId  = (string) $request['field'];
        $actionId = (string) $request['action'];

        $field = self::findField($page, $fieldId);

        if ($field instanceof WP_Error) {
            return $field;
        }

        if (!self::buttonExists($field, $actionId)) {
            return new WP_Error(
                'wireframe_unknown_action',
                sprintf('Action "%s" is not declared on field "%s".', $actionId, $fieldId),
                ['status' => 404]
            );
        }

        $hook = App::hookName(
            $page['prefix'],
            'action/' . $page['page_id'] . '/' . $fieldId . '/' . $actionId
        );

        return self::dispatch($hook, $request, $page);
    }

    private static function runHeaderAction(WP_REST_Request $request, string $internalId): WP_REST_Response|WP_Error
    {
        $page     = App::page($internalId);
        $actionId = (string) $request['action'];
        $action   = self::findHeaderAction($page, $actionId);

        if ($action === null || !empty($action['href'])) {
            return new WP_Error(
                'wireframe_unknown_action',
                sprintf('Header action "%s" is not declared.', $actionId),
                ['status' => 404]
            );
        }

        $resolver = new AccessResolver($page['capability']);

        if ($resolver->visibleHeaderActions([$action]) === []) {
            return new WP_Error(
                'rest_forbidden',
                sprintf('You are not allowed to run header action "%s".', $actionId),
                ['status' => 403]
            );
        }

        $hook = App::hookName($page['prefix'], 'header_action/' . $page['page_id'] . '/' . $actionId);

        return self::dispatch($hook, $request, $page);
    }

    /**
     * Fire a dispatch filter with the request's scoped, sanitized values and
     * turn its result into a REST response.
     *
     * @param string          $hook    Filter name.
     * @param WP_REST_Request $request The originating request.
     * @param array           $page    Page entry from App::page().
     * @return WP_REST_Response|WP_Error
     */
    private static function dispatch(string $hook, WP_REST_Request $request, array $page): WP_REST_Response|WP_Error
    {
        $values = self::sanitizeIncomingValues($request, $page);
        $result = apply_filters($hook, Unhandled::get(), $values, $request);

        if ($result instanceof Unhandled) {
            return new WP_Error(
                'wireframe_action_unhandled',
                sprintf('No handler is attached to "%s".', $hook),
                ['status' => 404]
            );
        }

        if ($result instanceof WP_Error) {
            return $result;
        }

        return new WP_REST_Response(self::normalizeResult($result));
    }

    /**
     * Confirm the requested `$actionId` is declared on the field.
     *
     * Two valid shapes:
     *  - `args.buttons[]` declared → `actionId` must match a declared `id`.
     *  - No `buttons` key (single-button sugar) → `actionId` must equal
     *    the literal string `run`. The sugar route is always
     *    `…/{fieldId}/run` so the hook reads as "the run action on this
     *    field" instead of repeating the field id.
     */
    private static function buttonExists(array $field, string $actionId): bool
    {
        $buttons = $field['args']['buttons'] ?? null;

        if (!is_array($buttons) || $buttons === []) {
            return $actionId === 'run';
        }

        foreach ($buttons as $button) {
            if (is_array($button) && ($button['id'] ?? '') === $actionId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scope the JSON body's `values` to the user's access, then run them
     * through the same sanitizer chain Save uses.
     *
     * @param WP_REST_Request $request The originating request.
     * @param array           $page    Page entry from App::page().
     * @return array<string, mixed> Field ID → sanitized value.
     */
    private static function sanitizeIncomingValues(WP_REST_Request $request, array $page): array
    {
        $body    = $request->get_json_params();
        $payload = is_array($body['values'] ?? null) ? $body['values'] : [];

        if ($payload === []) {
            return [];
        }

        $fields = ConfigLoader::flatFields($page['config']);
        $map    = SettingsController::accessMap($page);

        if ($map->isRbac()) {
            [$payload, $fields] = self::scopeToAccess($payload, $fields, $map, $page);
        }

        // Include stateless fields so transient inputs (e.g. `upload`) reach the
        // action handler — they're passed through here, never persisted.
        return Sanitizer::sanitize($payload, $fields, [], true);
    }

    /**
     * Restrict a payload and its field set to what the user could do in the UI.
     *
     * Fields the user can't view are dropped from both, so the handler never sees them (not even
     * as a default). View-only fields carry their stored value, or the default when unsaved.
     *
     * @param array<string, mixed> $payload Posted values.
     * @param array<string, array> $fields  Flat fields from ConfigLoader::flatFields().
     * @param ConfigAccessMap      $map     The user's access map for the page.
     * @param array                $page    Page entry from App::page().
     * @return array{0: array<string, mixed>, 1: array<string, array>} Scoped payload and fields.
     */
    private static function scopeToAccess(array $payload, array $fields, ConfigAccessMap $map, array $page): array
    {
        $viewable = $map->viewableFieldIds();
        $editable = array_intersect($viewable, SettingsController::editableTopLevelIds($map->editable));
        $readOnly = array_diff($viewable, $editable);

        $payload = array_intersect_key($payload, array_flip($editable))
            + array_intersect_key(Settings::allFor($page['option_key']), array_flip($readOnly));

        return [$payload, SettingsController::scopeFields($fields, $viewable)];
    }

    /**
     * Coerce arbitrary handler return values into a stable response shape.
     *
     *  - array  → as-is (client reads `status`, `message`, `html`)
     *  - bool   → `{success: bool}`
     *  - other  → `{success: true, result: <value>}`
     */
    private static function normalizeResult(mixed $result): array
    {
        if (is_array($result)) {
            return $result;
        }

        if (is_bool($result)) {
            return ['success' => $result];
        }

        return ['success' => true, 'result' => $result];
    }

    private static function findField(array $page, string $fieldId): array|WP_Error
    {
        $fields = ConfigLoader::flatFields($page['config']);
        $field  = $fields[$fieldId] ?? null;

        if ($field === null || ($field['type'] ?? '') !== 'action') {
            return new WP_Error(
                'wireframe_field_not_found',
                sprintf('Action field "%s" not found.', $fieldId),
                ['status' => 404]
            );
        }

        return $field;
    }

    private static function findHeaderAction(array $page, string $actionId): ?array
    {
        foreach (ConfigLoader::load($page['config'])['header_actions'] ?? [] as $action) {
            if ((string) ($action['id'] ?? '') === $actionId) {
                return $action;
            }
        }

        return null;
    }
}
