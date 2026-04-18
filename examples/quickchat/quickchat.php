<?php

/**
 * Plugin Name:       QuickChat (Example)
 * Description:       WP Wireframe example — a SaaS-style chat plugin with its entire config in a single file.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * Text Domain:       quickchat
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/wp-wireframe/vendor/autoload.php';

add_action('init', function () {
    Wireframe\App::boot([
        'prefix'     => 'quickchat',
        'page_title' => __('QuickChat', 'quickchat'),
        'option_key' => 'quickchat_settings',
        'menu_icon'  => 'dashicons-format-status',
        'config'     => [
            'title'    => __('QuickChat', 'quickchat'),
            'subtitle' => __('Drop-in support chat for your site.', 'quickchat'),
            'sections' => [
                [
                    'id'          => 'connection',
                    'title'       => __('Connect your workspace', 'quickchat'),
                    'description' => __('Find these in your QuickChat dashboard under Settings → API.', 'quickchat'),
                    'fields'      => [
                        [
                            'id'       => 'workspace_id',
                            'type'     => 'text',
                            'label'    => __('Workspace ID', 'quickchat'),
                            'required' => true,
                            'columns'  => 6,
                            'args'     => ['placeholder' => 'ws_...'],
                        ],
                        [
                            'id'       => 'api_key',
                            'type'     => 'password',
                            'label'    => __('API key', 'quickchat'),
                            'required' => true,
                            'columns'  => 6,
                            'args'     => ['placeholder' => 'sk_...'],
                        ],
                    ],
                ],
                [
                    'id'     => 'appearance',
                    'title'  => __('Appearance', 'quickchat'),
                    'fields' => [
                        [
                            'id'      => 'accent_color',
                            'type'    => 'color',
                            'label'   => __('Accent color', 'quickchat'),
                            'default' => '#3858e9',
                            'columns' => 6,
                        ],
                        [
                            'id'      => 'position',
                            'type'    => 'select',
                            'label'   => __('Position', 'quickchat'),
                            'default' => 'bottom-right',
                            'columns' => 6,
                            'args'    => [
                                'options' => [
                                    'bottom-right' => __('Bottom right', 'quickchat'),
                                    'bottom-left'  => __('Bottom left', 'quickchat'),
                                ],
                            ],
                        ],
                        [
                            'id'          => 'greeting',
                            'type'        => 'textarea',
                            'label'       => __('Greeting message', 'quickchat'),
                            'description' => __('Shown when the widget opens.', 'quickchat'),
                            'default'     => __('Hi! How can we help?', 'quickchat'),
                            'args'        => ['rows' => 3],
                        ],
                    ],
                ],
                [
                    'id'     => 'behaviour',
                    'title'  => __('Behaviour', 'quickchat'),
                    'fields' => [
                        [
                            'id'      => 'show_on_mobile',
                            'type'    => 'toggle',
                            'label'   => __('Show on mobile', 'quickchat'),
                            'default' => true,
                            'columns' => 6,
                        ],
                        [
                            'id'      => 'hide_for_logged_in',
                            'type'    => 'toggle',
                            'label'   => __('Hide for logged-in users', 'quickchat'),
                            'default' => false,
                            'columns' => 6,
                        ],
                        [
                            'id'          => 'delay_seconds',
                            'type'        => 'number',
                            'label'       => __('Delay before showing (seconds)', 'quickchat'),
                            'default'     => 3,
                            'columns'     => 6,
                            'args'        => ['min' => 0, 'max' => 60],
                        ],
                    ],
                ],
            ],
        ],
    ]);
});
