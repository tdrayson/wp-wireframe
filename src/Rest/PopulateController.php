<?php

declare(strict_types=1);

namespace Wireframe\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Wireframe\App;
use Wireframe\Framework\ConfigLoader;
use Wireframe\Framework\Populate;
use Wireframe\PostFields\FieldGroups;
use Wireframe\PostFields\MetaRegistrar;

/**
 * Searches the options of `fetch` select fields: `GET {prefix}/v1/populate?context=…&field=…&search=…`.
 *
 * `context` is `settings:{pageId}` or `post:{postType}`; `field` is a field id, or `{repeater}.{subfield}`.
 */
final class PopulateController
{
    /**
     * Register the search route under every prefix with a settings page or a field group.
     */
    public static function register(): void
    {
        foreach (self::prefixes() as $prefix) {
            register_rest_route(App::restNamespace($prefix), '/populate', [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => static fn (WP_REST_Request $request): WP_REST_Response => self::search($prefix, $request),
                'permission_callback' => static fn (WP_REST_Request $request): bool => self::field($prefix, $request) !== null,
                'args'                => [
                    'context' => ['type' => 'string', 'required' => true],
                    'field'   => ['type' => 'string', 'required' => true],
                    'search'  => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ]);
        }
    }

    /**
     * Return the field's options matching the search, at most 20.
     */
    private static function search(string $prefix, WP_REST_Request $request): WP_REST_Response
    {
        $field = (array) self::field($prefix, $request);

        return new WP_REST_Response(Populate::search($field, (string) $request['search']));
    }

    /**
     * Get the `fetch` field a request names, or null when it doesn't exist or the user may not search it.
     */
    private static function field(string $prefix, WP_REST_Request $request): ?array
    {
        [$kind, $target] = array_pad(explode(':', (string) $request['context'], 2), 2, '');
        $fieldId         = (string) $request['field'];

        $field = match ($kind) {
            'settings' => self::settingsField($prefix, $target, $fieldId),
            'post'     => self::postField($prefix, $target, $fieldId),
            default    => null,
        };

        return $field !== null && Populate::fetches($field) ? $field : null;
    }

    /**
     * Get a settings page's field, when the user may view the page and the field.
     */
    private static function settingsField(string $prefix, string $pageId, string $fieldId): ?array
    {
        foreach (App::pages() as $internalId => $page) {
            if ($page['prefix'] !== $prefix || (string) $page['page_id'] !== $pageId) {
                continue;
            }

            if (!SettingsController::checkPermission($internalId, 'view')) {
                return null;
            }

            $viewable = SettingsController::accessMap($page)->viewableFieldIds();

            if (!in_array(self::topId($fieldId), $viewable, true)) {
                return null;
            }

            return ConfigLoader::flatFields($page['config'])[$fieldId] ?? null;
        }

        return null;
    }

    /**
     * Get a post type's field, when the user may edit posts of the type and view the field.
     */
    private static function postField(string $prefix, string $postType, string $fieldId): ?array
    {
        $type  = get_post_type_object($postType);
        $group = FieldGroups::groupOfField($postType, self::topId($fieldId));

        if ($type === null || $group === null || $group['prefix'] !== $prefix || !current_user_can($type->cap->edit_posts)) {
            return null;
        }

        $viewable = MetaRegistrar::accessMap($postType, $group, wp_get_current_user())->viewableFieldIds();

        if (!in_array(self::topId($fieldId), $viewable, true)) {
            return null;
        }

        return ConfigLoader::flatFields($group['config'])[$fieldId] ?? null;
    }

    /**
     * Get the top-level id of a field id or `{repeater}.{subfield}` path.
     */
    private static function topId(string $fieldId): string
    {
        return explode('.', $fieldId, 2)[0];
    }

    /**
     * Get every prefix that owns a settings page or a field group.
     *
     * @return list<string>
     */
    private static function prefixes(): array
    {
        $prefixes = array_column(App::pages(), 'prefix');

        foreach (FieldGroups::all() as $groups) {
            $prefixes = [...$prefixes, ...array_column($groups, 'prefix')];
        }

        return array_values(array_unique($prefixes));
    }
}
