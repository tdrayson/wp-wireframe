<?php

declare(strict_types=1);

namespace Wireframe;

/**
 * Settings bound to one wp_options key.
 *
 * Wraps the static Settings facade with the key fixed for the object's
 * lifetime, so callers don't repeat it on every call:
 *
 *   $settings = Settings::for('my_plugin_settings');
 *   $settings->get('site_name');
 *   $settings->set('theme', 'dark');
 */
final class SettingsRepository
{
    /**
     * @param string $optionKey The wp_options key every call reads and writes.
     */
    public function __construct(private readonly string $optionKey)
    {
    }

    /**
     * The wp_options key this instance is bound to.
     *
     * @return string
     */
    public function optionKey(): string
    {
        return $this->optionKey;
    }

    // ─── Core CRUD ────────────────────────────────────

    /**
     * Get a setting value with dot notation support.
     *
     * @param string $key     Setting key, dot notation allowed.
     * @param mixed  $default Fallback when neither a saved value nor a field default exists.
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Settings::get($this->optionKey, $key, $default);
    }

    /**
     * Set a setting value, sanitized through the field's handler.
     *
     * @param string $key   Setting key, dot notation allowed.
     * @param mixed  $value Value to store.
     * @return bool Whether the option was updated.
     */
    public function set(string $key, mixed $value): bool
    {
        return Settings::set($this->optionKey, $key, $value);
    }

    /**
     * Check if a setting exists and is not null.
     *
     * @param string $key Setting key, dot notation allowed.
     * @return bool
     */
    public function has(string $key): bool
    {
        return Settings::has($this->optionKey, $key);
    }

    /**
     * Remove a setting.
     *
     * @param string $key Setting key, dot notation allowed.
     * @return bool Whether the option was updated.
     */
    public function forget(string $key): bool
    {
        return Settings::forget($this->optionKey, $key);
    }

    /**
     * Get all raw saved settings (no defaults merged).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return Settings::all($this->optionKey);
    }

    /**
     * Get all settings with field defaults merged in.
     *
     * @return array<string, mixed>
     */
    public function resolved(): array
    {
        return Settings::resolved($this->optionKey);
    }

    /**
     * Replace all settings at once.
     *
     * @param array<string, mixed> $values       Complete settings array.
     * @param bool                 $preSanitized Skip handler sanitization.
     * @return bool Whether the option was updated.
     */
    public function update(array $values, bool $preSanitized = false): bool
    {
        return Settings::update($this->optionKey, $values, $preSanitized);
    }

    /**
     * Delete all saved settings (fields revert to defaults).
     *
     * @return bool Whether the option was deleted.
     */
    public function reset(): bool
    {
        return Settings::reset($this->optionKey);
    }

    /**
     * Check if any settings have been saved.
     *
     * @return bool
     */
    public function exists(): bool
    {
        return Settings::exists($this->optionKey);
    }

    // ─── Subset helpers ───────────────────────────────

    /**
     * Get only the specified keys (resolved with defaults).
     *
     * @param string ...$keys Top-level setting keys.
     * @return array<string, mixed>
     */
    public function only(string ...$keys): array
    {
        return Settings::only($this->optionKey, ...$keys);
    }

    /**
     * Get all resolved settings except the specified keys.
     *
     * @param string ...$keys Top-level setting keys.
     * @return array<string, mixed>
     */
    public function except(string ...$keys): array
    {
        return Settings::except($this->optionKey, ...$keys);
    }

    // ─── Type-safe getters ────────────────────────────

    /**
     * Get a setting as a bool.
     *
     * @param string $key     Setting key, dot notation allowed.
     * @param bool   $default Fallback when the value is null.
     * @return bool
     */
    public function bool(string $key, bool $default = false): bool
    {
        return Settings::bool($this->optionKey, $key, $default);
    }

    /**
     * Get a setting as an int.
     *
     * @param string $key     Setting key, dot notation allowed.
     * @param int    $default Fallback when the value is null.
     * @return int
     */
    public function int(string $key, int $default = 0): int
    {
        return Settings::int($this->optionKey, $key, $default);
    }

