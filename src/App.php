<?php

declare(strict_types=1);

namespace Wireframe;

use Wireframe\Framework\ConfigLoader;

/**
 * Central bootstrap for WP Wireframe.
 *
 * Multi-tenant: any number of consuming plugins can call `App::boot()`
 * against the same shared package. Each boot registers its own pages,
 * hooks, REST endpoints, and option keys under its prefix.
 *
 * Single page with an inline config:
 *   App::boot([
 *       'prefix'     => 'my-plugin',
 *       'page_title' => 'My Plugin',
 *       'config'     => ['tabs' => [...]],
 *   ]);
 *
 * Single page loaded from a file:
 *   App::boot([
 *       'prefix' => 'my-plugin',
 *       'config' => __DIR__ . '/config/settings.php',
 *   ]);
 *
 * Multi-page:
 *   App::boot([
 *       'prefix' => 'my-plugin',
 *       'pages'  => [
 *           ['page_title' => 'General',  'config' => __DIR__ . '/config/general.php'],
 *           ['page_title' => 'Advanced', 'config' => ['fields' => [...]]],
 *       ],
 *   ]);
 */
final class App
{
    /** @var array<string, array> Registered pages keyed by internal ID (`{prefix}__{pageId}`). */
    private static array $pages = [];

    /** @var array<string, string> option_key → config slug lookup, populated as pages are registered. */
    private static array $optionKeyToConfig = [];

    /** @var string Wp-wireframe package directory (resolved once). */
    private static string $packageDir = '';

    /**
     * Optional override for the admin-asset URL base. Consumers can pass
     * `assets_url` to boot() when the package lives outside WP_PLUGIN_DIR —
     * e.g. symlinked into vendor/ during local development, where __DIR__
     * resolves to the real target and plugins_url() can't derive the URL.
     */
    private static string $assetsUrl = '';

    /**
     * Bootstrap the framework for one consuming plugin.
     *
     * Safe to call many times from different plugins; each call registers
     * pages under its own prefix. WP hooks are wired only once (singleton).
     */
    public static function boot(array $config = []): void
    {
        if (self::$packageDir === '') {
            self::$packageDir = dirname(__DIR__) . '/';
        }

        if (self::$assetsUrl === '' && !empty($config['assets_url'])) {
            self::$assetsUrl = trailingslashit((string) $config['assets_url']);
        }

        $prefix = $config['prefix'] ?? 'wireframe';

        $perBoot = [
            'prefix'     => $prefix,
            'version'    => $config['version'] ?? '1.0.0',
            'capability' => $config['capability'] ?? 'manage_options',
            'option_key' => $config['option_key'] ?? str_replace('-', '_', $prefix) . '_settings',
        ];

        self::resolvePages($config, $perBoot);

        Plugin::instance();
    }

    /**
     * Resolve pages from a boot config and add them to the registry.
     *
     * @param array $config  The raw `boot()` config.
     * @param array $perBoot Per-plugin metadata (prefix, version, capability, option_key).
     */
    private static function resolvePages(array $config, array $perBoot): void
    {
        $prefix = $perBoot['prefix'];

        if (!empty($config['pages']) && is_array($config['pages'])) {
            foreach ($config['pages'] as $index => $pageConfig) {
                $rawConfig = $pageConfig['config'] ?? [];
                $fallbackSlug = $pageConfig['id']
                    ?? (is_string($rawConfig) && $rawConfig !== '' ? basename($rawConfig, '.php') : "page-{$index}");
                $configSlug = self::resolveConfigSlug($rawConfig, $prefix . '-' . $fallbackSlug);
                $pageId     = $pageConfig['id'] ?? $fallbackSlug;
                $internalId = self::internalId($prefix, $pageId);

                $optionKey = $pageConfig['option_key'] ?? $perBoot['option_key'] . '_' . str_replace('-', '_', $pageId);

                self::$pages[$internalId] = $perBoot + [
                    'page_id'       => $pageId,
                    'page_title'    => $pageConfig['page_title'] ?? ucfirst($pageId),
                    'menu_title'    => $pageConfig['menu_title'] ?? $pageConfig['page_title'] ?? ucfirst($pageId),
                    'menu_icon'     => $pageConfig['menu_icon'] ?? 'dashicons-admin-generic',
                    'menu_position' => $pageConfig['menu_position'] ?? (80 + $index),
                    'config'        => $configSlug,
                    'option_key'    => $optionKey,
                    'menu_slug'     => $pageConfig['menu_slug'] ?? $prefix . '-' . $pageId,
                ];

                self::$optionKeyToConfig[$optionKey] = $configSlug;
            }
        } else {
            $rawConfig  = $config['config'] ?? [];
            $configSlug = self::resolveConfigSlug($rawConfig, $prefix);
            $pageId     = 'default';
            $internalId = self::internalId($prefix, $pageId);

            self::$pages[$internalId] = $perBoot + [
                'page_id'       => $pageId,
                'page_title'    => $config['page_title'] ?? ucfirst($prefix),
                'menu_title'    => $config['menu_title'] ?? $config['page_title'] ?? ucfirst($prefix),
                'menu_icon'     => $config['menu_icon'] ?? 'dashicons-admin-generic',
                'menu_position' => $config['menu_position'] ?? 80,
                'config'        => $configSlug,
                'option_key'    => $perBoot['option_key'],
                'menu_slug'     => $prefix,
            ];

            self::$optionKeyToConfig[$perBoot['option_key']] = $configSlug;
        }
    }

