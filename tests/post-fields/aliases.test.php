<?php

use Wireframe\Meta;
use Wireframe\PostFields\Aliases;
use Wireframe\PostFields\FieldGroups;

require __DIR__ . '/assert.php';

global $wpdb;

as_user('administrator');

$notices = [];
add_action('doing_it_wrong_run', static function (string $function, string $message) use (&$notices): void {
    $notices[] = $message;
}, 10, 2);

// A late post type, registered before the first REST request so it gets routes.
register_post_type('alias_cpt', ['public' => true, 'show_in_rest' => true, 'supports' => ['title']]);
FieldGroups::register('wf-alias', [
    'id'       => 'alias_group',
    'title'    => 'Alias',
    'location' => ['alias_cpt'],
    'config'   => ['fields' => [
        [
            'id'           => 'genres',
            'type'         => 'checkboxes',
            'previous_ids' => ['old_genres'],
            'default'      => ['jazz'],
            'args'         => ['options' => ['jazz' => 'Jazz', 'rock' => 'Rock', 'folk' => 'Folk']],
        ],
        [
            'id'           => 'sessions',
            'type'         => 'repeater',
            'previous_ids' => ['old_sessions', 'older_sessions'],
            'args'         => ['subfields' => [
                ['id' => 'venue', 'type' => 'text', 'indexed' => true],
            ]],
        ],
    ]],
]);

$mentions = static function (string $needle) use (&$notices): int {
    return count(array_filter($notices, static fn (string $m): bool => str_contains($m, $needle)));
};
$rest     = static fn (int $id): array => (array) (rest('GET', "/wp/v2/event/$id", [], ['context' => 'edit'])->get_data()['meta'] ?? []);
$created  = [];
$event    = static function () use (&$created): int {
    return $created[] = make_event();
};

// ─── Single value ─────────────────────────────────

$id = $event();
add_post_meta($id, 'cost', '15');

check('get_post_meta reads the previous key', '15', get_post_meta($id, 'price', true));
check('get_post_meta, single false, reads the previous rows', ['15'], get_post_meta($id, 'price'));
check('the previous key is not copied', false, metadata_exists('post', $id, 'price'));
check('Meta::get reads the previous key', 15, Meta::get($id, 'price'));
check('Meta::has sees the previous key', true, Meta::has($id, 'price'));
check('Meta::all sees the previous key', '15', Meta::all($id)['price'] ?? null);
check('Meta::resolved sees the previous key', 15, Meta::resolved($id)['price'] ?? null);
check('Meta::exists sees the previous key', true, Meta::exists($id));
check('REST edit context shows the previous key as the field', 15.0, $rest($id)['price'] ?? null);
check('REST does not expose the previous key', false, array_key_exists('cost', $rest($id)));
check('the previous key is protected from the Custom Fields box', true, is_protected_meta('cost', 'post'));
check('an unrelated key is not protected', false, is_protected_meta('wf_alias_other', 'post'));

add_post_meta($id, 'price', '7');
check('a current value wins over the previous one', 7, Meta::get($id, 'price'));

// ─── Writes ───────────────────────────────────────

$id = $event();
add_post_meta($id, 'cost', '15');
update_post_meta($id, 'price', 20);
check('update_post_meta removes the previous key', false, metadata_exists('post', $id, 'cost'));
check('update_post_meta stores the value', '20', get_post_meta($id, 'price', true));

$id = $event();
add_post_meta($id, 'cost', '15');
Meta::set($id, 'price', 30);
check('Meta::set removes the previous key', false, metadata_exists('post', $id, 'cost'));
check('Meta::set stores the value', 30, Meta::get($id, 'price'));

$id = $event();
add_post_meta($id, 'cost', '15');
rest('POST', "/wp/v2/event/$id", ['meta' => ['price' => 40]]);
check('a REST write removes the previous key', false, metadata_exists('post', $id, 'cost'));
check('a REST write stores the value', 40.0, $rest($id)['price'] ?? null);

$id = $event();
add_post_meta($id, 'cost', '15');
rest('POST', "/wp/v2/event/$id", ['title' => 'Renamed']);
check('a save that leaves the field alone keeps the previous key', true, metadata_exists('post', $id, 'cost'));
check('and the field still reads it', 15.0, $rest($id)['price'] ?? null);
wp_delete_post($id, true);

$id = $event();
add_post_meta($id, 'cost', '15');
Meta::forget($id, 'price');
check('Meta::forget removes the previous key', false, metadata_exists('post', $id, 'cost'));
check('Meta::forget leaves the field unset', false, Meta::has($id, 'price'));

$id = $event();
add_post_meta($id, 'cost', '15');
Meta::reset($id);
check('Meta::reset removes the previous key', false, metadata_exists('post', $id, 'cost'));

