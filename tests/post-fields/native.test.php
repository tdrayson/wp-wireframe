<?php

use Wireframe\PostFields\Index\MetaQueryBridge;

require __DIR__ . '/assert.php';

global $wpdb;

as_user('administrator');

$a = make_event(['sessions' => [
    ['starts' => '2026-11-05', 'venue' => 'Leeds', 'seats' => 0, 'notes' => 'Bring a pen'],
    ['starts' => '2026-10-01', 'venue' => 'York', 'seats' => 10],
]], 'publish');
$b = make_event(['sessions' => [['starts' => '2026-12-01', 'venue' => 'Leeds', 'seats' => 5, 'tags' => ['a']]]], 'publish');
$c = make_event([], 'publish');

update_post_meta($a, 'price', 10);
update_post_meta($b, 'price', 50);
update_post_meta($c, 'price', 10);

$fixtures = [$a, $b, $c];

// Scoped to the fixtures: other suites leave events behind.
$query = static function (array $args) use ($fixtures): array {
    return array_map('intval', (new WP_Query($args + [
        'post_type'      => 'event',
        'fields'         => 'ids',
        'post__in'       => $fixtures,
        'posts_per_page' => -1,
    ]))->posts);
};

$ids = static function (array $metaQuery) use ($query): array {
    $found = $query(['meta_query' => $metaQuery]);
    sort($found);

    return $found;
};

$wpdb->last_error = '';

check('a dotted key matches any row', [$a, $b], $ids([['key' => 'sessions.venue', 'value' => 'Leeds']]));

$found = get_posts([
    'post_type'   => 'event',
    'fields'      => 'ids',
    'post__in'    => $fixtures,
    'numberposts' => -1,
    'meta_query'  => [['key' => 'sessions.venue', 'value' => 'Leeds']],
]);
sort($found);
check('get_posts applies a dotted key', [$a, $b], array_map('intval', $found));

