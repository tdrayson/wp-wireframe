<?php

declare(strict_types=1);

namespace Wireframe\Rest;

use Wireframe\App;
use Wireframe\Framework\ConfigLoader;
use Wireframe\Framework\Sanitizer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller for `action` fields.
 *
 * One generic route per page dispatches to the field's configured callback:
 *
 *   POST /{prefix}/v1/action/{pageId}/{fieldId}   → args.callback($values, $request)
 *
 * The callback receives the page's in-flight form values, sanitized through
 * the same per-type handlers the save flow uses — so it can read sibling
 * field selections (e.g. a "target user" dropdown) without having to
 * re-sanitize them itself. Callbacks should still treat the values as
 * untrusted input (use `$wpdb->prepare()`, cap checks, etc.) since the
 * sanitizers only enforce format, not semantics.
 *
 * Security boundaries:
 * - `permission_callback` requires the page's `capability` (default
 *   `manage_options`) — same gate as Save/Reset.
 * - Field lookup goes through `ConfigLoader::flatFields()` and rejects any
 *   request whose target isn't actually an `action` field, so the route
 *   can't be abused to invoke arbitrary field-arg callables.
 * - `Sanitizer::sanitize()` only iterates configured fields, so unknown
 *   keys in the payload are silently dropped (no mass assignment).
 */
final class ActionController
{
    /**
     * Register the action route for every page across every booted plugin.
     */
    public static function register(): void
    {
        foreach (App::pages() as $internalId => $page) {
            $namespace = App::restNamespace($page['prefix']);
            $route     = '/action/' . $page['page_id'] . '/(?P<field>[a-zA-Z0-9_.-]+)';

            register_rest_route($namespace, $route, [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => fn(WP_REST_Request $r) => self::run($r, $internalId),
                    'permission_callback' => fn() => SettingsController::checkPermission($internalId),
                ],
            ]);
        }
    }

    /**
     * Dispatch the request to the field's configured callback.
     */
    private static function run(WP_REST_Request $request, string $internalId): WP_REST_Response|WP_Error
    {
        $page    = App::page($internalId);
        $fieldId = (string) $request['field'];

        $field = self::findField($page, $fieldId);

        if ($field instanceof WP_Error) {
            return $field;
        }

        $callback = $field['args']['callback'] ?? null;

        if (!is_callable($callback)) {
            return new WP_Error(
                'wireframe_invalid_callback',
                sprintf('Action "%s" has no callable callback.', $fieldId),
                ['status' => 500]
            );
        }

        $values = self::sanitizeIncomingValues($request, $page);
        $result = call_user_func($callback, $values, $request);

        if ($result instanceof WP_Error) {
            return $result;
        }

        return new WP_REST_Response(self::normalizeResult($result));
    }

    /**
     * Run the JSON body's `values` through the same sanitizer chain as Save.
     *
     * Unknown keys in the payload are dropped (the Sanitizer iterates the
     * configured fields, not the payload), so the callback never sees
     * anything the consumer hasn't declared as a field.
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
     * Coerce arbitrary callback return values into a stable response shape.
     *
     * Callbacks may return:
     *   - bool             → `{success: bool}`
     *   - array            → as-is (clients read `status`, `message`, `html`)
     *   - anything else    → wrapped as `{success: true, result: <value>}`
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

    /**
     * Look up an `action` field in the page's flat field map.
     */
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
