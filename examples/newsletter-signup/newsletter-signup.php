<?php

/**
 * Plugin Name:       Newsletter Signup (Example)
 * Description:       WP Wireframe example — settings loaded from a separate config file.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * Text Domain:       newsletter-signup
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/wp-wireframe/vendor/autoload.php';

add_action('init', function () {
    Wireframe\App::boot([
        'prefix'     => 'newsletter-signup',
        'page_title' => __('Newsletter Signup', 'newsletter-signup'),
        'option_key' => 'newsletter_signup_settings',
        'menu_icon'  => 'dashicons-email',
        'config'     => __DIR__ . '/config/settings.php',
    ]);
});