$id = $event();
add_post_meta($id, 'cost', '15');
Meta::update($id, ['ticket_url' => 'https://example.com']);
check('Meta::update, leaving the field out, removes the previous key', false, metadata_exists('post', $id, 'cost'));

// ─── Migrate ──────────────────────────────────────

$id = $event();
add_post_meta($id, 'cost', '15');
$untouched = $event();
update_post_meta($untouched, 'price', 9);

check('migrate reports the posts changed', 1, Meta::migrate('event'));
check('migrate copies the value to the current key', '15', get_post_meta($id, 'price', true));
check('migrate removes the previous key', false, metadata_exists('post', $id, 'cost'));
check('migrate leaves a migrated post alone', '9', get_post_meta($untouched, 'price', true));
check('a second migrate has nothing to do', 0, Meta::migrate('event'));

$id = $event();
add_post_meta($id, 'cost', '15');
remove_action('added_post_meta', [Aliases::class, 'onChange'], 10);
add_post_meta($id, 'price', '8');
add_action('added_post_meta', [Aliases::class, 'onChange'], 10, 3);
check('migrate counts a post whose previous key is stale', 1, Meta::migrate('event'));
check('migrate never overwrites the current value', '8', get_post_meta($id, 'price', true));
check('migrate drops the stale previous key', false, metadata_exists('post', $id, 'cost'));

$id = $event();
add_post_meta($id, 'cost', '15');
check('migrate for another post type skips this one', 0, Meta::migrate('post'));
check('the post is untouched', true, metadata_exists('post', $id, 'cost'));
check('migrate with no post type covers every type', true, Meta::migrate() >= 1);
check('and moved the value', '15', get_post_meta($id, 'price', true));

// ─── Multi-value and repeaters ────────────────────

$cpt = static function () use (&$created): int {
    $id = wp_insert_post(['post_type' => 'alias_cpt', 'post_status' => 'publish', 'post_title' => 'Alias'], true);

    return $created[] = (int) $id;
};
$found = static function (string $venue): array {
    return array_map('intval', (new WP_Query([
        'post_type'       => 'alias_cpt',
        'fields'          => 'ids',
        'posts_per_page'  => -1,
        'wireframe_query' => [['field' => 'sessions', 'subfield' => 'venue', 'value' => $venue]],
    ]))->posts);
};

$id = $cpt();
add_post_meta($id, 'old_genres', 'rock');
add_post_meta($id, 'old_genres', 'folk');
check('a multi-value key reads every previous row', ['rock', 'folk'], get_post_meta($id, 'genres'));
check('Meta::get reads the previous rows, not the default', ['rock', 'folk'], Meta::get($id, 'genres'));
check('Meta::all reads the previous rows', ['rock', 'folk'], Meta::all($id)['genres'] ?? null);
check('Meta::resolved reads the previous rows', ['rock', 'folk'], Meta::resolved($id)['genres'] ?? null);
check('REST edit context reads the previous rows', ['rock', 'folk'], rest('GET', "/wp/v2/alias_cpt/$id", [], ['context' => 'edit'])->get_data()['meta']['genres'] ?? null);

Meta::set($id, 'genres', ['jazz']);
check('Meta::set on a multi-value key removes the previous rows', false, metadata_exists('post', $id, 'old_genres'));
check('and stores the new rows', ['jazz'], get_post_meta($id, 'genres'));

$id = $cpt();
add_post_meta($id, 'old_genres', 'rock');
Meta::set($id, 'genres', []);
check('emptying an aliased multi-value key removes the previous rows', false, metadata_exists('post', $id, 'old_genres'));
check('and it stays empty rather than reverting', [], Meta::get($id, 'genres'));

$id = $cpt();
add_post_meta($id, 'old_genres', 'rock');
add_post_meta($id, 'old_genres', 'folk');
check('migrate moves a multi-value key', 1, Meta::migrate('alias_cpt'));
check('migrate copies every row, in order', ['rock', 'folk'], get_post_meta($id, 'genres'));
check('migrate removes every previous row', false, metadata_exists('post', $id, 'old_genres'));

$id = $cpt();
add_post_meta($id, 'older_sessions', [['venue' => 'Leeds']]);
check('the first previous id with a value is used', [['venue' => 'Leeds']], get_post_meta($id, 'sessions', true));
add_post_meta($id, 'old_sessions', [['venue' => 'York']]);
check('an earlier previous id wins over a later one', [['venue' => 'York']], get_post_meta($id, 'sessions', true));
check('a repeater reads through Meta::get', 'York', Meta::get($id, 'sessions.0.venue'));
check('a renamed repeater is not in the index before migrating', [], $found('York'));
check('a reindex indexes it under the new name', true, Meta::reindex('alias_cpt') >= 1);
check('so wireframe_query finds it after a reindex', [$id], $found('York'));
wp_delete_post($id, true);

