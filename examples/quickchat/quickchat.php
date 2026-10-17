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
        'menu_icon'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M3 3h14a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H8l-4 3v-3H3a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zm3 5a1 1 0 1 0 0 2 1 1 0 0 0 0-2zm4 0a1 1 0 1 0 0 2 1 1 0 0 0 0-2zm4 0a1 1 0 1 0 0 2 1 1 0 0 0 0-2z"/></svg>',
        'config'     => [
            'title'    => __('QuickChat', 'quickchat'),
            'subtitle' => __('Drop-in support chat for your site.', 'quickchat'),
            // Default:`false` saves all options in a single wp_option value as an array.
	        // `true` saves each option individually using the field's id as the wp_options key.
	        // Or configure specific fields with `option_name` to save only those as individual wp_options.
            'individual_options' => true,
            'sections' => [
                [
                    'id'          => 'connection',
                    'title'       => __('Connect your workspace', 'quickchat'),
                    'description' => __('Find these in your QuickChat dashboard under Settings → API.', 'quickchat'),
                    'fields'      => [
                        [
                            'id'       => 'quickchat_workspace_id',
                            'type'     => 'text',
                            'label'    => __('Workspace ID', 'quickchat'),
                            'required' => true,
                            'columns'  => 6,
                            'args'     => ['placeholder' => 'ws_...'],
                        ],
                        [
                            'id'       => 'quickchat_api_key',
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
                            'id'      => 'quickchat_accent_color',
                            'type'    => 'color',
                            'label'   => __('Accent color', 'quickchat'),
                            'default' => '#3858e9',
                            'columns' => 6,
                        ],
                        [
                            'id'      => 'quickchat_position',
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
                            'id'          => 'quickchat_greeting',
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
                            'id'      => 'quickchat_show_on_mobile',
                            'type'    => 'toggle',
                            'label'   => __('Show on mobile', 'quickchat'),
                            'default' => true,
                            'columns' => 6,
                        ],
                        [
                            'id'      => 'quickchat_hide_for_logged_in',
                            'type'    => 'toggle',
                            'label'   => __('Hide for logged-in users', 'quickchat'),
                            'default' => false,
                            'columns' => 6,
                        ],
                        [
                            'id'          => 'quickchat_delay_seconds',
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
