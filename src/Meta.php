<?php

declare(strict_types=1);

namespace Wireframe;

use Wireframe\Framework\Fields\FieldRegistry;
use Wireframe\Framework\Populate;
use Wireframe\PostFields\Aliases;
use Wireframe\PostFields\FieldGroups;
use Wireframe\PostFields\Index\IndexSync;
use Wireframe\PostFields\MetaRegistrar;
use Wireframe\PostFields\StorageKind;

/**
 * Post meta facade.
 *
 * Static API for reading and writing a post's field values, mirroring Settings with a post ID in place of the option key.
 * Dot notation reaches into repeaters (`Meta::get($id, 'sessions.0.venue')`). Only registered fields are readable or writable.
 */
final class Meta
{
    private const NESTED_KINDS = [StorageKind::REPEATER, StorageKind::LIST, StorageKind::MULTI];

    // ─── Core CRUD ────────────────────────────────────

    /**
     * Get a field value, falling back to the field's config default, then `$default`.
     *
     * @param int    $postId  Post ID.
     * @param string $key     Field id, with optional dot path.
     * @param mixed  $default Fallback when nothing is stored and the field has no default.
     */
    public static function get(int $postId, string $key, mixed $default = null): mixed
    {
        $top   = self::topKey($key);
        $field = self::field($postId, $top);

        if ($field === null) {
            return $default;
        }

        $value = self::value($postId, $top, $field);

        if ($top === $key) {
            return $value ?? $default ?? self::blank($field);
        }

        $nested = Settings::dotGet(is_array($value) ? $value : [], self::path($key, $top));

        return $nested ?? (self::flat($postId)[$key]['default'] ?? $default);
    }

    /**
     * Set a field value, sanitised through its field class. Returns false for an unregistered key.
     *
     * @param int    $postId Post ID.
     * @param string $key    Field id, with optional dot path into repeaters and lists.
     * @param mixed  $value  New value.
     */
    public static function set(int $postId, string $key, mixed $value): bool
    {
        return self::put($postId, $key, $value, true, __METHOD__);
    }

    /**
     * Check whether a field, or a path inside it, has a stored value.
     */
    public static function has(int $postId, string $key): bool
    {
        $top   = self::topKey($key);
        $field = self::field($postId, $top);

        if ($field === null) {
            return false;
        }

        $stored = self::stored($postId, $top, $field);

        if ($stored === null || $top === $key) {
            return $stored !== null;
        }

        return Settings::dotGet(is_array($stored) ? $stored : [], self::path($key, $top)) !== null;
    }

    /**
     * Delete a field, or one path inside it; a single-value field then reads as its default again.
     */
    public static function forget(int $postId, string $key): bool
    {
        $top   = self::topKey($key);
        $field = self::field($postId, $top);

        if ($field === null) {
            return false;
        }

        if ($top === $key) {
            self::remove($postId, $top);

            return !Aliases::exists($postId, $top);
        }

        $stored = self::stored($postId, $top, $field);

        if ($stored === null) {
            return true;
        }

        if (!is_array($stored)) {
            return false;
        }

        Settings::dotForget($stored, self::path($key, $top));

        return self::write($postId, $top, $field, $stored, true);
    }

    /**
     * Get the raw stored value of every registered field that has one (no defaults, no casting).
     *
     * @return array<string, mixed>
     */
    public static function all(int $postId): array
    {
        $saved = [];

        foreach (self::storedFields($postId) as $key => $field) {
            $stored = self::stored($postId, $key, $field);

            if ($stored !== null) {
                $saved[$key] = $stored;
            }
        }

        return $saved;
    }

    /**
     * Get every registered field's value with defaults merged in.
     *
     * @return array<string, mixed>
     */
    public static function resolved(int $postId): array
    {
        $merged = [];

        foreach (self::storedFields($postId) as $key => $field) {
            $merged[$key] = self::value($postId, $key, $field) ?? self::blank($field);
        }

        return $merged;
    }

    /**
     * Replace every registered field at once: the given keys are set, all others deleted.
     *
     * @param array<string, mixed> $values       Complete set of values.
     * @param bool                 $preSanitized Skip field-class sanitising.
     */
    public static function update(int $postId, array $values, bool $preSanitized = false): bool
    {
        $ok = true;

        foreach ($values as $key => $value) {
            $ok = self::put($postId, (string) $key, $value, !$preSanitized, __METHOD__) && $ok;
        }

        foreach (array_keys(array_diff_key(self::storedFields($postId), $values)) as $key) {
            self::remove($postId, $key);
        }

        return $ok;
    }

    /**
     * Delete every registered field's value (fields revert to defaults).
     */
    public static function reset(int $postId): bool
    {
        foreach (array_keys(self::storedFields($postId)) as $key) {
            self::remove($postId, $key);
        }

        return true;
    }

    /**
     * Check if any registered field has a stored value.
     */
    public static function exists(int $postId): bool
    {
        return self::all($postId) !== [];
    }

