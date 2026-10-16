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
                'id'       => 'event_details',
                'location' => ['event'],
                'display'  => ['position' => 'content'],
                'config'   => __DIR__ . '/config/event.php',
            ],
            [
                'id'       => 'event_seo',
                'title'    => 'SEO',
                'location' => ['all' => [['field' => 'post_type', 'operator' => 'equals', 'value' => 'event']]],
                'display'  => ['position' => 'sidebar', 'collapsed' => true, 'order' => 20],
                'config'   => __DIR__ . '/config/seo.php',
            ],
            [
                'id'       => 'event_extras',
                'title'    => 'Extras',
                'location' => ['all' => [
                    ['field' => 'post_type', 'operator' => 'equals', 'value' => 'event'],
                    ['field' => 'user_role', 'operator' => 'contains', 'value' => 'administrator'],
                ]],
                'display'  => ['position' => 'meta_box'],
                'config'   => ['fields' => [['id' => 'extra_note', 'type' => 'textarea', 'label' => 'Note']]],
            ],
            [
                'id'       => 'post_extras',
                'title'    => 'Post extras',
                'location' => ['post'],
                'display'  => ['position' => 'meta_box'],
                'config'   => ['fields' => [['id' => 'post_subtitle', 'type' => 'text', 'label' => 'Subtitle']]],
            ],
        ],
    ]);
});