check('a dotted clause mixes with a plain one', [$a], $ids([
    'relation' => 'AND',
    ['key' => 'sessions.seats', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC'],
    ['key' => 'price', 'value' => 10, 'type' => 'NUMERIC'],
]));
check('nested relations', [$a, $b], $ids([
    'relation' => 'OR',
    ['key' => 'sessions.venue', 'value' => 'York'],
    ['relation' => 'AND', ['key' => 'sessions.seats', 'value' => 5]],
]));
check('DATE compares', [$a, $b], $ids([['key' => 'sessions.starts', 'value' => '2026-11-01', 'compare' => '>=', 'type' => 'DATE']]));
check('IN takes a comma list like core', [$a], $ids([['key' => 'sessions.venue', 'value' => 'Hull,York', 'compare' => 'IN']]));
check('NOT EXISTS', [$c], $ids([['key' => 'sessions.starts', 'compare' => 'NOT EXISTS']]));
check('a plain OR sibling sharing the dotted alias', [$a, $c], $ids([
    'relation' => 'OR',
    ['key' => 'price', 'value' => 10],
    ['key' => 'sessions.venue', 'value' => 'York'],
]));
check('a dotted OR sibling sharing the plain alias', [$a, $c], $ids([
    'relation' => 'OR',
    ['key' => 'sessions.venue', 'value' => 'York'],
    ['key' => 'price', 'value' => 10],
]));
check('a non-indexed dotted key matches nothing', [], $ids([['key' => 'sessions.notes', 'value' => 'Bring a pen']]));
check('a quote in a value cannot inject SQL', [], $ids([['key' => 'sessions.venue', 'value' => "x' OR 1=1 -- "]]));
check('dotted clauses cause no SQL error', '', $wpdb->last_error);

$plain = [
    'relation' => 'OR',
    ['key' => 'price', 'value' => 10, 'compare' => '>', 'type' => 'NUMERIC'],
    ['key' => 'other.thing', 'compare' => 'NOT EXISTS'],
    ['relation' => 'AND', ['key' => 'sessions.notes', 'value' => 'x'], ['key' => 'seo_title', 'compare' => 'EXISTS']],
];
$filtered = get_meta_sql($plain, 'post', $wpdb->posts, 'ID');
remove_filter('get_meta_sql', [MetaQueryBridge::class, 'rewrite'], 1);
$core = get_meta_sql($plain, 'post', $wpdb->posts, 'ID');
add_filter('get_meta_sql', [MetaQueryBridge::class, 'rewrite'], 1, 6);
check('queries without indexed dotted keys are left to core', $core, $filtered);

check('meta_key orders ASC by the earliest value, empty last', [$a, $b, $c], $query([
    'meta_key' => 'sessions.starts',
    'orderby'  => 'meta_value',
    'order'    => 'ASC',
]));
check('meta_key orders DESC by the latest value, empty last', [$b, $a, $c], $query([
    'meta_key' => 'sessions.starts',
    'orderby'  => 'meta_value',
    'order'    => 'DESC',
]));
check('meta_value_num orders numerically', [$a, $b, $c], $query([
    'meta_key' => 'sessions.seats',
    'orderby'  => 'meta_value_num',
    'order'    => 'DESC',
]));
check('a named clause orders', [$a, $b], $query([
    'meta_query' => ['starts' => ['key' => 'sessions.starts', 'compare' => 'EXISTS']],
    'orderby'    => ['starts' => 'ASC'],
]));
check('a named clause orders by its matching rows', [$b, $a], $query([
    'meta_query' => ['seats' => ['key' => 'sessions.seats', 'value' => 6, 'compare' => '<', 'type' => 'NUMERIC']],
    'orderby'    => ['seats' => 'DESC'],
]));
check('a plain clause after a dotted one keeps its alias for orderby', [$b, $a], $query([
    'meta_query' => [
        'venue' => ['key' => 'sessions.venue', 'value' => 'Leeds'],
        'price' => ['key' => 'price', 'type' => 'NUMERIC', 'compare' => 'EXISTS'],
    ],
    'orderby'    => ['price' => 'DESC'],
]));

$found = get_posts([
    'post_type'   => 'event',
    'fields'      => 'ids',
    'post__in'    => $fixtures,
    'numberposts' => -1,
    'meta_key'    => 'sessions.starts',
    'orderby'     => 'meta_value',
    'order'       => 'ASC',
]);
check('get_posts orders by a dotted meta_key', [$a, $b, $c], array_map('intval', $found));
check('orderby causes no SQL error', '', $wpdb->last_error);

check('a cell by row index', 'Leeds', get_post_meta($a, 'sessions.0.venue', true));
check('every row of a subfield', ['Leeds', 'York'], get_post_meta($a, 'sessions.venue'));
check('the first row of a subfield', 'Leeds', get_post_meta($a, 'sessions.venue', true));
check('a non-indexed subfield reads too', 'Bring a pen', get_post_meta($a, 'sessions.0.notes', true));
check('a multi-value cell stays an array', ['a'], get_post_meta($b, 'sessions.0.tags', true));
check('an out-of-range row is empty (single)', '', get_post_meta($a, 'sessions.5.venue', true));
check('an out-of-range row is empty', [], get_post_meta($a, 'sessions.5.venue'));
check('an out-of-range row does not exist', false, metadata_exists('post', $a, 'sessions.5.venue'));
check('a post without rows has no subfield values', [], get_post_meta($c, 'sessions.venue'));

$page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Page']);
update_post_meta($page, 'sessions', [['venue' => 'Leeds']]);
check('a dotted key on a non-grouped post type is left to core', '', get_post_meta($page, 'sessions.venue', true));

$meta = rest('GET', "/wp/v2/event/$a")->get_data()['meta'] ?? [];
check('dotted keys are not REST meta', [], array_values(array_filter(array_keys($meta), static fn (string $key): bool => str_contains($key, '.'))));

foreach ([...$fixtures, $page] as $id) {
    wp_delete_post($id, true);
}

finish();
