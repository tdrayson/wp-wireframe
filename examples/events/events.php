<?php

/**
 * Plugin Name:       Events (Example)
 * Description:       WP Wireframe example — an `event` post type with one field group in each display position, an indexed repeater, and an `[upcoming_events]` shortcode that queries it with plain WP_Query.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * Text Domain:       events
 *
 * @package Events
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/wp-wireframe/vendor/autoload.php';

add_action('init', function () {
    register_post_type('event', [
        'label'        => __('Events', 'events'),
        'public'       => true,
        'menu_icon'    => 'dashicons-calendar-alt',
        'show_in_rest' => true,
        'supports'     => ['title', 'thumbnail', 'revisions'],
    ]);

    Wireframe\App::boot([
        'prefix'       => 'events',
        'field_groups' => [
            [
                'id'       => 'event_details',
                'title'    => __('Event details', 'events'),
                'location' => ['event'],
                'display'  => ['position' => 'content'],
                'config'   => __DIR__ . '/config/event.php',
            ],
            [
                'id'       => 'event_organiser',
                'title'    => __('Organiser', 'events'),
                'location' => ['event'],
                'display'  => ['position' => 'meta_box', 'collapsed' => true],
                'config'   => [
                    'fields' => [
                        ['id' => 'event_organiser', 'type' => 'text', 'label' => __('Organiser', 'events'), 'columns' => 6],
                        ['id' => 'event_contact', 'type' => 'email', 'label' => __('Contact email', 'events'), 'columns' => 6],
                        ['id' => 'event_ticket_url', 'type' => 'url', 'label' => __('Ticket URL', 'events'), 'public' => true],
                    ],
                ],
            ],
            [
                'id'       => 'event_promotion',
                'title'    => __('Promotion', 'events'),
                'location' => ['event'],
                'display'  => ['position' => 'sidebar', 'order' => 20],
                'config'   => [
                    'fields' => [
                        ['id' => 'event_hero', 'type' => 'file', 'label' => __('Hero image', 'events')],
                        ['id' => 'event_promoted', 'type' => 'toggle', 'label' => __('Promote on the homepage', 'events'), 'default' => false],
                    ],
                ],
            ],
        ],
    ]);
});

// [upcoming_events limit="5"]: events with a session from today on, soonest first.
add_shortcode('upcoming_events', function ($atts) {
    $atts = shortcode_atts(['limit' => 5], (array) $atts, 'upcoming_events');

    // `sessions.starts` is an indexed repeater subfield. A named clause sorts by its matching rows, so past sessions don't count.
    $query = new WP_Query([
        'post_type'      => 'event',
        'post_status'    => 'publish',
        'posts_per_page' => max(1, (int) $atts['limit']),
        'no_found_rows'  => true,
        'orderby'        => ['next_session' => 'ASC'],
        'meta_query'     => [
            'next_session' => ['key' => 'sessions.starts', 'compare' => '>=', 'value' => wp_date('Y-m-d'), 'type' => 'DATE'],
        ],
    ]);

    if (! $query->have_posts()) {
        return '<p>' . esc_html__('No upcoming events.', 'events') . '</p>';
    }

    $items = '';

    foreach ($query->posts as $event) {
        $venue = (string) get_post_meta($event->ID, 'sessions.0.venue', true);
        $price = Wireframe\Meta::get($event->ID, 'event_price');

        $items .= sprintf(
            '<li><a href="%s">%s</a>%s%s</li>',
            esc_url(get_permalink($event)),
            esc_html(get_the_title($event)),
            $venue !== '' ? ' &middot; ' . esc_html($venue) : '',
            is_numeric($price) && (float) $price > 0 ? ' &middot; ' . esc_html(number_format_i18n((float) $price, 2)) : ''
        );
    }

    return '<ul class="upcoming-events">' . $items . '</ul>';
});
