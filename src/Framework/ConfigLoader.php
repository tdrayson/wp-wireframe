<?php

declare(strict_types=1);

namespace Wireframe\Framework;

/**
 * Loads, normalizes, and caches settings configurations.
 *
 * Supports three input formats:
 *   1. Fields only   — `['fields' => [...]]`
 *   2. Sections only — `['sections' => [...]]`
 *   3. Full tabs     — `['tabs' => [...]]`
 *
 * All formats use sequential arrays with explicit `id` keys.
 * The normalizer ensures the canonical structure is always:
 *   `['tabs' => [['id' => ..., 'sections' => [['id' => ..., 'fields' => [...]]]]]]`
 */
final class ConfigLoader
{
    /** @var array<string, array> Cached normalized configs keyed by slug. */
    private static array $configs = [];

    /** @var array<string, array> Cached flat field maps keyed by slug. */
    private static array $flatFieldsCache = [];

    /** @var array<string, array> Configs registered as inline arrays at boot time. */
    private static array $inlineConfigs = [];

    /**
     * Register a config array under a slug.
     *
     * Called from App::boot() once per page, after resolving the `config`
     * option (either an inline array or a path to a config file).
     *
     * @param string $slug   Config slug.
     * @param array  $config Raw config array (same shape as a config file return).
     */
    public static function register(string $slug, array $config): void
    {
        self::$inlineConfigs[$slug] = $config;
        unset(self::$configs[$slug], self::$flatFieldsCache[$slug]);
    }

    /**
     * Load and normalize a registered config by slug.
     *
     * Returns an empty config if nothing was registered under the slug.
     *
     * @param string $slug Config slug.
     * @return array Normalized config with canonical tab/section/field structure.
     */
    public static function load(string $slug = 'settings'): array
    {
        if (isset(self::$configs[$slug])) {
            return self::$configs[$slug];
        }

        if (!isset(self::$inlineConfigs[$slug])) {
            self::$configs[$slug] = ['tabs' => []];
            return self::$configs[$slug];
        }

        self::$configs[$slug] = self::normalize(self::$inlineConfigs[$slug]);
        return self::$configs[$slug];
    }

    /**
     * Get a flat map of all fields keyed by field `id`.
     *
     * The returned array uses field IDs as keys for easy lookup.
     * Repeater subfields are included as `{parentId}.{subId}`.
     *
     * @param string $slug Config file slug.
     * @return array<string, array> Field ID → field config.
     */
    public static function flatFields(string $slug = 'settings'): array
    {
        if (isset(self::$flatFieldsCache[$slug])) {
            return self::$flatFieldsCache[$slug];
        }

        $config = self::load($slug);
        $fields = [];

        foreach ($config['tabs'] ?? [] as $tab) {
            foreach ($tab['sections'] ?? [] as $section) {
                foreach ($section['fields'] ?? [] as $field) {
                    $fieldId = $field['id'] ?? '';

                    if ($fieldId === '') {
                        continue;
                    }

                    $fields[$fieldId] = $field;

                    // Flatten repeater subfields.
                    if (($field['type'] ?? '') === 'repeater') {
                        $subfields = $field['args']['subfields'] ?? [];

                        foreach ($subfields as $subfield) {
                            $subId = $subfield['id'] ?? '';

                            if ($subId !== '') {
                                $fields["{$fieldId}.{$subId}"] = $subfield;
                            }
                        }
                    }
                }
            }
        }

        self::$flatFieldsCache[$slug] = $fields;
        return $fields;
    }

    /**
     * Clear all cached configs.
     */
    public static function reset(): void
    {
        self::$configs = [];
        self::$flatFieldsCache = [];
    }

    /**
     * Normalize any of the 3 input formats into the canonical structure.
     *
     * Canonical: ['tabs' => [['id' => ..., 'sections' => [['id' => ..., 'fields' => [...]]]]]]
     *
     * @param array $raw Raw config array from the PHP file.
     * @return array Normalized config.
     */
    private static function normalize(array $raw): array
    {
        // Preserve top-level metadata.
        $normalized = [
            'title'    => $raw['title'] ?? '',
            'subtitle' => $raw['subtitle'] ?? '',
        ];

        // Format 1: Fields only — wrap in default section + default tab.
        if (isset($raw['fields']) && !isset($raw['sections']) && !isset($raw['tabs'])) {
            $normalized['tabs'] = [
                [
                    'id'       => 'default',
                    'title'    => '',
                    'sections' => [
                        [
                            'id'     => 'default',
                            'title'  => '',
                            'fields' => $raw['fields'],
                        ],
                    ],
                ],
            ];

            return $normalized;
        }

        // Format 2: Sections only — wrap in default tab.
        if (isset($raw['sections']) && !isset($raw['tabs'])) {
            $normalized['tabs'] = [
                [
                    'id'       => 'default',
                    'title'    => '',
                    'sections' => $raw['sections'],
                ],
            ];

            return $normalized;
        }

        // Format 3: Full tabs.
        $normalized['tabs'] = $raw['tabs'] ?? [];

        return $normalized;
    }
}
