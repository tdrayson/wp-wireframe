<?php

declare(strict_types=1);

namespace Wireframe\Framework;

/**
 * Dot-notation access into nested arrays, for Settings and Meta.
 *
 * @internal
 */
final class Dot
{
    /**
     * Get a nested value by dot path.
     *
     * @param array  $array Array to read.
     * @param string $key   Dot path, such as `a.b.c`.
     * @return mixed The value, or null when any segment is missing.
     */
    public static function get(array $array, string $key): mixed
    {
        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return null;
            }
            $array = $array[$segment];
        }

        return $array;
    }

    /**
     * Set a nested value by dot path, creating or replacing intermediate arrays.
     *
     * @param array  $array Array to write, by reference.
     * @param string $key   Dot path.
     * @param mixed  $value Value to set.
     */
    public static function set(array &$array, string $key, mixed $value): void
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

    /**
     * Remove a nested value by dot path; a missing segment leaves the array alone.
     *
     * @param array  $array Array to write, by reference.
     * @param string $key   Dot path.
     */
    public static function forget(array &$array, string $key): void
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
}
