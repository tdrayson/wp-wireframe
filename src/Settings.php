<?php

declare(strict_types=1);

namespace Wireframe;

use Wireframe\Framework\ConfigLoader;
use Wireframe\Framework\Fields\FieldRegistry;

/**
 * Settings facade.
 *
 * Multi-tenant static API for reading and writing plugin settings. Every
 * method takes the wp_options key as its first argument so callers from
 * different plugins don't collide:
 *
 *   Settings::get('my_plugin_settings', 'site_name');
 *   Settings::bool('my_plugin_settings', 'notifications');
 *   Settings::set('my_plugin_settings', 'theme', 'dark');
 *
 * Dot notation is supported for nested values (repeaters, arrays):
 *   Settings::get('my_plugin_settings', 'redirects.0.from_path');
 */
final class Settings
{
    // ─── Core CRUD ────────────────────────────────────

    /**
     * Get a setting value with dot notation support.
     */
    public static function get(string $optionKey, string $key, mixed $default = null): mixed
    {
        $settings = self::all($optionKey);

        if (str_contains($key, '.')) {
            return self::dotGet($settings, $key) ?? self::fieldDefault($optionKey, $key, $default);
        }

        if (array_key_exists($key, $settings)) {
            return $settings[$key];
        }

        return self::fieldDefault($optionKey, $key, $default);
    }

    /**
     * Set a setting value with dot notation support.
     *
     * The value is sanitized through the field's registered handler
     * before storage, so the same rules apply as the REST save path.
     */
    public static function set(string $optionKey, string $key, mixed $value): bool
    {
        $settings = self::all($optionKey);

        $topKey = str_contains($key, '.') ? explode('.', $key, 2)[0] : $key;
        $value  = self::sanitizeField($optionKey, $topKey, $key === $topKey ? $value : null, $value);

        if (str_contains($key, '.')) {
            self::dotSet($settings, $key, $value);
        } else {
            $settings[$key] = $value;
        }

        return update_option($optionKey, $settings);
    }

    /**
     * Check if a setting exists and is not null.
     */
    public static function has(string $optionKey, string $key): bool
    {
        $settings = self::all($optionKey);

        if (str_contains($key, '.')) {
            return self::dotGet($settings, $key) !== null;
        }

        return array_key_exists($key, $settings) && $settings[$key] !== null;
    }

    /**
     * Remove a setting.
     */
    public static function forget(string $optionKey, string $key): bool
    {
        $settings = self::all($optionKey);

        if (str_contains($key, '.')) {
            self::dotForget($settings, $key);
        } else {
            unset($settings[$key]);
        }

        return update_option($optionKey, $settings);
    }

    /**
     * Get all raw saved settings for an option key (no defaults merged).
     *
     * @return array<string, mixed>
     */
    public static function all(string $optionKey): array
    {
        $saved = get_option($optionKey, []);

        return is_array($saved) ? $saved : [];
    }

    /**
     * Get all settings with field defaults merged in.
     *
     * @return array<string, mixed>
     */
    public static function resolved(string $optionKey): array
    {
        return self::resolvedFor($optionKey, App::configSlugForOptionKey($optionKey));
    }

    /**
     * Replace all settings at once.
     *
     * @param array<string, mixed> $values       Complete settings array.
     * @param bool                 $preSanitized Skip handler sanitization (true when called from the REST controller).
     */
    public static function update(string $optionKey, array $values, bool $preSanitized = false): bool
    {
        if (!$preSanitized) {
            $configSlug = App::configSlugForOptionKey($optionKey);
            $fields     = ConfigLoader::flatFields($configSlug);
            $registry   = FieldRegistry::instance();

            foreach ($values as $key => $value) {
                if (!isset($fields[$key])) {
                    continue;
                }
                $type    = $fields[$key]['type'] ?? 'text';
                $handler = $registry->get($type);
                $args    = $fields[$key]['args'] ?? [];

                if (!$handler::isStateless()) {
                    $values[$key] = $handler::sanitize($value, $args);
                }
            }
        }

        return update_option($optionKey, $values);
    }

    /**
     * Delete all saved settings (fields revert to defaults).
     */
    public static function reset(string $optionKey): bool
    {
        return delete_option($optionKey);
    }

    /**
     * Check if any settings have been saved for this option key.
     */
    public static function exists(string $optionKey): bool
    {
        return !empty(self::all($optionKey));
    }

    // ─── Subset helpers ───────────────────────────────