    // ─── Subset helpers ───────────────────────────────

    /**
     * Get only the specified keys (resolved with defaults).
     *
     * @return array<string, mixed>
     */
    public static function only(int $postId, string ...$keys): array
    {
        return array_intersect_key(self::resolved($postId), array_flip($keys));
    }

    /**
     * Get every field except the specified keys (resolved with defaults).
     *
     * @return array<string, mixed>
     */
    public static function except(int $postId, string ...$keys): array
    {
        return array_diff_key(self::resolved($postId), array_flip($keys));
    }

    // ─── Type-safe getters ────────────────────────────

    public static function bool(int $postId, string $key, bool $default = false): bool
    {
        $value = self::get($postId, $key);

        return $value !== null ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : $default;
    }

    public static function int(int $postId, string $key, int $default = 0): int
    {
        $value = self::get($postId, $key);

        return $value !== null ? (int) $value : $default;
    }

    public static function float(int $postId, string $key, float $default = 0.0): float
    {
        $value = self::get($postId, $key);

        return $value !== null ? (float) $value : $default;
    }

    public static function string(int $postId, string $key, string $default = ''): string
    {
        $value = self::get($postId, $key);

        return $value !== null ? (string) $value : $default;
    }

    public static function array(int $postId, string $key, array $default = []): array
    {
        $value = self::get($postId, $key);

        return is_array($value) ? $value : $default;
    }

