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
     * Legacy mode (no `access` keys on the config tree): uses the page's
     * declared capability — same as it has always done, defaulting to
     * `manage_options`.
     *
     * RBAC mode (at least one `access` key declared somewhere): drops the
     * capability floor to `read` so any logged-in user can reach the page,
     * then relies on per-element filtering to hide what they can't see.
     * If the user has zero accessible elements, the menu is suppressed
     * entirely so it doesn't appear in the sidebar at all.
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

            add_menu_page(
                $page['page_title'],
                $page['menu_title'],
                $capability,
                $page['menu_slug'],
                fn() => self::render($internalId),
                $page['menu_icon'],
                $page['menu_position']
            );
        }
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
        self::localizeData($matchedId);
    }

    /**
     * Find which internal page ID matches the current admin hook suffix.
     */
    private static function matchPage(string $hookSuffix): ?string
    {
        foreach (App::pages() as $internalId => $page) {
            if (str_contains($hookSuffix, $page['menu_slug'])) {
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