    /**
     * Get only the specified keys (resolved with defaults).
     *
     * @return array<string, mixed>
     */
    public static function only(string $optionKey, string ...$keys): array
    {
        $resolved = self::resolved($optionKey);
        $result   = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $resolved)) {
                $result[$key] = $resolved[$key];
            }
        }

        return $result;
    }

    /**
     * Get all settings except the specified keys.
     *
     * @return array<string, mixed>
     */
    public static function except(string $optionKey, string ...$keys): array
    {
        return array_diff_key(self::resolved($optionKey), array_flip($keys));
    }

    // ─── Type-safe getters ────────────────────────────

    public static function bool(string $optionKey, string $key, bool $default = false): bool
    {
        $value = self::get($optionKey, $key);

        return $value !== null ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : $default;
    }

    public static function int(string $optionKey, string $key, int $default = 0): int
    {
        $value = self::get($optionKey, $key);

        return $value !== null ? (int) $value : $default;
    }

    public static function float(string $optionKey, string $key, float $default = 0.0): float
    {
        $value = self::get($optionKey, $key);

        return $value !== null ? (float) $value : $default;
    }

    public static function string(string $optionKey, string $key, string $default = ''): string
    {
        $value = self::get($optionKey, $key);

        return $value !== null ? (string) $value : $default;
    }

    public static function array(string $optionKey, string $key, array $default = []): array
    {
        $value = self::get($optionKey, $key);

        return is_array($value) ? $value : $default;
    }

    public static function json(string $optionKey, string $key, mixed $default = null): mixed
    {
        $value = self::get($optionKey, $key);

        if (!is_string($value)) {
            return $default;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    // ─── Conditional helpers ──────────────────────────

    public static function filled(string $optionKey, string $key, mixed $default = null): mixed
    {
        $value = self::get($optionKey, $key);

        if ($value === null || $value === '' || $value === [] || $value === false) {
            return $default;
        }

        return $value;
    }

    public static function transform(string $optionKey, string $key, callable $callback, mixed $default = null): mixed
    {
        $value = self::get($optionKey, $key);

        return $value !== null ? $callback($value) : $default;
    }

    public static function when(string $optionKey, string $key, callable $callback, mixed $default = null): mixed
    {
        return self::has($optionKey, $key) ? $callback(self::get($optionKey, $key)) : $default;
    }

    public static function increment(string $optionKey, string $key, int|float $amount = 1): bool
    {
        return self::set($optionKey, $key, self::get($optionKey, $key, 0) + $amount);
    }

    public static function decrement(string $optionKey, string $key, int|float $amount = 1): bool
    {
        return self::increment($optionKey, $key, -$amount);
    }

    public static function toggle(string $optionKey, string $key): bool
    {
        return self::set($optionKey, $key, !self::bool($optionKey, $key));
    }

    public static function getOrSet(string $optionKey, string $key, mixed $default): mixed
    {
        if (self::has($optionKey, $key)) {
            return self::get($optionKey, $key);
        }

        self::set($optionKey, $key, $default);

        return $default;
    }

    public static function push(string $optionKey, string $key, mixed $value): bool
    {
        $array   = self::array($optionKey, $key);
        $array[] = $value;

        return self::set($optionKey, $key, $array);
    }

    public static function pull(string $optionKey, string $key, mixed $default = null): mixed
    {
        $value = self::get($optionKey, $key, $default);
        self::forget($optionKey, $key);

        return $value;
    }

    // ─── Framework-internal helpers (used by REST & AdminPage) ─

    /**
     * Resolve settings with defaults, using an explicit config slug.
     *
     * Used by the REST controller and admin page where the config slug
     * is already known (and may not yet be registered in the App map).
     */
    public static function resolvedFor(string $optionKey, string $configSlug): array
    {
        $saved  = self::all($optionKey);
        $fields = ConfigLoader::flatFields($configSlug);
        $merged = [];

        foreach ($fields as $fieldId => $fieldConfig) {
            if (str_contains($fieldId, '.')) {
                continue;
            }
            $default = $fieldConfig['default'] ?? null;
            $merged[$fieldId] = $saved[$fieldId] ?? $default;
        }

        return $merged;
    }

    public static function existsFor(string $optionKey): bool
    {
        return self::exists($optionKey);
    }

    public static function allFor(string $optionKey): array
    {
        return self::all($optionKey);
    }

    public static function updateFor(string $optionKey, array $values): bool
    {
        return update_option($optionKey, $values);
    }

    public static function resetFor(string $optionKey): bool
    {
        return self::reset($optionKey);
    }

    // ─── Dot notation internals ───────────────────────

    private static function dotGet(array $array, string $key): mixed
    {
        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return null;
            }
            $array = $array[$segment];
        }

        return $array;
    }

    private static function dotSet(array &$array, string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $last = array_pop($keys);

        foreach ($keys as $segment) {
            if (!isset($array[$segment]) || !is_array($array[$segment])) {
                $array[$segment] = [];
            }
            $array = &$array[$segment];
        }

        $array[$last] = $value;
    }

    private static function dotForget(array &$array, string $key): void
    {
        $keys = explode('.', $key);
        $last = array_pop($keys);

        foreach ($keys as $segment) {
            if (!isset($array[$segment]) || !is_array($array[$segment])) {
                return;
            }
            $array = &$array[$segment];
        }

        unset($array[$last]);
    }

    private static function fieldDefault(string $optionKey, string $key, mixed $fallback): mixed
    {
        $fields = ConfigLoader::flatFields(App::configSlugForOptionKey($optionKey));

        return $fields[$key]['default'] ?? $fallback;
    }

    /**
     * Sanitize a value through its registered field handler.
     *
     * For top-level keys, sanitizes the full value. For dot-notation keys,
     * returns the leaf as-is (repeater row updates need full context).
     */
    private static function sanitizeField(
        string $optionKey,
        string $topKey,
        mixed $topValue,
        mixed $leafValue
    ): mixed {
        $fields = ConfigLoader::flatFields(App::configSlugForOptionKey($optionKey));

        if (!isset($fields[$topKey])) {
            return $leafValue;
        }

        $fieldConfig = $fields[$topKey];
        $type        = $fieldConfig['type'] ?? 'text';
        $args        = $fieldConfig['args'] ?? [];
        $handler     = FieldRegistry::instance()->get($type);

        if ($handler::isStateless()) {
            return $leafValue;
        }

        if ($topValue !== null) {
            return $handler::sanitize($topValue, $args);
        }

        return $leafValue;
    }
}
