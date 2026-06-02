<?php

declare(strict_types=1);

namespace Wireframe\Rest;

use Wireframe\App;
use Wireframe\Framework\ConfigLoader;
use Wireframe\Framework\Sanitizer;
use Wireframe\Framework\Unhandled;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller for `action` fields.
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
 * The handler receives the page's in-flight form values, sanitized through
 * the same per-type handlers the save flow uses — so it can read sibling
 * field selections without having to re-sanitize them.
 *
 * Security boundaries:
 *  - `permission_callback` reuses `SettingsController::checkPermission()`,
 *    so the route is gated by the page's `capability` (default
 *    `manage_options`) and the standard WP REST nonce.
 *  - Field lookup goes through `ConfigLoader::flatFields()` and 404s unless
 *    the target is actually an `action` field.
 *  - The requested `actionId` must be declared in `args.buttons[]` (or be
 *    the sugar single-button case where it equals the field id).
 *  - `Sanitizer::sanitize()` only iterates configured fields, so unknown
 *    keys in the payload are dropped (no mass assignment).
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

        $values = self::sanitizeIncomingValues($request, $page);
        $hook   = App::hookName(
            $page['prefix'],
            'action/' . $page['page_id'] . '/' . $fieldId . '/' . $actionId
        );

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
     * Run the JSON body's `values` through the same sanitizer chain Save uses.
     */
    private static function sanitizeIncomingValues(WP_REST_Request $request, array $page): array
    {
        $body    = $request->get_json_params();
        $payload = is_array($body['values'] ?? null) ? $body['values'] : [];

        if ($payload === []) {
            return [];
        }

        $fields = ConfigLoader::flatFields($page['config']);

        return Sanitizer::sanitize($payload, $fields);
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
}