    /**
     * Get a setting as a float.
     *
     * @param string $key     Setting key, dot notation allowed.
     * @param float  $default Fallback when the value is null.
     * @return float
     */
    public function float(string $key, float $default = 0.0): float
    {
        return Settings::float($this->optionKey, $key, $default);
    }

    /**
     * Get a setting as a string.
     *
     * @param string $key     Setting key, dot notation allowed.
     * @param string $default Fallback when the value is null.
     * @return string
     */
    public function string(string $key, string $default = ''): string
    {
        return Settings::string($this->optionKey, $key, $default);
    }

    /**
     * Get a setting as an array.
     *
     * @param string $key     Setting key, dot notation allowed.
     * @param array  $default Fallback when the value is not an array.
     * @return array
     */
    public function array(string $key, array $default = []): array
    {
        return Settings::array($this->optionKey, $key, $default);
    }

    /**
     * Get a setting as decoded JSON.
     *
     * @param string $key     Setting key, dot notation allowed.
     * @param mixed  $default Fallback when the value is not a string or not valid JSON.
     * @return mixed
     */
    public function json(string $key, mixed $default = null): mixed
    {
        return Settings::json($this->optionKey, $key, $default);
    }

    // ─── Conditional helpers ──────────────────────────

    /**
     * Get a setting, or the default when it is null, '', [] or false.
     *
     * @param string $key     Setting key, dot notation allowed.
     * @param mixed  $default Fallback for empty values.
     * @return mixed
     */
    public function filled(string $key, mixed $default = null): mixed
    {
        return Settings::filled($this->optionKey, $key, $default);
    }

    /**
     * Pass a non-null setting through a callback.
     *
     * @param string   $key      Setting key, dot notation allowed.
     * @param callable $callback Receives the value.
     * @param mixed    $default  Returned when the value is null.
     * @return mixed
     */
    public function transform(string $key, callable $callback, mixed $default = null): mixed
    {
        return Settings::transform($this->optionKey, $key, $callback, $default);
    }

    /**
     * Run a callback with the setting only when it exists.
     *
     * @param string   $key      Setting key, dot notation allowed.
     * @param callable $callback Receives the value.
     * @param mixed    $default  Returned when the setting is missing.
     * @return mixed
     */
    public function when(string $key, callable $callback, mixed $default = null): mixed
    {
        return Settings::when($this->optionKey, $key, $callback, $default);
    }

    /**
     * Add to a numeric setting.
     *
     * @param string    $key    Setting key, dot notation allowed.
     * @param int|float $amount Amount to add.
     * @return bool Whether the option was updated.
     */
    public function increment(string $key, int|float $amount = 1): bool
    {
        return Settings::increment($this->optionKey, $key, $amount);
    }

    /**
     * Subtract from a numeric setting.
     *
     * @param string    $key    Setting key, dot notation allowed.
     * @param int|float $amount Amount to subtract.
     * @return bool Whether the option was updated.
     */
    public function decrement(string $key, int|float $amount = 1): bool
    {
        return Settings::decrement($this->optionKey, $key, $amount);
    }

    /**
     * Flip a boolean setting.
     *
     * @param string $key Setting key, dot notation allowed.
     * @return bool Whether the option was updated.
     */
    public function toggle(string $key): bool
    {
        return Settings::toggle($this->optionKey, $key);
    }

    /**
     * Get a setting, storing and returning the default when it is missing.
     *
     * @param string $key     Setting key, dot notation allowed.
     * @param mixed  $default Value to store when missing.
     * @return mixed
     */
    public function getOrSet(string $key, mixed $default): mixed
    {
        return Settings::getOrSet($this->optionKey, $key, $default);
    }

    /**
     * Append a value to an array setting.
     *
     * @param string $key   Setting key, dot notation allowed.
     * @param mixed  $value Value to append.
     * @return bool Whether the option was updated.
     */
    public function push(string $key, mixed $value): bool
    {
        return Settings::push($this->optionKey, $key, $value);
    }

    /**
     * Get a setting and remove it.
     *
     * @param string $key     Setting key, dot notation allowed.
     * @param mixed  $default Fallback when the setting is missing.
     * @return mixed
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        return Settings::pull($this->optionKey, $key, $default);
    }
}
