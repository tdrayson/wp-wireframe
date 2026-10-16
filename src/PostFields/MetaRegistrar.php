<?php

declare(strict_types=1);

namespace Wireframe\PostFields;

use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;
use Wireframe\Framework\Access\AccessResolver;
use Wireframe\Framework\Access\ConfigAccessMap;
use Wireframe\Framework\ConfigLoader;
use Wireframe\Framework\Fields\FieldInterface;
use Wireframe\Framework\Fields\FieldRegistry;

/**
 * Registers post meta for attached field groups, with REST schemas, sanitising, auth and read privacy.
 */
final class MetaRegistrar
{
    private const ID_LIST_SCHEMA = ['type' => 'array', 'items' => ['type' => 'integer']];

    /**
     * Register the top-level fields of every post type with groups as post meta.
     */
    public static function register(): void
    {
        foreach (array_keys(FieldGroups::all()) as $postType) {
            self::registerFor($postType);
        }
    }

    /**
     * Register one post type's top-level fields as post meta, with REST privacy and save validation; safe to call again.
     *
     * @param string $postType Post type slug.
     */
    public static function registerFor(string $postType): void
    {
        foreach (self::stored(FieldGroups::flatFields($postType)) as $key => $field) {
            register_post_meta($postType, $key, self::metaArgs($postType, (string) $key, $field));
        }

        add_filter("rest_prepare_{$postType}", [self::class, 'filterResponse'], 10, 3);
        add_filter("rest_pre_insert_{$postType}", [SaveValidator::class, 'check'], 10, 2);
        add_filter('rest_prepare_revision', [self::class, 'filterResponse'], 10, 3);
        add_filter('rest_prepare_autosave', [self::class, 'filterResponse'], 10, 3);
    }

