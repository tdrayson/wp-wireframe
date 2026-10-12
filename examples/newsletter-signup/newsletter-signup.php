<?php

/**
 * Plugin Name:       Newsletter Signup (Example)
 * Description:       WP Wireframe example — settings built with the fluent builder in a separate config file.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * Text Domain:       newsletter-signup
 */

declare(strict_types=1);

use Wireframe\Builder\Editor;
use Wireframe\Builder\Fields\ToggleField;

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/wp-wireframe/vendor/autoload.php';

add_action('init', function () {
    Wireframe\App::boot([
        'prefix'     => 'newsletter-signup',
        'page_title' => __('Newsletter Signup', 'newsletter-signup'),
        'option_key' => 'newsletter_signup_settings',
        'menu_icon'  => __DIR__ . '/assets/icon.svg',
        'config'     => __DIR__ . '/config/settings.php',
    ]);
});

// Free/pro demo: this filter would normally live in a separate Pro add-on.
// Injected fields render, validate and save like the page's own fields.
add_filter('newsletter-signup/config', function (Editor $config) {
    return $config->after(
        'list_id',
        ToggleField::make('double_opt_in')
            ->label(__('Double opt-in', 'newsletter-signup'))
            ->description(__('Added by the Pro add-on.', 'newsletter-signup'))
            ->conditions(['all' => [['field' => 'provider', 'operator' => 'not_equals', 'value' => 'none']]])
    );
});
