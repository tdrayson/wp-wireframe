<?php

declare(strict_types=1);

namespace Wireframe\PostFields;

use WP_Post;
use WP_REST_Post_Meta_Fields;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;
use Wireframe\Framework\Access\AccessResolver;
use Wireframe\Framework\Access\ConfigAccessMap;
use Wireframe\Framework\ConfigLoader;
use Wireframe\Framework\Fields\FieldInterface;
use Wireframe\Framework\Fields\FieldRegistry;
use Wireframe\Framework\Populate;

/**
 * Registers post meta for attached field groups, with REST schemas, sanitising, auth and read privacy.
 */
final class MetaRegistrar
{
    private const ID_LIST_SCHEMA = ['type' => 'array', 'items' => ['type' => 'integer']];

    /** Post whose meta is being written, so sanitising keeps the populated values it already stores; 0 for none. */
    private static int $savingPostId = 0;

    /** @var array<string, mixed> Stored values by meta key, read before a write that deletes rows first. */
    private static array $savingStored = [];

    /** @var array<string, ConfigAccessMap> Access maps by site, post type, group and user, kept while REST callbacks run. */
    private static array $maps = [];

    private static int $restDepth = 0;

    /** @var list<array{0: int, 1: array, 2: int}> Save scopes REST saves replaced: post ID, stored values, and the request depth they began at. */
    private static array $saveScopes = [];

    /** @var array<string, true>|null Registered field keys of every grouped post type, for protect(). */
    private static ?array $protectedKeys = null;

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
        self::flushKeys();

        foreach (self::stored(FieldGroups::flatFields($postType)) as $key => $field) {
            register_post_meta($postType, $key, self::metaArgs($postType, (string) $key, $field));
        }

