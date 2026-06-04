<?php

declare(strict_types=1);

namespace Wireframe;

use Wireframe\Admin\AdminPage;
use Wireframe\Rest\ActionController;
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
        add_filter('admin_body_class', [$this, 'addBodyClass']);
        add_action('in_admin_header', [$this, 'suppressForeignNotices'], 1000);
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
