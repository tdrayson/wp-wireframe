<?php

declare(strict_types=1);

namespace Wireframe\PostFields;

use Wireframe\Framework\ConfigLoader;

/**
 * Rename aliases: a field's `previous_ids` stay readable until a write or `Meta::migrate()` moves them to the current key.
 */
final class Aliases
{
    private const BATCH = 200;

    /** @var array<string, array<string, list<string>>>|null Previous ids by meta key, by post type. */
    private static ?array $map = null;

    /** @var array<string, true>|null Every aliased current key, over all post types. */
    private static ?array $current = null;

    /** @var array<string, true>|null Every previous key, over all post types. */
    private static ?array $previous = null;

    /**
     * Hook the read fallback and the write cleanup.
     */
    public static function register(): void
    {
        // After core's registered-default filter (10), which would otherwise replace the previous value.
        add_filter('default_post_metadata', [self::class, 'fallback'], 20, 5);
        add_action('added_post_meta', [self::class, 'onChange'], 10, 3);
        add_action('updated_post_meta', [self::class, 'onChange'], 10, 3);
        add_action('deleted_post_meta', [self::class, 'onChange'], 10, 3);
    }

    /**
     * Forget the cached alias map; called whenever the attached groups change.
     */
    public static function flush(): void
    {
        self::$map = self::$current = self::$previous = null;
    }

    /**
     * Return the first previous key's value for a current key that has none of its own.
     *
     * @param mixed  $value    Default so far.
     * @param int    $postId   Post ID.
     * @param string $key      Meta key being read.
     * @param bool   $single   Whether a single value was asked for.
     * @param string $metaType Object type.
     * @return mixed
     */
    public static function fallback(mixed $value, int $postId, string $key, bool $single, string $metaType = ''): mixed
    {
        if ($metaType !== 'post') {
            return $value;
        }

        foreach (self::previousFor($postId, $key) as $old) {
            if (metadata_exists('post', $postId, $old)) {
                return get_post_meta($postId, $old, $single);
            }
        }

        return $value;
    }

    /**
     * Drop the previous keys once their current key is written or deleted.
     *
     * @param mixed  $metaId Meta ID, or IDs when deleting.
     * @param int    $postId Post ID.
     * @param string $key    Meta key.
     */
    public static function onChange(mixed $metaId, int $postId, string $key): void
    {
        self::purge($postId, $key);
    }

    /**
     * Delete the previous keys of a current key on a post.
     *
     * @param int    $postId Post ID.
     * @param string $key    Current meta key.
     */
    public static function purge(int $postId, string $key): void
    {
        foreach (self::previousFor($postId, $key) as $old) {
            delete_post_meta($postId, $old);
        }
    }

    /**
     * Whether a post has a value for a key, under it or under one of its previous ids.
     *
     * @param int    $postId Post ID.
     * @param string $key    Current meta key.
     */
    public static function exists(int $postId, string $key): bool
    {
        if (metadata_exists('post', $postId, $key)) {
            return true;
        }

        foreach (self::previousFor($postId, $key) as $old) {
            if (metadata_exists('post', $postId, $old)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a meta key is a previous id of any field.
     *
     * @param string $key Meta key.
     */
    public static function isPrevious(string $key): bool
    {
        self::build();

        return isset(self::$previous[$key]);
    }

    /**
     * Move every post's previous keys to the current ones where the current one is missing, then delete them.
     *
     * @param string|null $postType Post type slug, or null for every post type with groups.
     * @return int Posts changed.
     */
    public static function migrate(?string $postType = null): int
    {
        global $wpdb;

        self::build();

        $types   = $postType === null ? array_keys((array) self::$map) : [$postType];
        $changed = 0;

        foreach ($types as $type) {
            $aliases = self::$map[$type] ?? [];

            if ($aliases === []) {
                continue;
            }

            $old  = array_values(array_unique(array_merge(...array_values($aliases))));
            $in   = implode(', ', array_fill(0, count($old), '%s'));
            $last = 0;

            do {
                $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = %s AND p.ID > %d AND m.meta_key IN ({$in}) ORDER BY p.ID LIMIT %d",
                    [$type, $last, ...$old, self::BATCH]
                )));

                foreach ($ids as $id) {
                    $changed += self::migratePost($id, $type, $aliases) ? 1 : 0;
                    $last     = $id;
                }
            } while (count($ids) === self::BATCH);
        }

        return $changed;
    }

