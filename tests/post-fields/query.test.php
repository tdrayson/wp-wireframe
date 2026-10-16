<?php

use Wireframe\PostFields\Index\QueryClauses;

require __DIR__ . '/assert.php';

global $wpdb;

as_user('administrator');

$wrong = [];
add_action('doing_it_wrong_run', static function (string $function) use (&$wrong): void {
    $wrong[] = $function;
});

$a = make_event(['sessions' => [
    ['starts' => '2026-11-05', 'venue' => 'Leeds', 'seats' => 0],
    ['starts' => '2026-10-01', 'venue' => 'York', 'seats' => 10],
]], 'publish');
$b = make_event(['sessions' => [['starts' => '2026-12-01', 'venue' => 'Leeds', 'seats' => 5, 'tags' => ['a']]]], 'publish');
$c = make_event([], 'publish');
$d = make_event(['sessions' => [['venue' => '2026-11-30 18:30']]], 'publish');

update_post_meta($a, 'wf_query_flag', 'yes');
update_post_meta($c, 'wf_query_flag', 'yes');

$fixtures = [$a, $b, $c];

// Scoped to the fixtures: other suites leave events behind.
$ids = static function (mixed $q, ?array $scope = null, array $args = []) use ($fixtures): array {
    $found = (new WP_Query($args + [
        'post_type'       => 'event',
        'fields'          => 'ids',
        'post__in'        => $scope ?? $fixtures,
        'posts_per_page'  => -1,
        'wireframe_query' => $q,
    ]))->posts;
    sort($found);

    return array_map('intval', $found);
};

$clause = static fn (string $subfield, mixed $value, string $compare = '=', string $type = 'CHAR'): array => [
    'field'    => 'sessions',
    'subfield' => $subfield,
    'compare'  => $compare,
    'value'    => $value,
    'type'     => $type,
];

check('DATE >= matches any row on or after the date', [$a, $b], $ids([$clause('starts', '2026-11-01', '>=', 'DATE')]));
check('NUMERIC > matches through any row', [$a, $b], $ids([$clause('seats', 0, '>', 'NUMERIC')]));
check('same_row needs both conditions in one row', [$b], $ids([[
    'same_row' => true,
    $clause('venue', 'Leeds'),
    $clause('seats', 0, '>', 'NUMERIC'),
]]));
check('without same_row the conditions may match different rows', [$a, $b], $ids([
    $clause('venue', 'Leeds'),
    $clause('seats', 0, '>', 'NUMERIC'),
]));
check('IN', [$a], $ids([$clause('venue', ['York'], 'IN')]));
check('LIKE matches a substring', [$a, $b], $ids([$clause('venue', 'eed', 'LIKE')]));
check('DATE BETWEEN includes both bounds as dates', [$a], $ids([$clause('starts', ['2026-11-01', '2026-11-30'], 'BETWEEN', 'DATE')]));
check('DATE BETWEEN includes the upper bound day', [$b], $ids([$clause('starts', ['2026-11-30', '2026-12-01'], 'BETWEEN', 'DATE')]));
check('EXISTS', [$a, $b], $ids([['field' => 'sessions', 'subfield' => 'starts', 'compare' => 'EXISTS']]));
check('NOT EXISTS', [$c], $ids([['field' => 'sessions', 'subfield' => 'starts', 'compare' => 'NOT EXISTS']]));
check('relation OR', [$a, $b], $ids([
    'relation' => 'OR',
    $clause('venue', 'York'),
    $clause('seats', 5),
]));
check('relation applies at nested levels', [$a], $ids([
    $clause('venue', 'Leeds'),
    ['relation' => 'OR', $clause('venue', 'York'), $clause('seats', 50, '=', 'NUMERIC')],
]));
check('a top-level clause needs no wrapping list', [$a], $ids($clause('venue', 'York')));
check('same_row with NOT EXISTS checks the same row', [$a], $ids([[
    'same_row' => true,
    $clause('venue', 'Leeds'),
    ['field' => 'sessions', 'subfield' => 'tags', 'compare' => 'NOT EXISTS'],
]]));

check('IN with several values', [$a], $ids([$clause('venue', ['Hull', 'York'], 'IN')]));
check('IN on a multi-value subfield', [$b], $ids([$clause('tags', ['a', 'c'], 'IN')]));
check('!= matches when any row differs', [$a], $ids([$clause('venue', 'Leeds', '!=')]));
check('NOT IN matches when any row is outside the list', [$a], $ids([$clause('venue', ['Leeds'], 'NOT IN')]));

