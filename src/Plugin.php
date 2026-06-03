<?php

declare(strict_types=1);

namespace Wireframe;

use Wireframe\Admin\AdminPage;
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
        add_filter('admin_body_class', [$this, 'addBodyClass']);
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
