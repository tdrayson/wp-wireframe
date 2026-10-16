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

$fixtures = [$a, $b, $c];

// Scoped to the fixtures: other suites leave events behind.
$ids = static function (array $q) use ($fixtures): array {
    $found = (new WP_Query([
        'post_type'       => 'event',
        'fields'          => 'ids',
        'post__in'        => $fixtures,
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

foreach ($fixtures as $id) {
    wp_delete_post($id, true);
}

finish();