    /**
     * Describe why a group's own `previous_ids` are unusable, or null when they are fine.
     *
     * @param array $group Normalised group.
     */
    public static function problem(array $group): ?string
    {
        $fields  = self::topLevel($group);
        $claimed = [];

        foreach ($fields as $id => $field) {
            if (!array_key_exists('previous_ids', $field)) {
                continue;
            }

            $previous = $field['previous_ids'];

            if (!is_array($previous) || array_filter($previous, static fn (mixed $old): bool => !is_string($old) || $old === '') !== []) {
                return sprintf('field "%s": "previous_ids" must be a list of non-empty strings.', $id);
            }

            foreach ($previous as $old) {
                $problem = match (true) {
                    $old === (string) $id => sprintf('field "%s" lists itself in "previous_ids".', $id),
                    isset($fields[$old])  => sprintf('previous id "%s" of field "%s" is also a field id.', $old, $id),
                    isset($claimed[$old]) => sprintf('previous id "%s" is claimed by both "%s" and "%s".', $old, $claimed[$old], $id),
                    default               => null,
                };

                if ($problem !== null) {
                    return $problem;
                }

                $claimed[$old] = (string) $id;
            }
        }

        return null;
    }

    /**
     * Describe a previous id of one group that collides with a field id or previous id of another on the same post type.
     *
     * @param array  $group    Group being attached.
     * @param array  $other    Group already attached.
     * @param string $postType Post type slug.
     */
    public static function clash(array $group, array $other, string $postType): ?string
    {
        $mine   = self::previousIds($group);
        $theirs = self::previousIds($other);
        $hit    = array_values(array_intersect($mine, [...array_map('strval', array_keys(self::topLevel($other))), ...$theirs]))[0]
            ?? array_values(array_intersect(array_map('strval', array_keys(self::topLevel($group))), $theirs))[0]
            ?? null;

        return $hit === null ? null : sprintf('"%s" is a previous id that is also a field id or previous id in the group "%s" on post type "%s".', $hit, $other['id'], $postType);
    }

    /**
     * Run one post's aliased fields through the move; true when anything was found.
     *
     * @param array<string, list<string>> $aliases Previous ids by meta key.
     */
    private static function migratePost(int $postId, string $postType, array $aliases): bool
    {
        $fields  = FieldGroups::flatFields($postType);
        $touched = false;

        foreach ($aliases as $key => $previous) {
            $source = array_values(array_filter($previous, static fn (string $old): bool => metadata_exists('post', $postId, $old)))[0] ?? null;

            if ($source === null) {
                continue;
            }

            $touched = true;

            if (!metadata_exists('post', $postId, $key)) {
                $rows = get_post_meta($postId, $source);

                foreach (StorageKind::for($fields[$key]) === StorageKind::MULTI ? $rows : array_slice($rows, 0, 1) as $row) {
                    add_post_meta($postId, $key, wp_slash($row));
                }
            }

            self::purge($postId, $key);
        }

        return $touched;
    }

    /**
     * Get the previous ids of a key on a post's type.
     *
     * @return list<string>
     */
    private static function previousFor(int $postId, string $key): array
    {
        self::build();

        if (!isset(self::$current[$key])) {
            return [];
        }

        $postType = get_post_type($postId);

        return $postType === false ? [] : (self::$map[$postType][$key] ?? []);
    }

    /**
     * Get every previous id a group declares.
     *
     * @return list<string>
     */
    private static function previousIds(array $group): array
    {
        return array_values(array_merge([], ...array_values(self::map($group))));
    }

    /**
     * Get a group's usable aliases: previous ids by meta key, for fields that store a value.
     *
     * @return array<string, list<string>>
     */
    private static function map(array $group): array
    {
        $map = [];

        foreach (self::topLevel($group) as $id => $field) {
            $previous = $field['previous_ids'] ?? null;

            if (!is_array($previous) || StorageKind::for($field) === StorageKind::NONE) {
                continue;
            }

            $previous = array_values(array_filter($previous, static fn (mixed $old): bool => is_string($old) && $old !== ''));

            if ($previous !== []) {
                $map[(string) $id] = $previous;
            }
        }

        return $map;
    }

    /**
     * Get a group's top-level fields, which are its meta keys.
     *
     * @return array<string, array>
     */
    private static function topLevel(array $group): array
    {
        return array_filter(
            ConfigLoader::flatFields($group['config']),
            static fn (array $field, int|string $key): bool => !str_contains((string) $key, '.'),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Build the alias map from the attached groups, once per change.
     */
    private static function build(): void
    {
        if (self::$map !== null) {
            return;
        }

        self::$map      = [];
        self::$current  = [];
        self::$previous = [];

        foreach (FieldGroups::all() as $postType => $groups) {
            foreach ($groups as $group) {
                foreach (self::map($group) as $key => $previous) {
                    self::$map[$postType][$key] = $previous;
                    self::$current[$key]        = true;

                    foreach ($previous as $old) {
                        self::$previous[$old] = true;
                    }
                }
            }
        }
    }
}
