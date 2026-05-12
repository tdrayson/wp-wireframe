<?php

declare(strict_types=1);

namespace Wireframe\Admin;

use Wireframe\App;
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
     * Pages with a `parent` slug register as submenu items under that parent
     * (e.g. `tools.php`, `options-general.php`). Bare aliases like `tools`
     * or `settings` resolve to their canonical core slug.
     */
    public static function register(): void
    {
        foreach (App::pages() as $internalId => $page) {
            $callback = fn() => self::render($internalId);
            $parent   = self::resolveParent($page['parent'] ?? '');

            if ($parent !== '') {
                add_submenu_page(
                    $parent,
                    $page['page_title'],
                    $page['menu_title'],
                    $page['capability'],
                    $page['menu_slug'],
                    $callback,
                    $page['menu_position']
                );

                continue;
            }

            add_menu_page(
                $page['page_title'],
                $page['menu_title'],
                $page['capability'],
                $page['menu_slug'],
                $callback,
                $page['menu_icon'],
                $page['menu_position']
            );
        }
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
     */
    private static function localizeData(string $internalId): void
    {
        $page       = App::page($internalId);
        $optionKey  = $page['option_key'];
        $configSlug = $page['config'];
        $prefix     = $page['prefix'];
        $pageId     = $page['page_id'];

        wp_localize_script(App::assetHandle(), App::jsObjectName($prefix, $pageId), [
            'config'   => ConfigLoader::load($configSlug),
            'values'   => Settings::resolvedFor($optionKey, $configSlug),
            'hasSaved' => Settings::existsFor($optionKey),
            'restUrl'  => rest_url(App::restNamespace($prefix) . '/'),
            'nonce'    => wp_create_nonce('wp_rest'),
            'version'  => $page['version'],
            'prefix'   => $prefix,
            'pageId'   => $pageId,
        ]);
    }
}
