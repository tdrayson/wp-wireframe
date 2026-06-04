<?php

declare(strict_types=1);

namespace Wireframe\Admin;

use Wireframe\App;
use Wireframe\Framework\Access\AccessResolver;
use Wireframe\Framework\ConfigLoader;
use Wireframe\Settings;

/**
 * Registers admin menu pages, renders React mount points, and enqueues assets.
 *
 * Every consuming plugin's pages are iterated here; per-page prefix,
 * capability, and option key come from the page data itself.
 */
final class AdminPage
{
    /**
     * Register all configured admin menu pages across every booted plugin.
     *
     * Capability resolution:
     *  - Legacy mode (no `access` keys on the config tree): uses the page's
     *    declared capability — same as it has always done, defaulting to
     *    `manage_options`.
     *  - RBAC mode (at least one `access` key declared somewhere): drops the
     *    capability floor to `read` so any logged-in user can reach the page,
     *    then relies on per-element filtering to hide what they can't see.
     *    If the user has zero accessible elements, the menu is suppressed
     *    entirely so it doesn't appear in the sidebar at all.
     *
     * Menu placement:
     *  - Pages with a `parent` slug register as submenu items under that
     *    parent (e.g. `tools.php`, `options-general.php`). Bare aliases like
     *    `tools` or `settings` resolve to their canonical core slug.
     *  - Pages without a `parent` register as top-level menu items.
     */
    public static function register(): void
    {
        foreach (App::pages() as $internalId => $page) {
            $config = ConfigLoader::load($page['config']);
            $mode   = AccessResolver::pageMode($config);

            if ($mode === 'rbac') {
                $resolver = new AccessResolver($page['capability']);
                $map      = $resolver->resolveForConfig($config);

                if (!$map->hasAnyAccess()) {
                    continue;
                }

                $capability = 'read';
            } else {
                $capability = $page['capability'];
            }

            $callback = fn() => self::render($internalId);
            $parent   = self::resolveParent($page['parent'] ?? '');

            if ($parent !== '') {
                add_submenu_page(
                    $parent,
                    $page['page_title'],
                    $page['menu_title'],
                    $capability,
                    $page['menu_slug'],
                    $callback,
                    $page['menu_position']
                );

                continue;
            }

            add_menu_page(
                $page['page_title'],
                $page['menu_title'],
                $capability,
                $page['menu_slug'],
                $callback,
                $page['menu_icon'],
                $page['menu_position']
            );
        }
    }