    /**
     * Get the REST schema of one field's stored value.
     *
     * @param array $field Field config.
     * @return array JSON Schema.
     */
    public static function schemaFor(array $field): array
    {
        return match (StorageKind::for($field)) {
            StorageKind::MULTI    => ['type' => 'array', 'items' => ['type' => 'string']],
            StorageKind::LIST     => self::ID_LIST_SCHEMA,
            StorageKind::REPEATER => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => self::rowSchema($field)]],
            default               => ['type' => self::scalarType($field)],
        };
    }

    /**
     * Remove meta the current user may not read, or whose group doesn't apply, from a post, revision or autosave response.
     *
     * @param WP_REST_Response $response Prepared response.
     * @param WP_Post          $post     Post, revision or autosave being prepared.
     * @param WP_REST_Request  $request  Current request.
     * @return WP_REST_Response
     */
    public static function filterResponse(WP_REST_Response $response, WP_Post $post, WP_REST_Request $request): WP_REST_Response
    {
        $data = $response->get_data();

        if (!is_array($data['meta'] ?? null)) {
            return $response;
        }

        $postId   = $post->post_type === 'revision' ? (int) $post->post_parent : $post->ID;
        $postType = (string) get_post_type($postId);
        $context  = (string) ($request['context'] ?? 'view');
        $user     = wp_get_current_user();

        foreach (FieldGroups::groupsFor($postType) as $group) {
            $applies  = Location::applies($group, $postId, $user->exists() ? $user : null);
            $viewable = $applies ? self::accessMap($postType, $group, $user)->viewableFieldIds() : [];

            foreach (self::stored(ConfigLoader::flatFields($group['config'])) as $key => $field) {
                if (!self::readable((string) $key, $field, $context, $viewable)) {
                    unset($data['meta'][$key]);
                }
            }
        }

        $response->set_data($data);

        return $response;
    }

    /**
     * Build the `register_post_meta` args for one field.
     */
    private static function metaArgs(string $postType, string $key, array $field): array
    {
        $kind        = StorageKind::for($field);
        $single      = $kind !== StorageKind::MULTI;
        $schema      = self::schemaFor($field);
        $valueSchema = $single ? $schema : $schema['items'];

        $args = [
            'single'            => $single,
            'type'              => $valueSchema['type'],
            'show_in_rest'      => ['schema' => $valueSchema + ['context' => !empty($field['public']) ? ['view', 'edit'] : ['edit']]],
            'sanitize_callback' => self::sanitizer($kind, $field, $schema),
            'auth_callback'     => static fn (bool $allowed, string $metaKey, int $postId, int $userId): bool
                => self::canWrite($postType, $key, $postId, $userId),
            'revisions_enabled' => post_type_supports($postType, 'revisions'),
        ];

        if ($single && isset($field['default'])) {
            $args['default'] = $field['default'];
        }

        return $args;
    }

    /**
     * Build the sanitize callback for one field, adapting the field class to its storage kind.
     */
    private static function sanitizer(string $kind, array $field, array $schema): callable
    {
        $type    = (string) ($field['type'] ?? 'text');
        $handler = FieldRegistry::instance()->get($type);
        $args    = $field['args'] ?? [];

        if ($kind === StorageKind::MULTI) {
            return static fn (mixed $value): mixed => self::sanitizeOption($handler, $value, $args);
        }

        if ($kind === StorageKind::SCALAR && $type === 'file') {
            return static fn (mixed $value): int => self::sanitizeSingleFile($handler, $value, $args);
        }

        if ($kind === StorageKind::REPEATER) {
            $columns = $schema['items']['properties'];

            return static fn (mixed $value): array => self::sanitizeRows($handler, $value, $args, $columns);
        }

        return static fn (mixed $value): mixed => $handler::sanitize($value, $args);
    }

    /**
     * Sanitise one value of a multi-value field against its options; WP stores each value as its own row.
     *
     * @param class-string<FieldInterface> $handler Field class.
     */
    private static function sanitizeOption(string $handler, mixed $value, array $args): mixed
    {
        if (!is_scalar($value)) {
            return '';
        }

        $clean = $handler::sanitize([(string) $value], $args);

        return $clean[0] ?? '';
    }

    /**
     * Sanitise a single file to one attachment ID, or 0.
     *
     * @param class-string<FieldInterface> $handler Field class.
     */
    private static function sanitizeSingleFile(string $handler, mixed $value, array $args): int
    {
        $ids = array_values((array) $handler::sanitize($value, $args));

        return (int) ($ids[0] ?? 0);
    }

    /**
     * Sanitise repeater rows, keeping only the subfields that store a value.
     *
     * @param class-string<FieldInterface> $handler Field class.
     */
    private static function sanitizeRows(string $handler, mixed $value, array $args, array $columns): array
    {
        $rows = (array) $handler::sanitize($value, $args);

        return array_map(static fn (array $row): array => array_intersect_key($row, $columns), $rows);
    }

    /**
     * Get the schema of each stored subfield of a repeater row.
     */
    private static function rowSchema(array $repeater): array
    {
        $properties = [];

        foreach ($repeater['args']['subfields'] ?? [] as $subfield) {
            $id = (string) ($subfield['id'] ?? '');

            if ($id === '' || StorageKind::for($subfield) === StorageKind::NONE) {
                continue;
            }

            // Inside rows the file class's array is stored as-is; only top-level single files are unwrapped.
            $properties[$id] = ($subfield['type'] ?? '') === 'file' ? self::ID_LIST_SCHEMA : self::schemaFor($subfield);
        }

        return $properties;
    }

    /**
     * Get the JSON Schema type of a single-value field.
     */
    private static function scalarType(array $field): string
    {
        return match ($field['type'] ?? 'text') {
            'toggle', 'checkbox' => 'boolean',
            'number', 'range'    => 'number',
            'file'               => 'integer',
            default              => 'string',
        };
    }

    /**
     * Keep the top-level fields of a flat field map that store a value, keyed by meta key.
     *
     * @return array<string, array>
     */
    private static function stored(array $flatFields): array
    {
        return array_filter(
            $flatFields,
            static fn (array $field, int|string $key): bool => !str_contains((string) $key, '.') && StorageKind::for($field) !== StorageKind::NONE,
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Whether a field may be read in the given REST context, given the field ids the user can view.
     */
    private static function readable(string $key, array $field, string $context, array $viewable): bool
    {
        if ($context !== 'edit' && empty($field['public'])) {
            return false;
        }

        return in_array($key, $viewable, true);
    }

    /**
     * Whether a user may write a field on a post: they can edit the post, its group applies, and its access allows it.
     */
    private static function canWrite(string $postType, string $key, int $postId, int $userId): bool
    {
        $user  = get_userdata($userId);
        $group = FieldGroups::groupOfField($postType, $key);

        if (!$user instanceof WP_User || $group === null || !user_can($user, 'edit_post', $postId)) {
            return false;
        }

        if (!Location::applies($group, $postId, $user)) {
            return false;
        }

        return self::accessMap($postType, $group, $user)->canEdit($key);
    }

    /**
     * Get the meta keys a user may write on a post, each mapped to its owning group's id; the bulk form of the REST write gate.
     *
     * @param string  $postType Post type slug.
     * @param int     $postId   Post being saved.
     * @param WP_User $user     User saving.
     * @return array<string, string> Owning group id by meta key.
     */
    public static function writableKeys(string $postType, int $postId, WP_User $user): array
    {
        if (!$user->exists() || !user_can($user, 'edit_post', $postId)) {
            return [];
        }

        $keys = [];

        foreach (FieldGroups::groupsFor($postType) as $group) {
            if (!Location::applies($group, $postId, $user)) {
                continue;
            }

            $map = self::accessMap($postType, $group, $user);

            foreach (array_keys(self::stored(ConfigLoader::flatFields($group['config']))) as $key) {
                if ($map->canEdit((string) $key)) {
                    $keys[(string) $key] = $group['id'];
                }
            }
        }

        return $keys;
    }

    /**
     * Resolve a group's field access for a user, inherited from sections and tabs as on settings pages.
     *
     * Fields with no rule anywhere above them defer to post capabilities, so public fields stay readable logged out;
     * they still pass through the `wp-wireframe/access/resolve` filter.
     *
     * @param string  $postType Post type slug.
     * @param array   $group    Normalised group.
     * @param WP_User $user     User being checked; one that doesn't exist counts as logged out.
     * @return ConfigAccessMap
     */
    public static function accessMap(string $postType, array $group, WP_User $user): ConfigAccessMap
    {
        $config = ConfigLoader::load($group['config']);

        return self::resolver($postType)->resolveForConfig($config, $user->exists() ? $user : null);
    }

    /**
     * Get the access resolver used for a post type's field groups.
     *
     * @param string $postType Post type slug.
     * @return AccessResolver
     */
    public static function resolver(string $postType): AccessResolver
    {
        return new AccessResolver(get_post_type_object($postType)?->cap->edit_posts ?? 'edit_posts', deferUnruled: true);
    }
}
