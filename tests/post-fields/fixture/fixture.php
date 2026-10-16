<?php

/**
 * Plugin Name: WF Fixture
 * Description: Test fixture for WP Wireframe post fields.
 */

declare(strict_types=1);

require_once WP_PLUGIN_DIR . '/wp-wireframe/vendor/autoload.php';

add_action('init', static function (): void {
    register_post_type('event', [
        'label'        => 'Events',
        'public'       => true,
        'show_in_rest' => true,
        'supports'     => ['title', 'thumbnail', 'revisions'],
    ]);

    register_taxonomy('event_type', 'event', [
        'label'        => 'Event types',
        'hierarchical' => true,
        'show_in_rest' => true,
    ]);

    Wireframe\App::boot([
        'prefix'       => 'wf-fixture',
        'assets_url'   => plugins_url('wp-wireframe/src/assets/'),
        'field_groups' => [
            [
                'id'         => 'event_details',
                'post_types' => ['event'],
                'config'     => __DIR__ . '/config/event.php',
            ],
        ],
    ]);
});