    /**
     * True when the given screen / hook suffix belongs to a Wireframe page.
     *
     * WP always builds the screen ID as `{page_type}_page_{menu_slug}` (with
     * `page_type` being `toplevel` or the parent menu's hook). Matching on
     * the suffix `_page_{menu_slug}` is the leanest exact-ish check — the
     * underscore-bounded `_page_` separator can't be confused with any
     * other dash-delimited segment in a normal admin slug.
     */
    public static function isWireframeScreen(string $screenId): bool
    {
        foreach (App::pages() as $page) {
            if (str_ends_with($screenId, '_page_' . $page['menu_slug'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map common parent-menu aliases to their canonical WordPress slugs.
     *
     * Accepts a bare alias (`tools`, `settings`, `appearance`, `users`,
     * `dashboard`, `plugins`, `media`, `pages`, `posts`, `comments`) or any
     * explicit slug (`tools.php`, `edit.php?post_type=page`). Empty string
     * means "top-level menu".
     */
    private static function resolveParent(string $parent): string
    {
        $parent = trim($parent);

        if ($parent === '') {
            return '';
        }

        $aliases = [
            'dashboard'  => 'index.php',
            'posts'      => 'edit.php',
            'media'      => 'upload.php',
            'pages'      => 'edit.php?post_type=page',
            'comments'   => 'edit-comments.php',
            'appearance' => 'themes.php',
            'plugins'    => 'plugins.php',
            'users'      => 'users.php',
            'tools'      => 'tools.php',
            'settings'   => 'options-general.php',
            'options'    => 'options-general.php',
        ];

        return $aliases[strtolower($parent)] ?? $parent;
    }

    /**
     * Output the React mount point for a specific page.
     */
    public static function render(string $internalId): void
    {
        $page = App::page($internalId);

        if (!$page) {
            return;
        }

        printf(
            '<div id="%s" data-object-name="%s" data-prefix="%s" data-page-id="%s"></div>',
            esc_attr($page['menu_slug'] . '-app'),
            esc_attr(App::jsObjectName($page['prefix'], $page['page_id'])),
            esc_attr($page['prefix']),
            esc_attr($page['page_id'])
        );
    }

    /**
     * Enqueue scripts, styles, and localized data on matching admin pages.
     */
    public static function enqueueAssets(string $hookSuffix): void
    {
        $matchedId = self::matchPage($hookSuffix);

        if ($matchedId === null) {
            return;
        }

        $assetFile = App::assetsDir() . 'index.asset.php';

        if (!file_exists($assetFile)) {
            return;
        }

        $asset = require $assetFile;

        self::enqueueScriptsAndStyles($asset);
        self::enqueueWordPressEditors();
        self::enqueueExternalAssets($matchedId);
        self::localizeData($matchedId);
    }

    /**
     * Enqueue any external scripts/styles a page declared via its `assets` config.
     *
     * Each entry is shaped:
     *   [
     *       'handle'    => 'my-vendor-lib',         // required
     *       'src'       => 'https://cdn/lib.js',    // required
     *       'deps'      => ['jquery'],              // optional, default []
     *       'version'   => '1.2.3',                 // optional, falls back to page version
     *       'type'      => 'script' | 'style',      // optional, default 'script'
     *       'in_footer' => true,                    // scripts only, default true
     *       'media'     => 'all',                   // styles only, default 'all'
     *   ]
     */
    private static function enqueueExternalAssets(string $internalId): void
    {
        $page   = App::page($internalId);
        $assets = $page['assets'] ?? [];

        /**
         * Filter the external assets enqueued on this page.
         *
         * Hook name uses the page's prefix (e.g. `my-plugin/assets`) so each
         * consuming plugin owns its own namespace. Return an array of asset
         * entries shaped the same as the static `assets` config.
         *
         * @param array $assets Asset entries already declared in page config.
         * @param array $page   The matched page definition.
         */
        $assets = apply_filters(App::hookName($page['prefix'], 'assets'), $assets, $page);

        if (!is_array($assets) || $assets === []) {
            return;
        }

        foreach ($assets as $asset) {
            if (!is_array($asset) || empty($asset['handle']) || empty($asset['src'])) {
                continue;
            }

            $handle  = (string) $asset['handle'];
            $src     = (string) $asset['src'];
            $deps    = isset($asset['deps']) && is_array($asset['deps']) ? $asset['deps'] : [];
            $version = $asset['version'] ?? $page['version'];
            $type    = ($asset['type'] ?? 'script') === 'style' ? 'style' : 'script';

            if ($type === 'style') {
                if (wp_style_is($handle, 'enqueued')) {
                    continue;
                }

                wp_enqueue_style($handle, $src, $deps, $version, (string) ($asset['media'] ?? 'all'));
                continue;
            }

            if (wp_script_is($handle, 'enqueued')) {
                continue;
            }

            wp_enqueue_script($handle, $src, $deps, $version, (bool) ($asset['in_footer'] ?? true));
        }
    }

    /**
     * Find which internal page ID matches the current admin hook suffix.
     *
     * Anchored on the `_page_{menu_slug}` suffix so sibling subpages whose
     * own slug starts with a Wireframe page's slug don't false-positive
     * (e.g. an `example-plugin-diagnostics` subpage being mistaken for
     * the `example-plugin` Wireframe page).
     */
    private static function matchPage(string $hookSuffix): ?string
    {
        foreach (App::pages() as $internalId => $page) {
            if (str_ends_with($hookSuffix, '_page_' . $page['menu_slug'])) {
                return $internalId;
            }
        }

        return null;
    }

    /**
     * Enqueue the compiled React bundle and stylesheet — shared across every plugin.
     *
     * @param array{dependencies: string[], version: string} $asset Build asset manifest.
     */
    private static function enqueueScriptsAndStyles(array $asset): void
    {
        $handle    = App::assetHandle();
        $assetsUrl = App::assetsUrl();

        if (wp_script_is($handle, 'enqueued')) {
            return;
        }

        wp_enqueue_script(
            $handle,
            $assetsUrl . 'index.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        // wp-components ships a stylesheet that we depend on;
        // wp-dataviews is bundled into our JS, so its CSS is imported
        // directly in the JS entry and emitted into our own index.css.
        wp_enqueue_style(
            $handle,
            $assetsUrl . 'index.css',
            ['wp-components'],
            $asset['version']
        );

        wp_set_script_translations(
            $handle,
            'wp-wireframe',
            App::packageDir() . 'languages'
        );
    }

    /**
     * Enqueue WordPress editor assets (TinyMCE, CodeMirror, Media Library).
     */
    private static function enqueueWordPressEditors(): void
    {
        wp_enqueue_editor();

        $codeEditorSettings = wp_enqueue_code_editor(['type' => 'text/css']);

        if ($codeEditorSettings !== false) {
            wp_add_inline_script(
                'code-editor',
                sprintf('wp.codeEditor.defaultSettings = %s;', wp_json_encode($codeEditorSettings))
            );
        }

        wp_enqueue_media();
    }

    /**
     * Localize config + saved values for the matched page.
     *
     * The config is filtered through the user's AccessMap before being sent
     * to the React frontend so the browser never sees fields the user
     * isn't allowed to view. The `wp-wireframe/config/for_user` filter is
     * the developer hook for further mutating the config per-request.
     */
    private static function localizeData(string $internalId): void
    {
        $page       = App::page($internalId);
        $optionKey  = $page['option_key'];
        $configSlug = $page['config'];
        $prefix     = $page['prefix'];
        $pageId     = $page['page_id'];

        $config   = ConfigLoader::load($configSlug);
        $resolver = new AccessResolver($page['capability']);
        $map      = $resolver->resolveForConfig($config);
        $config   = $resolver->filterConfig($config, $map);

        /**
         * Filter the per-user config just before it ships to the browser.
         *
         * Use this hook to mutate the config based on the current user (e.g.
         * append extra fields for trusted roles, alter labels, etc.) without
         * having to fork the original config array.
         *
         * @param array          $config Filtered config (already had non-viewable elements stripped).
         * @param string         $pageId Page identifier (matches the REST route segment).
         * @param ConfigAccessMap $map    The access map used to produce $config.
         */
        $config = apply_filters('wp-wireframe/config/for_user', $config, $pageId, $map);

        wp_localize_script(App::assetHandle(), App::jsObjectName($prefix, $pageId), [
            'config'   => $config,
            'values'   => Settings::resolvedFor($optionKey, $configSlug),
            'hasSaved' => Settings::existsFor($optionKey),
            'canSave'  => !empty($map->editable),
            'canReset' => $map->canReset && !empty($map->editable),
            'restUrl'  => rest_url(App::restNamespace($prefix) . '/'),
            'nonce'    => wp_create_nonce('wp_rest'),
            'version'  => $page['version'],
            'prefix'   => $prefix,
            'pageId'   => $pageId,
        ]);
    }
}