$id = $cpt();
add_post_meta($id, 'old_sessions', [['venue' => 'Hull']]);
check('migrate moves a repeater', 1, Meta::migrate('alias_cpt'));
check('the repeater lands under the new key', [['venue' => 'Hull']], get_post_meta($id, 'sessions', true));
check('wireframe_query finds the migrated repeater', [$id], $found('Hull'));
check('the old key is gone', false, metadata_exists('post', $id, 'old_sessions'));

$unregistered = [];
add_filter('query', static function (string $sql) use (&$unregistered): string {
    if (str_contains($sql, 'wireframe_index') && str_contains($sql, 'old_sessions')) {
        $unregistered[] = $sql;
    }

    return $sql;
});
$id = $cpt();
add_post_meta($id, 'old_sessions', [['venue' => 'Ely']]);
Meta::set($id, 'sessions', [['venue' => 'Bath']]);
check('cleaning up a previous repeater key does not touch the index', [], $unregistered);
check('the index holds the new value', [$id], $found('Bath'));
check('and nothing of the old one', [], $found('Ely'));

// ─── Config errors ────────────────────────────────

$colliding = static function (string $slug, array $fields, string $needle) use (&$notices, $mentions): array {
    $notices = [];
    register_post_type($slug, ['public' => true, 'show_in_rest' => true]);
    FieldGroups::register('wf-alias', ['id' => "{$slug}_group", 'title' => 'T', 'location' => [$slug], 'config' => ['fields' => $fields]]);

    return [$mentions($needle), array_column(FieldGroups::groupsFor($slug), 'id')];
};

check('a previous id naming another field skips the group, reported once', [1, []], $colliding('coll_a', [
    ['id' => 'a', 'type' => 'text', 'previous_ids' => ['b']],
    ['id' => 'b', 'type' => 'text'],
], 'previous id "b"'));
check('a previous id naming the field itself skips the group', [1, []], $colliding('coll_b', [
    ['id' => 'a', 'type' => 'text', 'previous_ids' => ['a']],
], 'lists itself'));
check('two fields claiming one previous id skips the group', [1, []], $colliding('coll_c', [
    ['id' => 'a', 'type' => 'text', 'previous_ids' => ['x']],
    ['id' => 'b', 'type' => 'text', 'previous_ids' => ['x']],
], 'claimed by both'));
check('a malformed previous_ids skips the group', [1, []], $colliding('coll_d', [
    ['id' => 'a', 'type' => 'text', 'previous_ids' => 'b'],
], 'previous_ids'));
check('a valid previous id keeps the group', [0, ['coll_e_group']], $colliding('coll_e', [
    ['id' => 'a', 'type' => 'text', 'previous_ids' => ['b']],
], 'previous id'));

$notices = [];
FieldGroups::register('wf-alias', ['id' => 'coll_other', 'title' => 'T', 'location' => ['coll_e'], 'config' => ['fields' => [['id' => 'c', 'type' => 'text', 'previous_ids' => ['a']]]]]);
check('a previous id naming a field id in another group skips that group', [1, ['coll_e_group']], [$mentions('"a" is a previous id'), array_column(FieldGroups::groupsFor('coll_e'), 'id')]);

$notices = [];
FieldGroups::register('wf-alias', ['id' => 'coll_third', 'title' => 'T', 'location' => ['coll_e'], 'config' => ['fields' => [['id' => 'b', 'type' => 'text']]]]);
check('a field id naming a previous id in another group skips that group', [1, ['coll_e_group']], [$mentions('"b"'), array_column(FieldGroups::groupsFor('coll_e'), 'id')]);

$notices = [];
FieldGroups::register('wf-alias', ['id' => 'coll_fourth', 'title' => 'T', 'location' => ['coll_e'], 'config' => ['fields' => [['id' => 'd', 'type' => 'text', 'previous_ids' => ['b']]]]]);
check('a previous id shared with another group skips that group', [1, ['coll_e_group']], [$mentions('"b"'), array_column(FieldGroups::groupsFor('coll_e'), 'id')]);

$sameKeyElsewhere = static function (): array {
    register_post_type('coll_f', ['public' => true, 'show_in_rest' => true]);
    FieldGroups::register('wf-alias', ['id' => 'coll_f_group', 'title' => 'T', 'location' => ['coll_f'], 'config' => ['fields' => [['id' => 'a', 'type' => 'text', 'previous_ids' => ['b']]]]]);

    return array_column(FieldGroups::groupsFor('coll_f'), 'id');
};
check('the same previous id on another post type is fine', ['coll_f_group'], $sameKeyElsewhere());

foreach ($created as $postId) {
    wp_delete_post($postId, true);
}

finish();
