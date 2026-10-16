<?php

declare(strict_types=1);

namespace Wireframe\PostFields;

use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;
use Wireframe\Framework\Access\AccessResolver;
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
     * Register one post type's top-level fields as post meta; safe to call again.
     *
     * @param string $postType Post type slug.
     */
    public static function registerFor(string $postType): void
    {
        foreach (self::stored(FieldGroups::flatFields($postType)) as $key => $field) {
            register_post_meta($postType, $key, self::metaArgs($postType, (string) $key, $field));
        }

        add_filter("rest_prepare_{$postType}", [self::class, 'filterResponse'], 10, 3);
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
            $applies = Location::applies($group, $postId, $user->exists() ? $user : null);

            foreach (self::stored(ConfigLoader::flatFields($group['config'])) as $key => $field) {
                if (!$applies || !self::readable($postType, $group, (string) $key, $field, $context, $user)) {
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
     * Whether a user may read a field in the given REST context.
     */
    private static function readable(string $postType, array $group, string $key, array $field, string $context, WP_User $user): bool
    {
        if ($context !== 'edit' && empty($field['public'])) {
            return false;
        }

        return self::allowed('view', $postType, $group, $key, $user);
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

        return self::allowed('edit', $postType, $group, $key, $user);
    }

    /**
     * Check a field's `access`, inherited from its section and tab as on settings pages.
     *
     * Fields with no rule anywhere above them defer to post capabilities, so public fields stay readable logged out.
     */
    private static function allowed(string $verb, string $postType, array $group, string $key, WP_User $user): bool
    {
        $config = ConfigLoader::load($group['config']);

        if (!in_array($key, self::gatedFieldIds($config), true)) {
            return true;
        }

        $resolver = new AccessResolver(get_post_type_object($postType)?->cap->edit_posts ?? 'edit_posts');
        $map      = $resolver->resolveForConfig($config, $user);

        return $verb === 'edit' ? $map->canEdit($key) : in_array($key, $map->viewableFieldIds(), true);
    }

    /**
     * Get the top-level field IDs with an `access` rule on themselves, their section or their tab.
     *
     * @return list<string>
     */
    private static function gatedFieldIds(array $config): array
    {
        $ids = [];

        foreach ($config['tabs'] ?? [] as $tab) {
            foreach ($tab['sections'] ?? [] as $section) {
                $inherited = isset($tab['access']) || isset($section['access']);

                foreach ($section['fields'] ?? [] as $field) {
                    if ($inherited || isset($field['access'])) {
                        $ids[] = (string) ($field['id'] ?? '');
                    }
                }
            }
        }

        return $ids;
    }
}