$timed = [$d];
check('DATE = covers the whole day', [$d], $ids([$clause('venue', '2026-11-30', '=', 'DATE')], $timed));
check('DATE <= includes later times on the day', [$d], $ids([$clause('venue', '2026-11-30', '<=', 'DATE')], $timed));
check('DATE >= includes the day', [$d], $ids([$clause('venue', '2026-11-30', '>=', 'DATE')], $timed));
check('DATE > excludes the day', [], $ids([$clause('venue', '2026-11-30', '>', 'DATE')], $timed));
check('DATE < excludes the day', [], $ids([$clause('venue', '2026-11-30', '<', 'DATE')], $timed));
check('DATE BETWEEN includes later times on the upper day', [$d], $ids([$clause('venue', ['2026-11-01', '2026-11-30'], 'BETWEEN', 'DATE')], $timed));
check('DATE != compares the date part', [], $ids([$clause('venue', '2026-11-30', '!=', 'DATE')], $timed));
check('DATETIME compares the full value', [$d], $ids([$clause('venue', '2026-11-30 18:00', '>', 'DATETIME')], $timed));
check('DATETIME BETWEEN ends at midnight of a bare upper date', [], $ids([$clause('venue', ['2026-11-01', '2026-11-30'], 'BETWEEN', 'DATETIME')], $timed));
check('DATE ranges compare the raw column', false, str_contains(
    QueryClauses::compile([$clause('starts', ['2026-11-01', '2026-11-30'], 'BETWEEN', 'DATE'), $clause('starts', '2026-11-01', '<=', 'DATE')], 'p.ID'),
    'DATE('
));

check('an empty child of an OR group is dropped', [$a], $ids(['relation' => 'OR', [], $clause('venue', 'York')]));
check('a query of empty groups adds no condition', [$a, $b, $c], $ids([[], [[]]]));
check('combines with meta_query', [$a], $ids([$clause('venue', 'Leeds')], null, [
    'meta_query' => [['key' => 'wf_query_flag', 'value' => 'yes']],
]));

$found = get_posts([
    'post_type'       => 'event',
    'fields'          => 'ids',
    'post__in'        => $fixtures,
    'numberposts'     => -1,
    'wireframe_query' => [$clause('venue', 'York')],
]);
check('get_posts applies wireframe_query', [$a], array_map('intval', $found));

$paged = new WP_Query([
    'post_type'       => 'event',
    'fields'          => 'ids',
    'post__in'        => $fixtures,
    'posts_per_page'  => 1,
    'orderby'         => 'ID',
    'order'           => 'DESC',
    'wireframe_query' => [$clause('venue', 'Leeds')],
]);
check('the first page is filtered', [$b], array_map('intval', $paged->posts));
check('found_posts counts filtered posts', 2, (int) $paged->found_posts);
check('max_num_pages counts filtered posts', 2, (int) $paged->max_num_pages);
$paged->set('paged', 2);
check('re-running get_posts on the same query stays filtered', [$a], array_map('intval', $paged->get_posts()));
check('a query built from copied vars stays filtered', [$a], array_map('intval', (new WP_Query($paged->query_vars))->posts));

$wrong = [];
check('a non-array wireframe_query is ignored', [$a, $b, $c], $ids('venue=Leeds'));
check('a relation-only wireframe_query is ignored', [$a, $b, $c], $ids(['relation' => 'OR']));
check('a malformed wireframe_query reports doing-it-wrong', 2, count($wrong));

$wpdb->last_error = '';
check('an unknown subfield matches nothing', [], $ids([$clause('nope', 'Leeds')]));
check('an unknown subfield causes no SQL error', '', $wpdb->last_error);
check('a quote in a value cannot inject SQL', [], $ids([$clause('venue', "x' OR 1=1 -- ")]));
check('a quote in a subfield cannot inject SQL', [], $ids([$clause("venue' OR 1=1 -- ", 'Leeds')]));
check('injection attempts cause no SQL error', '', $wpdb->last_error);

$seats = $ids([$clause('seats', 0, '>', 'NUMERIC')]);
check('no duplicate posts', count($seats), count(array_unique($seats)));

$wrong = [];
check('an unknown compare matches nothing', [], $ids([$clause('venue', 'Leeds', 'REGEXP')]));
check('an unknown type matches nothing', [], $ids([$clause('venue', 'Leeds', '=', 'BINARY')]));
check('a same_row group across repeaters matches nothing', [], $ids([[
    'same_row' => true,
    $clause('venue', 'Leeds'),
    ['field' => 'other', 'subfield' => 'venue', 'value' => 'Leeds'],
]]));
check('a same_row group with a nested group matches nothing', [], $ids([[
    'same_row' => true,
    $clause('venue', 'Leeds'),
    [$clause('seats', 5)],
]]));
check('each malformed query reports doing-it-wrong', 4, count($wrong));
check('malformed queries cause no SQL error', '', $wpdb->last_error);

$query = new WP_Query();
$query->set('wireframe_query', [$clause('venue', 'Leeds')]);
$once = QueryClauses::apply(['where' => ''], $query);
$once = QueryClauses::apply($once, $query);
check('the clauses are applied once per query', 1, substr_count($once['where'], 'EXISTS'));
check('the applied flag is set', true, (bool) $query->get('_wireframe_query_applied'));

foreach ([...$fixtures, $d] as $id) {
    wp_delete_post($id, true);
}

finish();
