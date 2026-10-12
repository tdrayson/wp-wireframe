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

// Demo handler for the `upload` + `action` pair on the Tools tab.
add_filter('field-reference/action/default/ref_csv_preview/run', function ($unhandled, array $values) {
    $file = $values['ref_csv_file'] ?? null;

    if (!is_array($file) || empty($file['content'])) {
        return ['status' => 'error', 'message' => __('Choose a CSV file first.', 'field-reference')];
    }

    [, $base64] = explode(',', $file['content'], 2);
    $lines = array_slice(preg_split('/\r\n|\n|\r/', trim((string) base64_decode($base64))), 0, 6);
    $rows  = array_map(static fn ($line) => '<tr><td>' . implode('</td><td>', array_map('esc_html', str_getcsv($line))) . '</td></tr>', $lines);

    return [
        'status'  => 'success',
        'message' => sprintf(__('Read %s (%s bytes).', 'field-reference'), $file['name'] ?? 'file', number_format_i18n((int) ($file['size'] ?? 0))),
        'html'    => '<table class="widefat striped">' . implode('', $rows) . '</table>',
    ];
}, 10, 2);
