<?php

declare(strict_types=1);

namespace Wireframe;

use Wireframe\Admin\AdminPage;
use Wireframe\Framework\ConfigValidator;
use Wireframe\PostFields\Aliases;
use Wireframe\PostFields\Bindings;
use Wireframe\PostFields\ClassicEditor;
use Wireframe\PostFields\ClassicSave;
use Wireframe\PostFields\Cli\MigrateCommand;
use Wireframe\PostFields\Cli\ReindexCommand;
use Wireframe\PostFields\Columns;
use Wireframe\PostFields\DottedMeta;
use Wireframe\PostFields\EditorIntegration;
use Wireframe\PostFields\FieldGroups;
use Wireframe\PostFields\Index\IndexSync;
use Wireframe\PostFields\Index\IndexTable;
use Wireframe\PostFields\Index\MetaQueryBridge;
use Wireframe\PostFields\Index\QueryClauses;
use Wireframe\PostFields\MetaRegistrar;
use Wireframe\Rest\ActionController;
use Wireframe\Rest\PopulateController;
use Wireframe\Rest\SettingsController;
use Wireframe\Rest\TableController;

/**
 * Registers WordPress hooks for the admin page and REST API.
 *
 * Instantiated once by App::boot().
 */
final class Plugin
{
    /** @var self|null Singleton instance. */
    private static ?self $instance = null;

    /**
     * Get or create the singleton instance.
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->registerHooks();
    }

    /**
     * Wire up all WordPress hooks.
     */
    private function registerHooks(): void
    {
        add_action('admin_menu', [AdminPage::class, 'register']);
        add_action('admin_enqueue_scripts', [AdminPage::class, 'enqueueAssets']);
        add_action('rest_api_init', [SettingsController::class, 'register']);
        add_action('rest_api_init', [TableController::class, 'register']);
        add_action('rest_api_init', [ActionController::class, 'register']);
        add_action('rest_api_init', [PopulateController::class, 'register']);
        add_action('wp_loaded', [FieldGroups::class, 'boot'], 0);
        add_action('wp_loaded', [MetaRegistrar::class, 'register'], 1);
        add_filter('rest_request_before_callbacks', [MetaRegistrar::class, 'beginRequest']);
        add_filter('rest_request_after_callbacks', [MetaRegistrar::class, 'endRequest']);
        add_action('wp_loaded', [IndexTable::class, 'install'], 2);
        add_filter('wpmu_drop_tables', [IndexTable::class, 'dropWithSite']);
        IndexSync::register();
        Aliases::register();
        // App::boot() may itself run inside `init`, after its callbacks have fired.
        if (did_action('init')) {
            Bindings::register();
        } else {
            add_action('init', [Bindings::class, 'register']);
        }
        // Late, so a wireframe_query added by other pre_get_posts callbacks still lifts suppress_filters.
        add_action('pre_get_posts', [QueryClauses::class, 'prepare'], 1000);
        add_filter('posts_clauses', [QueryClauses::class, 'apply'], 10, 2);
        add_action('pre_get_posts', [MetaQueryBridge::class, 'prepare'], 1000);
        // Early, as it rebuilds the SQL from the queries; later get_meta_sql filters see the result.
        add_filter('get_meta_sql', [MetaQueryBridge::class, 'rewrite'], 1, 6);
        add_filter(MetaQueryBridge::OWNER_FILTER, [MetaQueryBridge::class, 'claim'], 10, 3);
        add_filter('posts_clauses', [MetaQueryBridge::class, 'orderby'], 10, 2);
        add_filter('get_post_metadata', [DottedMeta::class, 'read'], 10, 4);
        ReindexCommand::register();
        MigrateCommand::register();
        add_filter('is_protected_meta', [MetaRegistrar::class, 'protect'], 10, 3);
        add_filter('register_post_type_args', [EditorIntegration::class, 'filterPostTypeArgs'], 10, 2);
        add_filter('block_editor_settings_all', [EditorIntegration::class, 'filterEditorSettings'], 10, 2);
        add_action('enqueue_block_editor_assets', [EditorIntegration::class, 'enqueue']);
        add_action('save_post', [EditorIntegration::class, 'flush']);
        add_action('add_meta_boxes', [EditorIntegration::class, 'registerMetaBoxes'], 10, 2);
        ClassicEditor::register();
        ClassicSave::register();
        add_action('admin_init', [Columns::class, 'register']);
        add_filter('admin_body_class', [$this, 'addBodyClass']);
        add_action('in_admin_header', [$this, 'suppressForeignNotices'], 1000);

        // Surface config authoring mistakes during development only.
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_action('admin_notices', [ConfigValidator::class, 'adminNotice']);
        }
    }

    /**
     * Suppress third-party admin notices on Wireframe-managed pages.
     *
     * Plugins commonly stack notices on every admin screen via
     * `admin_notices` / `all_admin_notices`. They clutter our settings page
     * and push the Wireframe header down. We register on `in_admin_header`
     * with high priority so the `do_action('admin_notices')` call in
     * `wp-admin/admin-header.php` runs against an empty handler list.
     *
     * The behaviour is filterable — return false from
     * `wp-wireframe/suppress_admin_notices` to opt out (e.g. for a critical
     * banner you want to keep visible everywhere).
     */
    public function suppressForeignNotices(): void
    {
        $screen = get_current_screen();

        if (! $screen || ! AdminPage::isWireframeScreen($screen->id)) {
            return;
        }

        /**
         * Filter: should foreign admin notices be suppressed on Wireframe pages?
         *
         * @param bool      $suppress Default true.
         * @param \WP_Screen $screen   The current screen.
         */
        $suppress = (bool) apply_filters('wp-wireframe/suppress_admin_notices', true, $screen);

        if (! $suppress) {
            return;
        }

        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
        remove_all_actions('user_admin_notices');
        remove_all_actions('network_admin_notices');
    }

    /**
     * Add a consistent body class to Wireframe-managed pages so styles
     * can target them regardless of the consuming plugin's prefix.
     *
     * Uses the `_page_{menu_slug}` suffix check from
     * `AdminPage::isWireframeScreen()`. Previous versions used
     * `str_contains($screen->id, $menu_slug)`, which false-positived on
     * sibling subpages whose own slug started with a Wireframe page's
     * slug, applying `wireframe-admin` (and its `#wpcontent
     * { padding-left: 0 }` reset) to admin screens that aren't ours.
     *
     * @param string $classes Existing admin body classes.
     * @return string
     */
    public function addBodyClass(string $classes): string
    {
        $screen = get_current_screen();

        if (! $screen) {
            return $classes;
        }

        if (AdminPage::isWireframeScreen($screen->id)) {
            $classes .= ' wireframe-admin';
        }

        return $classes;
    }
}
