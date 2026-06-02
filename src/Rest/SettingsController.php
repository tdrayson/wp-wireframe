<?php

declare(strict_types=1);

namespace Wireframe\Rest;

use Wireframe\App;
use Wireframe\Framework\Access\AccessResolver;
use Wireframe\Framework\Access\ConfigAccessMap;
use Wireframe\Framework\Conditions;
use Wireframe\Framework\ConfigLoader;
use Wireframe\Framework\Fields\FieldRegistry;
use Wireframe\Framework\Sanitizer;
use Wireframe\Framework\Validator;
use Wireframe\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST API controller for reading, saving, and resetting plugin settings.
 *
 * Multi-tenant: one route set is registered per page, under the owning
 * plugin's prefix namespace:
 *   GET/POST/DELETE /{prefix}/v1/settings/{pageId}
 *
 * Save and reset both honor the per-user AccessMap so partial-access
 * users cannot overwrite or wipe fields they don't control.
 */
final class SettingsController
{
    /**
     * Register REST routes for every page across every booted plugin.
     */
    public static function register(): void
    {
        foreach (App::pages() as $internalId => $page) {
            $namespace = App::restNamespace($page['prefix']);
            $route     = '/settings/' . $page['page_id'];

            register_rest_route($namespace, $route, [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => fn(WP_REST_Request $r) => self::getSettings($internalId),
                    'permission_callback' => fn() => self::checkPermission($internalId, 'view'),
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => fn(WP_REST_Request $r) => self::saveSettings($r, $internalId),
                    'permission_callback' => fn() => self::checkPermission($internalId, 'edit'),
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => fn(WP_REST_Request $r) => self::resetSettings($internalId),
                    'permission_callback' => fn() => self::checkPermission($internalId, 'reset'),
                ],
            ]);
        }
    }

    /**
     * Permission gate for a page's REST endpoints.
     *
     * Legacy mode: identical to the previous behavior — the user must satisfy
     * the page's declared capability (defaulting to `manage_options`).
     *
     * RBAC mode: the user needs `read` plus at least one editable field for
     * write/reset, or any viewable element for read. Reset additionally
     * passes through the `wp-wireframe/access/can_reset` filter.
     *
     * @param string $verb 'view' for GET, 'edit' for POST, 'reset' for DELETE.
     */
    public static function checkPermission(string $internalId, string $verb = 'view'): bool
    {
        $page = App::page($internalId);

        if ($page === null) {
            return false;
        }

        $config = ConfigLoader::load($page['config']);
        $mode   = AccessResolver::pageMode($config);

        if ($mode === 'legacy') {
            return current_user_can($page['capability']);
        }

        if (!current_user_can('read')) {
            return false;
        }

        $map = self::accessMap($page);

        return match ($verb) {
            'view'  => $map->hasAnyAccess(),
            'edit'  => !empty($map->editable),
            'reset' => $map->canReset && !empty($map->editable),
            default => false,
        };
    }

    /**
     * Return config and resolved values for the page.
     *
     * The config is filtered through the per-user access map so the client
     * never sees fields it isn't allowed to view, matching what the admin
     * page's initial localized data exposes.
     */
    private static function getSettings(string $internalId): WP_REST_Response
    {
        $page     = App::page($internalId);
        $config   = ConfigLoader::load($page['config']);
        $map      = self::accessMap($page);
        $resolver = new AccessResolver($page['capability']);
        $filtered = $resolver->filterConfig($config, $map);

        return new WP_REST_Response([
            'config'   => apply_filters('wp-wireframe/config/for_user', $filtered, $page['page_id'], $map),
            'values'   => Settings::resolvedFor($page['option_key'], $page['config']),
            'canSave'  => !empty($map->editable),
            'canReset' => $map->canReset && !empty($map->editable),
        ]);
    }

    /**
     * Validate, sanitize, and persist submitted settings for the page.
     *
     * The payload is intersected with the user's editable field list before
     * anything else happens — any field the user can't edit is dropped from
     * the request. Sanitized values are then *merged* into existing saved
     * state instead of overwriting it, so partial-access users can't wipe
     * fields they can't see by saving their slice.
     */
    private static function saveSettings(WP_REST_Request $request, string $internalId): WP_REST_Response|WP_Error
    {
        $page    = App::page($internalId);
        $payload = $request->get_json_params();

        if (!is_array($payload)) {
            return new WP_Error(
                'invalid_payload',
                __('Invalid JSON payload.', App::textDomain($page['prefix'])),
                ['status' => 400]
            );
        }

        $optionKey  = $page['option_key'];
        $configSlug = $page['config'];
        $fields     = ConfigLoader::flatFields($configSlug);
        $map        = self::accessMap($page);

        // Drop any fields the user can't edit. Repeater subfields share the
        // parent field's editability (see AccessResolver::resolveForConfig).
        $editableTopLevel = self::editableTopLevelIds($map->editable);

        /**
         * Filter the final list of writable field IDs for this user/page.
         *
         * Useful for read-but-don't-write scenarios (e.g. a developer wants
         * to expose a field in the UI for context but block any persistence
         * regardless of role).
         *
         * @param list<string>    $fieldIds Top-level field IDs the user may write.
         * @param string          $pageId   Page identifier.
         * @param ConfigAccessMap $map      The full access map.
         */
        $editableTopLevel = (array) apply_filters(
            'wp-wireframe/save/editable_fields',
            $editableTopLevel,
            $page['page_id'],
            $map,
        );

        $payload = array_intersect_key($payload, array_flip($editableTopLevel));

        // Scope the validator/sanitizer's view of the field set to what the
        // user can edit. Two reasons: (1) Validator would otherwise flag
        // required fields the user can't see as missing, (2) Sanitizer would
        // fill in defaults for non-editable fields and we'd merge them over
        // legitimate saved values. Include repeater subfields (`parent.sub`)
        // when their parent is editable so subfield validation still runs.
        $editableFields = self::scopeFieldsToEditable($fields, $editableTopLevel);

        $savedValues   = Settings::allFor($optionKey);
        $mergedValues  = array_merge($savedValues, $payload);
        $visibilityMap = Conditions::visibilityMap($editableFields, $mergedValues);

        $validationResult = Validator::validate($payload, $editableFields, $visibilityMap);

        if (!empty($validationResult['errors'])) {
            return new WP_Error(
                'validation_failed',
                __('Validation failed.', App::textDomain($page['prefix'])),
                ['status' => 400, 'errors' => $validationResult['errors']]
            );
        }

        $cleanValues = Sanitizer::sanitize($payload, $editableFields, $visibilityMap);

        /**
         * Filter the sanitized payload right before it's merged into saved state.
         *
         * Use this to coerce values, inject derived fields, or veto specific
         * keys based on runtime conditions the static config can't express.
         *
         * @param array  $cleanValues Field ID → sanitized value.
         * @param string $pageId      Page identifier.
         * @param array  $payload     The pre-sanitization payload (already intersected with editable IDs).
         */
        $cleanValues = (array) apply_filters(
            'wp-wireframe/save/payload',
            $cleanValues,
            $page['page_id'],
            $payload,
        );

        // Merge: preserve every saved field the user didn't (or couldn't) touch.
        // This replaces the old preserveHiddenFieldValues() pass — both
        // condition-hidden fields and access-restricted fields are kept
        // intact for free because we never wipe them out in the first place.
        $finalValues = array_merge($savedValues, $cleanValues);

        Settings::updateFor($optionKey, $finalValues);

        do_action(App::hookName($page['prefix'], 'settings_saved'), $cleanValues, $page['page_id']);

        return new WP_REST_Response([
            'success' => true,
            'values'  => Settings::resolvedFor($optionKey, $configSlug),
        ]);
    }

    /**
     * Reset settings for the page.
     *
     * Legacy mode (admin-only, no `access` keys): deletes the entire option
     * just like before — every field reverts to its declared default.
     *
     * RBAC mode: only the user's editable fields are reset. Their values
     * are removed from the saved option (so the field falls back to its
     * declared default on the next read). Fields the user cannot edit are
     * preserved untouched, mirroring the save flow's merge semantics.
     */
    private static function resetSettings(string $internalId): WP_REST_Response
    {
        $page = App::page($internalId);
        $map  = self::accessMap($page);

        if ($map->isLegacy()) {
            Settings::resetFor($page['option_key']);
        } else {
            $saved = Settings::allFor($page['option_key']);
            $editableTopLevel = self::editableTopLevelIds($map->editable);
            $remaining = array_diff_key($saved, array_flip($editableTopLevel));

            if (empty($remaining)) {
                Settings::resetFor($page['option_key']);
            } else {
                Settings::updateFor($page['option_key'], $remaining);
            }
        }

        do_action(App::hookName($page['prefix'], 'settings_reset'), $page['page_id']);

        return new WP_REST_Response([
            'success' => true,
            'values'  => Settings::resolvedFor($page['option_key'], $page['config']),
        ]);
    }

    /**
     * Build (and cache per request) the AccessMap for the given page.
     */
    private static function accessMap(array $page): ConfigAccessMap
    {
        static $cache = [];
        $key = $page['prefix'] . '__' . $page['page_id'];

        if (!isset($cache[$key])) {
            $config = ConfigLoader::load($page['config']);
            $resolver = new AccessResolver($page['capability']);
            $cache[$key] = $resolver->resolveForConfig($config);
        }

        return $cache[$key];
    }

    /**
     * Strip repeater subfield ID's (`parent.sub`) from the editable list so
     * the result aligns with payload keys, which are always top-level.
     *
     * @param list<string> $editable Full editable list including subfield IDs.
     * @return list<string>
     */
    private static function editableTopLevelIds(array $editable): array
    {
        $top = [];

        foreach ($editable as $fieldId) {
            if (!str_contains($fieldId, '.')) {
                $top[] = $fieldId;
            }
        }

        return array_values(array_unique($top));
    }

    /**
     * Slice a flat-fields map down to a user's editable scope.
     *
     * Keeps the requested top-level fields and any of their repeater
     * subfields (stored as `parent.sub` keys). Used to constrain what
     * Validator and Sanitizer act on so non-editable fields don't trip
     * validation or get overwritten with defaults.
     *
     * @param array<string, array> $fields            Flat fields from ConfigLoader::flatFields().
     * @param list<string>         $editableTopLevel  Top-level field IDs the user may write.
     * @return array<string, array>
     */
    private static function scopeFieldsToEditable(array $fields, array $editableTopLevel): array
    {
        $allowed = array_flip($editableTopLevel);
        $scoped  = [];

        foreach ($fields as $fieldId => $fieldConfig) {
            if (isset($allowed[$fieldId])) {
                $scoped[$fieldId] = $fieldConfig;
                continue;
            }

            // Repeater subfields keep their parent's editability.
            if (str_contains($fieldId, '.')) {
                $parentId = explode('.', $fieldId, 2)[0];
                if (isset($allowed[$parentId])) {
                    $scoped[$fieldId] = $fieldConfig;
                }
            }
        }

        return $scoped;
    }
}