        add_filter("rest_prepare_{$postType}", [self::class, 'filterResponse'], 10, 3);
        add_filter("rest_pre_insert_{$postType}", [SaveValidator::class, 'check'], 10, 2);
        add_action("rest_insert_{$postType}", [self::class, 'beginSave']);
        add_action("rest_after_insert_{$postType}", [self::class, 'endSave'], 10, 0);
        add_action("rest_after_insert_{$postType}", [Aliases::class, 'afterRestSave'], 10, 2);
        add_filter('rest_prepare_revision', [self::class, 'filterResponse'], 10, 3);
        add_filter('rest_prepare_autosave', [self::class, 'filterResponse'], 10, 3);
    }

    /**
     * Run a write of a post's meta with its stored values in scope for sanitising.
     *
     * @param int                  $postId Post ID.
     * @param callable             $write  Callback doing the write.
     * @param array<string, mixed> $stored Stored values by meta key, read before the write.
     * @return mixed The callback's result.
     */
    public static function whileSaving(int $postId, callable $write, array $stored = []): mixed
    {
        $previous = [self::$savingPostId, self::$savingStored];

        [self::$savingPostId, self::$savingStored] = [$postId, $stored];

        try {
            return $write();
        } finally {
            [self::$savingPostId, self::$savingStored] = $previous;
        }
    }

    /**
     * Bring a REST save's post into scope for sanitising the meta core writes after `rest_insert_{type}`.
     *
     * @param WP_Post $post Saved post.
     */
    public static function beginSave(WP_Post $post): void
    {
        self::$saveScopes[] = [self::$savingPostId, self::$savingStored, self::$restDepth];

        [self::$savingPostId, self::$savingStored] = [$post->ID, []];
    }

    /**
     * End a REST save's scope after its insert, restoring the one it began inside; endRequest() ends one whose insert failed.
     */
    public static function endSave(): void
    {
        $previous = array_pop(self::$saveScopes);

        [self::$savingPostId, self::$savingStored] = $previous === null ? [0, []] : [$previous[0], $previous[1]];
    }

    /**
     * Filter `is_protected_meta`: hide registered field keys and their previous ids from the Custom Fields box and panel, which bypass field access.
     *
     * @param bool        $protected Whether the key is protected so far.
     * @param string      $metaKey   Meta key.
     * @param string|null $metaType  Object type, `post` for post meta.
     * @return bool
     */
    public static function protect(bool $protected, string $metaKey, ?string $metaType = null): bool
    {
        if ($protected || $metaType !== 'post') {
            return $protected;
        }

        if (Aliases::isPrevious($metaKey)) {
            return true;
        }

        if (self::$protectedKeys === null) {
            self::$protectedKeys = [];

            foreach (array_keys(FieldGroups::all()) as $postType) {
                self::$protectedKeys += array_fill_keys(array_keys(self::stored(FieldGroups::flatFields($postType))), true);
            }
        }

        return isset(self::$protectedKeys[$metaKey]);
    }

    /**
     * Forget the protected key set, for when groups change.
     */
    public static function flushKeys(): void
    {
        self::$protectedKeys = null;
    }

    /**
     * Filter `rest_request_before_callbacks`: keep access maps for the request's callbacks, and track saves begun in it.
     *
     * @param mixed $response Response so far, returned as-is.
     * @return mixed
     */
    public static function beginRequest(mixed $response): mixed
    {
        self::$restDepth++;

        return $response;
    }

    /**
     * Filter `rest_request_after_callbacks`: end saves the request began and never closed; forget access maps once the outermost request is done.
     *
     * @param mixed $response Response, returned as-is.
     * @return mixed
     */
    public static function endRequest(mixed $response): mixed
    {
        while (self::$saveScopes !== [] && end(self::$saveScopes)[2] >= self::$restDepth && self::$restDepth > 0) {
            self::endSave();
        }

        self::$restDepth = max(0, self::$restDepth - 1);

        if (self::$restDepth === 0) {
            self::$maps = [];
        }

        return $response;
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
            StorageKind::MULTI    => ['type' => 'array', 'items' => ['type' => Populate::valueType($field['args'] ?? [])]],
            StorageKind::LIST     => self::ID_LIST_SCHEMA,
            StorageKind::REPEATER => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => self::rowSchema($field)]],
            default               => ['type' => self::scalarType($field)],
        };
    }

    /**
     * Remove meta the current user may not read, or whose group doesn't apply, from a post, revision or autosave response.
     *
     * Edit reads by users who can edit the post relax live rules, so a group that starts applying in the editor has its values.
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
        $current  = $user->exists() ? $user : null;
        $relaxed  = $context === 'edit' && $current !== null && user_can($current, 'edit_post', $postId);

        foreach (FieldGroups::groupsFor($postType) as $group) {
            $matches  = $relaxed ? Location::staticMatch($group, $postId, $current) : Location::applies($group, $postId, $current);
            $viewable = $matches ? self::accessMap($postType, $group, $user)->viewableFieldIds() : [];

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
            'sanitize_callback' => self::sanitizer($kind, $key, $field, $schema),
            'auth_callback'     => static fn (bool $allowed, string $metaKey, int $postId, int $userId): bool
                => self::canWrite($postType, $key, $postId, $userId),
            'revisions_enabled' => post_type_supports($postType, 'revisions'),
        ];

        if ($single && isset($field['default'])) {
            $args += self::defaultArg($key, $field['default'], $valueSchema);
        }

        return $args;
    }

    /**
     * Get the `default` arg coerced to the value schema, or none when it can't fit, since core then refuses the whole key.
     *
     * @return array{default?: mixed}
     */
    private static function defaultArg(string $key, mixed $default, array $schema): array
    {
        if (is_wp_error(rest_validate_value_from_schema($default, $schema, $key))) {
            _doing_it_wrong(__CLASS__ . '::registerFor', sprintf('Field "%s": its "default" does not fit the %s type, so it was dropped.', esc_html($key), esc_html((string) $schema['type'])), '1.2.0');

            return [];
        }

        return ['default' => rest_sanitize_value_from_schema($default, $schema, $key)];
    }

    /**
     * Build the sanitize callback for one field, adapting the field class to its storage kind.
     */
    private static function sanitizer(string $kind, string $key, array $field, array $schema): callable
    {
        $type    = (string) ($field['type'] ?? 'text');
        $handler = FieldRegistry::instance()->get($type);
        $args    = $field['args'] ?? [];
        $argsFor = static fn (mixed $value): array => Populate::argsFor($field, $value, self::savingValue($key, $kind));

        if ($kind === StorageKind::MULTI) {
            return static fn (mixed $value): mixed => self::sanitizeOption($handler, $value, $argsFor($value));
        }

        if ($kind === StorageKind::SCALAR && $type === 'file') {
            return static fn (mixed $value): int => self::sanitizeSingleFile($handler, $value, $args);
        }

        if ($kind === StorageKind::REPEATER) {
            $columns = $schema['items']['properties'];

            return static fn (mixed $value): array => self::sanitizeRows($handler, $value, $argsFor($value), $columns);
        }

        return static fn (mixed $value): mixed => $handler::sanitize($value, $argsFor($value));
    }

    /**
     * Get the stored value of a key on the post being saved, or null.
     */
    private static function savingValue(string $key, string $kind): mixed
    {
        if (array_key_exists($key, self::$savingStored)) {
            return self::$savingStored[$key];
        }

        if (self::$savingPostId <= 0 || !Aliases::exists(self::$savingPostId, $key)) {
            return null;
        }

        return get_post_meta(self::$savingPostId, $key, $kind !== StorageKind::MULTI);
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
            'select', 'radio'    => Populate::valueType($field['args'] ?? []),
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
     * Get the meta keys a user may write on a post, each mapped to its owning group's key; the bulk form of the REST write gate.
     *
     * @param string               $postType Post type slug.
     * @param int                  $postId   Post being saved.
     * @param WP_User              $user     User saving.
     * @param array<string, mixed> $live     Live location params the save sets, before core stores them.
     * @return array<string, string> Owning group key by meta key.
     */
    public static function writableKeys(string $postType, int $postId, WP_User $user, array $live = []): array
    {
        if (!$user->exists() || !user_can($user, 'edit_post', $postId)) {
            return [];
        }

        $keys    = [];
        $context = Location::context($postId, $user, $postType, $live);

        foreach (FieldGroups::groupsFor($postType) as $group) {
            if (!Location::matches($group, $context)) {
                continue;
            }

            $map = self::accessMap($postType, $group, $user);

            foreach (array_keys(self::stored(ConfigLoader::flatFields($group['config']))) as $key) {
                if ($map->canEdit((string) $key)) {
                    $keys[(string) $key] = $group['key'];
                }
            }
        }

        return $keys;
    }

    /**
     * Drop from a REST save the meta the user may not write that only echoes the stored value, as the editor sends every key it read.
     *
     * Real changes stay in, so the write gate still refuses them.
     *
     * @param WP_REST_Request      $request  Save request; its `meta` param is narrowed in place.
     * @param string               $postType Post type slug.
     * @param int                  $postId   Post being saved.
     * @param array<string, mixed> $live     Live location params the save sets, before core stores them.
     */
    public static function dropEchoes(WP_REST_Request $request, string $postType, int $postId, array $live): void
    {
        $meta = $request['meta'];

        if ($postId <= 0 || !is_array($meta) || $meta === []) {
            return;
        }

        $writable = self::writableKeys($postType, $postId, wp_get_current_user(), $live);
        $stored   = (new WP_REST_Post_Meta_Fields($postType))->get_value($postId, $request);
        $fields   = get_registered_meta_keys('post', $postType);

        foreach ($meta as $key => $value) {
            if (isset($writable[$key]) || !isset($fields[$key]) || !array_key_exists($key, $stored)) {
                continue;
            }

            $isEcho = $value === null
                ? !Aliases::exists($postId, (string) $key)
                : self::restValue($fields[$key], $value) === $stored[$key];

            if ($isEcho) {
                unset($meta[$key]);
            }
        }

        $request->set_param('meta', $meta);
    }

    /**
     * Normalise an incoming meta value the way REST prepares a stored one, so the two compare strictly.
     */
    private static function restValue(array $args, mixed $value): mixed
    {
        $schema = $args['show_in_rest']['schema'] ?? ['type' => $args['type']];
        $schema = $args['single'] ? $schema : ['type' => 'array', 'items' => $schema];

        return rest_sanitize_value_from_schema($value, $schema);
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
        // Only within REST callbacks, where a collection or a multi-key save would resolve the same map many times.
        if (self::$restDepth === 0) {
            return self::resolveMap($postType, $group, $user);
        }

        $key = get_current_blog_id() . ":{$postType}:{$group['owner']}:{$user->ID}";

        return self::$maps[$key] ??= self::resolveMap($postType, $group, $user);
    }

    /**
     * Resolve a group's access map for a user.
     */
    private static function resolveMap(string $postType, array $group, WP_User $user): ConfigAccessMap
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
