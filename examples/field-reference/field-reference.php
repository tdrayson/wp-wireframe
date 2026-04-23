<?php

/**
 * Plugin Name:       Field Reference (Example)
 * Description:       WP Wireframe example — kitchen-sink demo of every field type, plus a curated "Example" tab that reads like a real product settings page.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * Text Domain:       field-reference
 *
 * @package FieldReference
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/wp-wireframe/vendor/autoload.php';

// Demo data + callbacks for the `table` field.
require_once __DIR__ . '/demo/entries-store.php';

add_action('init', function () {
    Wireframe\App::boot([
        'prefix'     => 'field-reference',
        'page_title' => __('Field Reference', 'field-reference'),
        'option_key' => 'field_reference_settings',
        'menu_icon'  => 'dashicons-screenoptions',
        'config'     => __DIR__ . '/config/settings.php',
    ]);
});
