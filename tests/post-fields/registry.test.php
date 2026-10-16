<?php

use Wireframe\App;
use Wireframe\PostFields\FieldGroups;

require __DIR__ . '/assert.php';

$notices = [];
add_action('doing_it_wrong_run', static function (string $function, string $message) use (&$notices): void {
    $notices[] = $message;
}, 10, 2);

$mentions = static function (string $needle) use (&$notices): int {
    return count(array_filter($notices, static fn ($m) => str_contains($m, $needle)));
};

check('group found', 'wf-fixture-group-event_details', FieldGroups::forPostType('event')['config'] ?? null);
check('sessions.starts flattened', 'date', FieldGroups::flatFields('event')['sessions.starts']['type'] ?? null);
check('custom-fields support added', true, post_type_supports('event', 'custom-fields'));
check('no settings page registered', [], array_filter(App::pages(), static fn ($p) => $p['prefix'] === 'wf-fixture'));
check('no notices from the valid group', [], $notices);

FieldGroups::register('other', [
    'id'         => 'dupe',
    'post_types' => ['event'],
    'config'     => ['fields' => [['id' => 'x', 'type' => 'text']]],
]);
FieldGroups::boot();
check('first group keeps the post type', 'event_details', FieldGroups::forPostType('event')['id'] ?? null);
check('duplicate group reported', 1, $mentions('"dupe"'));

register_post_type('bad', ['public' => true, 'show_in_rest' => true]);
FieldGroups::register('other', [
    'id'         => 'bad_group',
    'post_types' => ['bad'],
    'config'     => ['fields' => [['id' => 'go', 'type' => 'action']]],
]);
FieldGroups::boot();
check('excluded field type skips the group', null, FieldGroups::forPostType('bad'));
check('excluded field type reported', 1, $mentions('"bad_group"'));

register_post_type('hidden_cpt', ['public' => true]);
FieldGroups::register('other', [
    'id'         => 'hidden_group',
    'post_types' => ['hidden_cpt'],
    'config'     => ['fields' => [['id' => 'y', 'type' => 'text']]],
]);
FieldGroups::boot();
check('non-REST post type skipped', null, FieldGroups::forPostType('hidden_cpt'));
check('non-REST post type reported', 1, $mentions('"hidden_group"'));
check('valid group survives re-boot', 'event_details', FieldGroups::forPostType('event')['id'] ?? null);
check('all() keyed by post type', ['event'], array_keys(FieldGroups::all()));

finish();
