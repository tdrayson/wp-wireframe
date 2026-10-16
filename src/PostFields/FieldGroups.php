<?php

declare(strict_types=1);

namespace Wireframe\PostFields;

use Wireframe\Framework\ConfigLoader;
use Wireframe\Framework\ConfigValidator;

/**
 * Registry of field groups attached to post types.
 *
 * Groups are collected at `App::boot()` and validated once on `wp_loaded`.
 */
final class FieldGroups
{
    /** Field types with no stored value, or no meaning, on a post screen. */
    private const EXCLUDED_TYPES = ['action', 'table', 'import', 'export', 'upload'];

    /** @var array<string, array{id: string, prefix: string, post_types: list<string>, config: string}> Groups by owner key, in registration order. */
    private static array $groups = [];

    /** @var array<string, array{id: string, prefix: string, post_types: list<string>, config: string}> Valid groups by post type. */
    private static array $claimed = [];

    /**
     * Register a field group, replacing one with the same prefix and id; boots at once after `wp_loaded`.
     *
     * @param string $prefix Consumer prefix from `App::boot()`.
     * @param array  $group  `id`, `post_types`, and `config` (a ConfigLoader slug or a raw config array).
     */
    public static function register(string $prefix, array $group): void
    {
        $id   = is_string($group['id'] ?? null) ? $group['id'] : '';
        $slug = self::slug($prefix, $id);

        if (is_array($group['config'] ?? null)) {
            ConfigLoader::register($slug, $group['config']);
        } elseif (is_string($group['config'] ?? null) && $group['config'] !== '') {
            $slug = $group['config'];
        }

        self::$groups[self::owner(['prefix' => $prefix, 'id' => $id])] = [
            'id'         => $id,
            'prefix'     => $prefix,
            'post_types' => array_values(array_unique(array_filter((array) ($group['post_types'] ?? []), 'is_string'))),
            'config'     => $slug,
        ];

        if (did_action('wp_loaded') > 0) {
            self::boot();
        }
    }

    /**
     * Validate every registered group and claim post types for the valid ones.
     *
     * Safe to run again: the result is rebuilt from the registered groups.
     */
    public static function boot(): void
    {
        self::$claimed = [];

        foreach (self::$groups as $group) {
            $problem = self::configProblem($group) ?? self::postTypeProblem($group);

            if ($problem !== null) {
                self::reject($group, $problem);
                continue;
            }

            foreach ($group['post_types'] as $postType) {
                self::claim($group, $postType);
            }
        }
    }

    /**
     * Get the group attached to a post type.
     *
     * @param string $postType Post type slug.
     * @return array{id: string, prefix: string, post_types: list<string>, config: string}|null
     */
    public static function forPostType(string $postType): ?array
    {
        return self::$claimed[$postType] ?? null;
    }

    /**
     * Get a post type's fields keyed by id, repeater subfields as `{id}.{sub}`.
     *
     * @param string $postType Post type slug.
     * @return array<string, array>
     */
    public static function flatFields(string $postType): array
    {
        $group = self::forPostType($postType);

        return $group === null ? [] : ConfigLoader::flatFields($group['config']);
    }

    /**
     * Get every claimed group, keyed by post type.
     *
     * @return array<string, array{id: string, prefix: string, post_types: list<string>, config: string}>
     */
    public static function all(): array
    {
        return self::$claimed;
    }

    /**
     * Forget every group and release this registry's site-wide claims.
     */
    public static function reset(): void
    {
        foreach (self::$claimed as $postType => $group) {
            if (($GLOBALS['wireframe_field_group_owners'][$postType] ?? null) === self::owner($group)) {
                unset($GLOBALS['wireframe_field_group_owners'][$postType]);
            }
        }

        self::$groups  = [];
        self::$claimed = [];
    }

    /**
     * Build the ConfigLoader slug for a group.
     */
    private static function slug(string $prefix, string $id): string
    {
        return preg_replace('/[^a-z0-9_-]/i', '-', "{$prefix}-group-{$id}") ?: 'settings';
    }

    /**
     * Get the site-wide owner key for a group.
     */
    private static function owner(array $group): string
    {
        return $group['prefix'] . '/' . $group['id'];
    }

    /**
     * Describe why a group's config is unusable, or null when it is fine.
     */
    private static function configProblem(array $group): ?string
    {
        self::lint($group);

        if ($group['id'] === '') {
            return 'a field group needs a non-empty "id".';
        }

        if ($group['post_types'] === []) {
            return 'it needs at least one entry in "post_types".';
        }

        $fields = ConfigLoader::flatFields($group['config']);

        if ($fields === []) {
            return 'its config has no fields.';
        }

        foreach ($fields as $path => $field) {
            $type = (string) ($field['type'] ?? '');

            if (in_array($type, self::EXCLUDED_TYPES, true)) {
                return sprintf('field "%s" has type "%s", which is not supported on post screens.', $path, $type);
            }
        }

        return null;
    }

    /**
     * Describe why any of a group's post types cannot be attached, or null when all can.
     */
    private static function postTypeProblem(array $group): ?string
    {
        foreach ($group['post_types'] as $postType) {
            $type = get_post_type_object($postType);

            if ($type === null) {
                return sprintf('post type "%s" is not registered.', $postType);
            }

            if (empty($type->show_in_rest)) {
                return sprintf('post type "%s" must be registered with show_in_rest => true.', $postType);
            }

            if (isset(self::$claimed[$postType])) {
                return sprintf('post type "%s" already has the field group "%s".', $postType, self::$claimed[$postType]['id']);
            }
        }

        return null;
    }

    /**
     * Claim a post type for a group, unless another bundled copy already owns it.
     */
    private static function claim(array $group, string $postType): void
    {
        $owner = self::owner($group);

        if (($GLOBALS['wireframe_field_group_owners'][$postType] ?? $owner) !== $owner) {
            return;
        }

        $GLOBALS['wireframe_field_group_owners'][$postType] = $owner;
        self::$claimed[$postType] = $group;
        add_post_type_support($postType, 'custom-fields');
    }

    /**
     * Report config linter findings under WP_DEBUG; they never skip the group.
     */
    private static function lint(array $group): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $config = ConfigLoader::load($group['config']);

        foreach (ConfigValidator::validate($config, $group['config']) as $issue) {
            _doing_it_wrong(__CLASS__ . '::boot', sprintf('Field group "%s" (%s) config issue: %s', $group['id'], $group['prefix'], $issue), '1.2.0');
        }
    }

    /**
     * Report a skipped group.
     */
    private static function reject(array $group, string $reason): void
    {
        _doing_it_wrong(
            __CLASS__ . '::boot',
            sprintf('Field group "%s" (%s) was skipped: %s', $group['id'], $group['prefix'], $reason),
            '1.2.0'
        );
    }
}