    public static function json(int $postId, string $key, mixed $default = null): mixed
    {
        $value = self::get($postId, $key);

        if (!is_string($value)) {
            return $default;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    // ─── Conditional helpers ──────────────────────────

    public static function filled(int $postId, string $key, mixed $default = null): mixed
    {
        $value = self::get($postId, $key);

        if ($value === null || $value === '' || $value === [] || $value === false) {
            return $default;
        }

        return $value;
    }

    public static function transform(int $postId, string $key, callable $callback, mixed $default = null): mixed
    {
        $value = self::get($postId, $key);

        return $value !== null ? $callback($value) : $default;
    }

    public static function when(int $postId, string $key, callable $callback, mixed $default = null): mixed
    {
        return self::has($postId, $key) ? $callback(self::get($postId, $key)) : $default;
    }

    public static function increment(int $postId, string $key, int|float $amount = 1): bool
    {
        $current = self::get($postId, $key, 0);

        return self::set($postId, $key, (is_numeric($current) ? $current + 0 : 0) + $amount);
    }

    public static function decrement(int $postId, string $key, int|float $amount = 1): bool
    {
        return self::increment($postId, $key, -$amount);
    }

    public static function toggle(int $postId, string $key): bool
    {
        return self::set($postId, $key, !self::bool($postId, $key));
    }

    public static function getOrSet(int $postId, string $key, mixed $default): mixed
    {
        if (self::has($postId, $key)) {
            return self::get($postId, $key);
        }

        self::set($postId, $key, $default);

        return $default;
    }

    public static function push(int $postId, string $key, mixed $value): bool
    {
        $array   = self::array($postId, $key);
        $array[] = $value;

        return self::set($postId, $key, $array);
    }

    public static function pull(int $postId, string $key, mixed $default = null): mixed
    {
        $value = self::get($postId, $key, $default);
        self::forget($postId, $key);

        return $value;
    }

    /**
     * Rebuild the repeater index rows, needed after marking a subfield `indexed` on existing posts.
     *
     * @param string|null $postType Post type slug, or null for every post type with groups.
     * @return int Posts processed.
     */
    public static function reindex(?string $postType = null): int
    {
        return IndexSync::reindex($postType);
    }

    /**
     * Move values stored under a field's `previous_ids` to its current key, then delete the old keys.
     *
     * @param string|null $postType Post type slug, or null for every post type with groups.
     * @return int Posts changed.
     */
    public static function migrate(?string $postType = null): int
    {
        return Aliases::migrate($postType);
    }

    // ─── Internals ────────────────────────────────────

    /**
     * Resolve a key to its field and write the value, flagging unregistered keys and paths into scalars.
     */
    private static function put(int $postId, string $key, mixed $value, bool $sanitize, string $caller): bool
    {
        $top   = self::topKey($key);
        $field = self::field($postId, $top);

        if ($field === null) {
            _doing_it_wrong($caller, sprintf('"%s" is not a field registered for post %d.', $top, $postId), '1.2.0');

            return false;
        }

        if ($top !== $key) {
            if (!in_array(StorageKind::for($field), self::NESTED_KINDS, true)) {
                _doing_it_wrong($caller, sprintf('"%s" does not hold nested values.', $top), '1.2.0');

                return false;
            }

            $nested = self::stored($postId, $top, $field);
            $nested = is_array($nested) ? $nested : [];
            Settings::dotSet($nested, self::path($key, $top), $value);
            $value  = $nested;
        }

        return self::write($postId, $top, $field, $value, $sanitize);
    }

    /**
     * Store a top-level value in its storage kind: one row, or one row per value for multi-value fields.
     */
    private static function write(int $postId, string $key, array $field, mixed $value, bool $sanitize): bool
    {
        $kind   = StorageKind::for($field);
        $stored = self::stored($postId, $key, $field);
        $value  = $sanitize ? self::sanitize($kind, $field, $value, $stored) : $value;

        return MetaRegistrar::whileSaving($postId, static fn (): bool => self::store($postId, $key, $kind, $value), [$key => $stored]);
    }

    /**
     * Write a sanitised value's rows.
     */
    private static function store(int $postId, string $key, string $kind, mixed $value): bool
    {
        if ($kind === StorageKind::MULTI) {
            $rows = array_values((array) $value);

            if (metadata_exists('post', $postId, $key) && get_post_meta($postId, $key) === array_map('strval', $rows)) {
                return true;
            }

            self::remove($postId, $key);

            foreach ($rows as $row) {
                if (!add_post_meta($postId, $key, wp_slash($row))) {
                    return false;
                }
            }

            return true;
        }

        // update_post_meta() returns false for an unchanged value, which still counts as stored.
        return update_post_meta($postId, $key, wp_slash($value)) !== false || metadata_exists('post', $postId, $key);
    }

    /**
     * Sanitise a value with its field class into its stored shape; the registered meta callback then re-runs it idempotently.
     */
    private static function sanitize(string $kind, array $field, mixed $value, mixed $stored): mixed
    {
        $handler = FieldRegistry::instance()->get((string) ($field['type'] ?? 'text'));

        if ($kind === StorageKind::MULTI) {
            $value = array_map('strval', array_filter((array) $value, 'is_scalar'));
        }

        $clean = $handler::sanitize($value, Populate::argsFor($field, $value, $stored));

        if ($kind === StorageKind::SCALAR && ($field['type'] ?? '') === 'file') {
            return (int) (array_values((array) $clean)[0] ?? 0);
        }

        return $clean;
    }

    /**
     * Read a field's stored value, or null when nothing is stored.
     */
    private static function stored(int $postId, string $key, array $field): mixed
    {
        if (StorageKind::for($field) === StorageKind::MULTI) {
            $rows = get_post_meta($postId, $key);

            return $rows === [] ? null : $rows;
        }

        return Aliases::exists($postId, $key) ? get_post_meta($postId, $key, true) : null;
    }

    /**
     * Delete a field's rows and its previous keys, so it can't read an old value back.
     */
    private static function remove(int $postId, string $key): void
    {
        delete_post_meta($postId, $key);
        Aliases::purge($postId, $key);
    }

    /**
     * Read a field's typed value, or its config default when nothing is stored (multi-value: none outside auto-drafts).
     */
    private static function value(int $postId, string $key, array $field): mixed
    {
        $stored = self::stored($postId, $key, $field);

        if ($stored !== null) {
            return self::typed($field, $stored);
        }

        // A multi-value field stores no rows when emptied, so its default only seeds a new post.
        if (StorageKind::for($field) === StorageKind::MULTI && get_post_status($postId) !== 'auto-draft') {
            return null;
        }

        return $field['default'] ?? null;
    }

    /**
     * Get what an unset field reads as when it has no default: `[]` for multi-value, else null.
     */
    private static function blank(array $field): mixed
    {
        return StorageKind::for($field) === StorageKind::MULTI ? [] : null;
    }

    /**
     * Cast a stored scalar, or each value of a multi-value field, to its REST schema type, since post meta returns strings.
     */
    private static function typed(array $field, mixed $value): mixed
    {
        $kind = StorageKind::for($field);

        if ($kind === StorageKind::MULTI && is_array($value)) {
            return Populate::typed($field['args'] ?? [], $value);
        }

        if ($kind !== StorageKind::SCALAR) {
            return $value;
        }

        return match (MetaRegistrar::schemaFor($field)['type']) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'number'  => is_numeric($value) ? $value + 0 : $value,
            'integer' => is_numeric($value) ? (int) $value : $value,
            default   => $value,
        };
    }

    /**
     * Get a registered, stored top-level field config, or null.
     */
    private static function field(int $postId, string $key): ?array
    {
        return self::storedFields($postId)[$key] ?? null;
    }

    /**
     * Get the top-level fields of a post's type that store a value, keyed by meta key.
     *
     * @return array<string, array>
     */
    private static function storedFields(int $postId): array
    {
        return array_filter(
            self::flat($postId),
            static fn (array $field, int|string $key): bool => !str_contains((string) $key, '.') && StorageKind::for($field) !== StorageKind::NONE,
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Get every field across the post type's groups, repeater subfields as `{id}.{sub}`.
     *
     * @return array<string, array>
     */
    private static function flat(int $postId): array
    {
        return FieldGroups::flatFields((string) get_post_type($postId));
    }

    private static function topKey(string $key): string
    {
        return explode('.', $key, 2)[0];
    }

    private static function path(string $key, string $top): string
    {
        return substr($key, strlen($top) + 1);
    }
}
