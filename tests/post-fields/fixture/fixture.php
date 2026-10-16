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

    register_post_type('classic_event', [
        'label'        => 'Classic events',
        'public'       => true,
        'show_in_rest' => true,
        'supports'     => ['title', 'thumbnail', 'revisions'],
    ]);

    register_post_type('speaker', [
        'label'        => 'Speakers',
        'public'       => true,
        'show_in_rest' => true,
        'supports'     => ['title'],
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
                'location' => ['event', 'classic_event'],
                'display'  => ['position' => 'content'],
                'config'   => __DIR__ . '/config/event.php',
            ],
            [
                'id'       => 'event_seo',
                'title'    => 'SEO',
                'location' => ['all' => [['field' => 'post_type', 'operator' => 'in', 'value' => ['event', 'classic_event']]]],
                'display'  => ['position' => 'sidebar', 'collapsed' => true, 'order' => 20],
                'config'   => __DIR__ . '/config/seo.php',
            ],
            [
                'id'       => 'event_extras',
                'title'    => 'Extras',
                'location' => ['all' => [
                    ['field' => 'post_type', 'operator' => 'in', 'value' => ['event', 'classic_event']],
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

add_filter('use_block_editor_for_post_type', static fn (bool $use, string $postType): bool => $postType === 'classic_event' ? false : $use, 10, 2);