    /**
     * Look up the config slug registered for a given wp_options key.
     *
     * Returns 'settings' if no mapping exists (e.g. Settings:: called
     * before boot, or for an arbitrary option key).
     */
    public static function configSlugForOptionKey(string $optionKey): string
    {
        return self::$optionKeyToConfig[$optionKey] ?? 'settings';
    }

    /**
     * Build the internal page key. Callers never parse this — treat as opaque.
     */
    private static function internalId(string $prefix, string $pageId): string
    {
        return $prefix . '__' . $pageId;
    }

    /**
     * Resolve a page's `config` option to a slug, registering the config
     * array with the ConfigLoader. Accepts an inline array or a file path.
     */
    private static function resolveConfigSlug(array|string $rawConfig, string $fallbackSlug): string
    {
        $slug = preg_replace('/[^a-z0-9_-]/i', '-', $fallbackSlug) ?: 'settings';

        if (is_array($rawConfig)) {
            ConfigLoader::register($slug, $rawConfig);
            return $slug;
        }

        if (is_file($rawConfig)) {
            $loaded = require $rawConfig;

            if (is_array($loaded)) {
                ConfigLoader::register($slug, $loaded);
            }
        }

        return $slug;
    }

    // ─── Page lookup ───────────────────────────────────

    /** @return array<string, array> Internal ID → page config. */
    public static function pages(): array
    {
        return self::$pages;
    }

    public static function page(string $internalId): ?array
    {
        return self::$pages[$internalId] ?? null;
    }

    // ─── Per-plugin derived identifiers ────────────────

    /** REST API namespace for a given prefix (e.g. "my-plugin/v1"). */
    public static function restNamespace(string $prefix): string
    {
        return $prefix . '/v1';
    }

    /** Build a hook name for a given prefix (e.g. "my-plugin/settings_saved"). */
    public static function hookName(string $prefix, string $hook): string
    {
        return $prefix . '/' . $hook;
    }

    /** Text domain for a given prefix (defaults to the prefix itself). */
    public static function textDomain(string $prefix): string
    {
        return $prefix;
    }

    /** JS global object name for a given prefix + page (e.g. "myPluginSettingsData"). */
    public static function jsObjectName(string $prefix, string $pageId = 'default'): string
    {
        $slug = $prefix . ($pageId === 'default' ? '' : '-' . $pageId);
        $parts = array_filter(explode('-', $slug), 'strlen');
        $first = array_shift($parts) ?? 'wireframe';
        $camel = $first . implode('', array_map('ucfirst', $parts));

        return $camel . 'Data';
    }

    // ─── Global identifiers & paths ────────────────────

    /** Script/style handle for the shared admin bundle. Same for every consumer. */
    public static function assetHandle(): string
    {
        return 'wp-wireframe-admin';
    }

    public static function packageDir(): string
    {
        return self::$packageDir !== '' ? self::$packageDir : dirname(__DIR__) . '/';
    }

    public static function assetsDir(): string
    {
        return self::packageDir() . 'src/assets/';
    }

    public static function assetsUrl(): string
    {
        if (self::$assetsUrl !== '') {
            return self::$assetsUrl;
        }

        $packageDir = self::normalizePath(self::packageDir());

        if ($packageDir === '') {
            return '';
        }

        foreach (self::contentRoots() as [$dir, $urlBase]) {
            $realDir = self::normalizePath($dir);

            if ($realDir === '' || !str_starts_with($packageDir, $realDir)) {
                continue;
            }

            $relative = substr($packageDir, strlen($realDir));

            return trailingslashit($urlBase) . $relative . 'src/assets/';
        }

        return '';
    }

    /**
     * Known content roots paired with their public URL base.
     *
     * Checked in priority order by `assetsUrl()`; the first root that contains
     * the package wins. Themes cover most cases; `WP_CONTENT_DIR` is the catch
     * for anything else (mu-plugins sub-dirs, drop-ins, unusual layouts).
     *
     * @return array<int, array{0:string, 1:string}>
     */
    private static function contentRoots(): array
    {
        $roots = [];

        if (defined('WP_PLUGIN_DIR')) {
            $roots[] = [WP_PLUGIN_DIR, plugins_url()];
        }

        if (defined('WPMU_PLUGIN_DIR') && defined('WPMU_PLUGIN_URL')) {
            $roots[] = [WPMU_PLUGIN_DIR, WPMU_PLUGIN_URL];
        }

        if (function_exists('get_stylesheet_directory')) {
            $roots[] = [get_stylesheet_directory(), get_stylesheet_directory_uri()];
        }

        if (function_exists('get_template_directory')) {
            $roots[] = [get_template_directory(), get_template_directory_uri()];
        }

        if (defined('WP_CONTENT_DIR') && function_exists('content_url')) {
            $roots[] = [WP_CONTENT_DIR, content_url()];
        }

        return $roots;
    }

    /**
     * Canonicalise a filesystem path with a trailing slash.
     *
     * `realpath()` resolves symlinks on both ends of the comparison so an
     * install where the package (or WP itself) lives behind a symlink still
     * matches. Returns an empty string if the path cannot be resolved.
     */
    private static function normalizePath(string $path): string
    {
        $real = realpath($path);

        if ($real === false) {
            return '';
        }

        return rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }
}
