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

$group = static fn (string $id, array $postTypes, array $fields): array => [
    'id'         => $id,
    'post_types' => $postTypes,
    'config'     => ['fields' => $fields],
];

check('group found', 'wf-fixture-group-event_details', FieldGroups::forPostType('event')['config'] ?? null);
check('sessions.starts flattened', 'date', FieldGroups::flatFields('event')['sessions.starts']['type'] ?? null);
check('custom-fields support added', true, post_type_supports('event', 'custom-fields'));
check('no settings page registered', [], array_filter(App::pages(), static fn ($p) => $p['prefix'] === 'wf-fixture'));
check('fixture group boots with no notices (lint clean)', [], $notices);

// Duplicate group on one post type: first owner keeps it.
FieldGroups::register('other', $group('dupe', ['event'], [['id' => 'x', 'type' => 'text']]));
check('first group keeps the post type', 'event_details', FieldGroups::forPostType('event')['id'] ?? null);
check('duplicate group reported', 1, $mentions('"dupe"'));

// Excluded field types, top level and inside a repeater.
register_post_type('bad', ['public' => true, 'show_in_rest' => true]);
FieldGroups::register('other', $group('bad_group', ['bad'], [['id' => 'go', 'type' => 'action']]));
check('excluded field type skips the group', null, FieldGroups::forPostType('bad'));
check('excluded field type reported', 1, $mentions('"bad_group"'));

register_post_type('bad_nested', ['public' => true, 'show_in_rest' => true]);
FieldGroups::register('other', $group('nested_group', ['bad_nested'], [[
    'id'    => 'rows',
    'type'  => 'repeater',
    'args'  => ['subfields' => [['id' => 'go', 'type' => 'action', 'label' => 'Go']]],
]]));
check('excluded type inside a repeater skips the group', null, FieldGroups::forPostType('bad_nested'));
check('excluded type inside a repeater reported', 1, $mentions('rows.go'));

// Post types that cannot be attached.
register_post_type('hidden_cpt', ['public' => true]);
FieldGroups::register('other', $group('hidden_group', ['hidden_cpt'], [['id' => 'y', 'type' => 'text']]));
check('non-REST post type skipped', null, FieldGroups::forPostType('hidden_cpt'));
check('non-REST post type reported', 1, $mentions('must be registered with show_in_rest'));

FieldGroups::register('other', $group('typo_group', ['evnt'], [['id' => 'y', 'type' => 'text']]));
check('unregistered post type skipped', null, FieldGroups::forPostType('evnt'));
check('unregistered post type has its own message', 1, $mentions('post type "evnt" is not registered'));

// A group with several post types attaches to all of them or none.
register_post_type('multi_ok', ['public' => true, 'show_in_rest' => true]);
$notices = [];
FieldGroups::register('other', $group('multi_group', ['multi_ok', 'hidden_cpt'], [['id' => 'y', 'type' => 'text']]));
check('multi post type group is not half-attached', null, FieldGroups::forPostType('multi_ok'));
check('multi post type group reported once', 1, $mentions('"multi_group"'));

// Another bundled copy already owns the post type: skipped without a notice.
register_post_type('some_cpt', ['public' => true, 'show_in_rest' => true]);
$GLOBALS['wireframe_field_group_owners']['some_cpt'] = 'foreign/group';
$notices = [];
FieldGroups::register('other', $group('foreign_group', ['some_cpt'], [['id' => 'y', 'type' => 'text']]));
check('post type owned by another copy is skipped', null, FieldGroups::forPostType('some_cpt'));
check('post type owned by another copy is silent', 0, $mentions('foreign_group'));
unset($GLOBALS['wireframe_field_group_owners']['some_cpt']);

// Booting after wp_loaded still claims.
register_post_type('late_cpt', ['public' => true, 'show_in_rest' => true]);
App::boot(['prefix' => 'late', 'field_groups' => [$group('late_group', ['late_cpt'], [['id' => 'y', 'type' => 'text']])]]);
check('group booted after wp_loaded is claimed', 'late_group', FieldGroups::forPostType('late_cpt')['id'] ?? null);
check('late group has no settings page', [], array_filter(App::pages(), static fn ($p) => $p['prefix'] === 'late'));

// Booting twice with the same groups replaces, never duplicates.
$notices = [];
$eventGroup = ['id' => 'event_details', 'post_types' => ['event'], 'config' => __DIR__ . '/fixture/config/event.php'];
App::boot(['prefix' => 'wf-fixture', 'field_groups' => [$eventGroup]]);
App::boot(['prefix' => 'wf-fixture', 'field_groups' => [$eventGroup]]);
check('second boot keeps the group', 'event_details', FieldGroups::forPostType('event')['id'] ?? null);
check('second boot raises no notice for the group', 0, $mentions('"event_details" (wf-fixture)'));

// Linter findings are reported but do not skip the group.
register_post_type('lint_cpt', ['public' => true, 'show_in_rest' => true]);
$notices = [];
FieldGroups::register('other', $group('lint_group', ['lint_cpt'], [['id' => 'z', 'type' => 'no_such_type']]));
check('lint finding reported', 1, $mentions('unknown type "no_such_type"'));
check('lint finding does not skip the group', 'lint_group', FieldGroups::forPostType('lint_cpt')['id'] ?? null);

// Malformed entries are reported.
$notices = [];
App::boot(['prefix' => 'junk', 'field_groups' => ['not-an-array']]);
check('non-array field_groups entry reported', 1, $mentions('must be an array'));

check('valid group survives every re-boot', 'event_details', FieldGroups::forPostType('event')['id'] ?? null);

finish();
