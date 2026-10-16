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

$group = static fn (string $id, mixed $location, array $fields, array $extra = []): array => $extra + [
    'id'       => $id,
    'title'    => 'Title',
    'location' => $location,
    'config'   => ['fields' => $fields],
];

$ids = static fn (string $postType): array => array_column(FieldGroups::groupsFor($postType), 'id');

$find = static function (string $postType, string $id): ?array {
    foreach (FieldGroups::groupsFor($postType) as $group) {
        if ($group['id'] === $id) {
            return $group;
        }
    }

    return null;
};

check('fixture group boots with no notices (lint clean)', [], $notices);
check('event groups sorted by order, then registration', ['event_details', 'event_extras', 'event_featured_extras', 'event_seo'], $ids('event'));
check('post gets its shorthand group', ['post_extras'], $ids('post'));

$seo = $find('event', 'event_seo');
check('normalised keys', ['id', 'prefix', 'owner', 'title', 'location', 'post_types', 'display', 'config'], array_keys((array) $seo));
check('normalised display', ['position' => 'sidebar', 'collapsed' => true, 'order' => 20], $seo['display'] ?? null);
check('title kept', 'SEO', $seo['title'] ?? null);
check('owner is prefix/id', 'wf-fixture/event_seo', $seo['owner'] ?? null);
check('post types resolved from the location', ['event', 'classic_event'], $seo['post_types'] ?? null);
check('config is a slug', 'wf-fixture-group-event_seo', $seo['config'] ?? null);
check(
    'shorthand location expanded',
    ['all' => [['field' => 'post_type', 'operator' => 'in', 'value' => ['post']]]],
    $find('post', 'post_extras')['location'] ?? null
);
check('content group without a title keeps it empty', '', $find('event', 'event_details')['title'] ?? null);

check('flat fields merge every group', ['date', 'text', 'textarea'], [
    FieldGroups::flatFields('event')['sessions.starts']['type'] ?? null,
    FieldGroups::flatFields('event')['seo_title']['type'] ?? null,
    FieldGroups::flatFields('event')['extra_note']['type'] ?? null,
]);
check('groupOfField finds the owning group', 'event_extras', FieldGroups::groupOfField('event', 'extra_note')['id'] ?? null);
check('groupOfField ignores repeater subfield paths', null, FieldGroups::groupOfField('event', 'sessions.starts'));
check('groupOfField on an unknown field', null, FieldGroups::groupOfField('event', 'nope'));
check('groupOfField on the wrong post type', null, FieldGroups::groupOfField('post', 'extra_note'));
check('custom-fields support added', [true, true], [post_type_supports('event', 'custom-fields'), post_type_supports('post', 'custom-fields')]);
check('content group claims its post type site-wide', 'wf-fixture/event_details', $GLOBALS['wireframe_field_group_owners']['event'] ?? null);
check('meta_box group does not claim', false, isset($GLOBALS['wireframe_field_group_owners']['post']));
check('no settings page registered', [], array_filter(App::pages(), static fn ($p) => $p['prefix'] === 'wf-fixture'));

$GLOBALS['wireframe_field_group_owners']['event'] = 'foreign/group';
FieldGroups::boot();
check('foreign claim blocks the content group only', ['event_extras', 'event_featured_extras', 'event_seo'], $ids('event'));
check('foreign claim is silent', 0, $mentions('event_details'));
unset($GLOBALS['wireframe_field_group_owners']['event']);
FieldGroups::boot();
check('released claim restores the content group', ['event_details', 'event_extras', 'event_featured_extras', 'event_seo'], $ids('event'));

register_post_type('defaults_cpt', ['public' => true, 'show_in_rest' => true]);
FieldGroups::register('other', ['id' => 'defaults', 'title' => 'D', 'location' => ['defaults_cpt'], 'config' => ['fields' => [['id' => 'd', 'type' => 'text']]]]);
check('display defaults', ['position' => 'meta_box', 'collapsed' => false, 'order' => 10], $find('defaults_cpt', 'defaults')['display'] ?? null);

$notices = [];
FieldGroups::register('other', $group('dupe', ['event'], [['id' => 'x', 'type' => 'text']], ['display' => ['position' => 'content']]));
check('second content group skipped', ['event_details', 'event_extras', 'event_featured_extras', 'event_seo'], $ids('event'));
check('second content group reported', 1, $mentions('"dupe"'));

$notices = [];
FieldGroups::register('other', $group('dupe_field', ['event'], [['id' => 'seo_title', 'type' => 'text']]));
check('duplicate field id skips the later group', null, $find('event', 'dupe_field'));
check('duplicate field id reported', 1, $mentions('"seo_title"'));

$notices = [];
FieldGroups::register('other', $group('roles_only', ['all' => [['field' => 'user_role', 'operator' => 'contains', 'value' => 'editor']]], [['id' => 'r', 'type' => 'text']]));
check('location without a finite post_type skipped', [], array_filter(FieldGroups::all(), static fn ($groups) => in_array('roles_only', array_column($groups, 'id'), true)));
check('location without a finite post_type reported', 1, $mentions('"roles_only"'));

$notices = [];
FieldGroups::register('other', $group('bad_location', 42, [['id' => 'r', 'type' => 'text']]));
check('invalid location reported', 1, $mentions('"bad_location"'));

register_post_type('pos_cpt', ['public' => true, 'show_in_rest' => true]);
$notices = [];
FieldGroups::register('other', $group('bad_position', ['pos_cpt'], [['id' => 'p', 'type' => 'text']], ['display' => ['position' => 'side']]));
check('unknown position skips the group', [], $ids('pos_cpt'));
check('unknown position reported', 1, $mentions('"bad_position"'));

register_post_type('lint_keys_cpt', ['public' => true, 'show_in_rest' => true]);
$notices = [];
FieldGroups::register('other', $group('lint_keys', ['lint_keys_cpt'], [['id' => 'k', 'type' => 'text']], [
    'post_types' => ['lint_keys_cpt'],
    'display'    => ['position' => 'meta_box', 'width' => 'wide'],
]));
check('v1 post_types key reported as unknown', 1, $mentions('unknown key "post_types"'));
check('unknown display key reported', 1, $mentions('unknown key "display.width"'));
check('unknown keys do not skip the group', ['lint_keys'], $ids('lint_keys_cpt'));

register_post_type('seo_cpt', ['public' => true, 'show_in_rest' => true]);
$notices = [];
FieldGroups::register('other', ['id' => 'seo', 'location' => ['seo_cpt'], 'display' => ['position' => 'sidebar'], 'config' => ['fields' => [['id' => 's', 'type' => 'text']]]]);
check('missing sidebar title reported', 1, $mentions('"title"'));
check('missing title falls back to the id', 'Seo', $find('seo_cpt', 'seo')['title'] ?? null);

register_post_type('bad', ['public' => true, 'show_in_rest' => true]);
$notices = [];
FieldGroups::register('other', $group('bad_group', ['bad'], [['id' => 'go', 'type' => 'action']]));
check('excluded field type skips the group', [], $ids('bad'));
check('excluded field type reported', 1, $mentions('"bad_group"'));

register_post_type('bad_nested', ['public' => true, 'show_in_rest' => true]);
FieldGroups::register('other', $group('nested_group', ['bad_nested'], [[
    'id'    => 'rows',
    'type'  => 'repeater',
    'args'  => ['subfields' => [['id' => 'go', 'type' => 'action', 'label' => 'Go']]],
]]));
check('excluded type inside a repeater skips the group', [], $ids('bad_nested'));
check('excluded type inside a repeater reported', 1, $mentions('rows.go'));

register_post_type('hidden_cpt', ['public' => true]);
FieldGroups::register('other', $group('hidden_group', ['hidden_cpt'], [['id' => 'y', 'type' => 'text']]));
check('non-REST post type skipped', [], $ids('hidden_cpt'));
check('non-REST post type reported', 1, $mentions('must be registered with show_in_rest'));

FieldGroups::register('other', $group('typo_group', ['evnt'], [['id' => 'y', 'type' => 'text']]));
check('unregistered post type skipped', [], $ids('evnt'));
check('unregistered post type has its own message', 1, $mentions('post type "evnt" is not registered'));

register_post_type('multi_ok', ['public' => true, 'show_in_rest' => true]);
$notices = [];
FieldGroups::register('other', $group('multi_group', ['multi_ok', 'hidden_cpt'], [['id' => 'y', 'type' => 'text']]));
check('multi post type group is not half-attached', [], $ids('multi_ok'));
check('multi post type group reported once', 1, $mentions('"multi_group"'));

register_post_type('some_cpt', ['public' => true, 'show_in_rest' => true]);
$GLOBALS['wireframe_field_group_owners']['some_cpt'] = 'foreign/group';
$notices = [];
FieldGroups::register('other', $group('foreign_group', ['some_cpt'], [['id' => 'y', 'type' => 'text']], ['display' => ['position' => 'content']]));
FieldGroups::register('other', $group('foreign_box', ['some_cpt'], [['id' => 'z', 'type' => 'text']]));
check('content group on a post type owned by another copy is skipped', ['foreign_box'], $ids('some_cpt'));
check('post type owned by another copy is silent', 0, $mentions('foreign_group'));
unset($GLOBALS['wireframe_field_group_owners']['some_cpt']);

register_post_type('late_cpt', ['public' => true, 'show_in_rest' => true]);
App::boot(['prefix' => 'late', 'field_groups' => [$group('late_group', ['late_cpt'], [['id' => 'y', 'type' => 'text']])]]);
check('group booted after wp_loaded is attached', ['late_group'], $ids('late_cpt'));
check('late group has no settings page', [], array_filter(App::pages(), static fn ($p) => $p['prefix'] === 'late'));

$notices = [];
$eventGroup = ['id' => 'event_details', 'location' => ['event'], 'display' => ['position' => 'content'], 'config' => __DIR__ . '/fixture/config/event.php'];
App::boot(['prefix' => 'wf-fixture', 'field_groups' => [$eventGroup]]);
App::boot(['prefix' => 'wf-fixture', 'field_groups' => [$eventGroup]]);
check('second boot keeps the group and its place', ['event_details', 'event_extras', 'event_featured_extras', 'event_seo'], $ids('event'));
check('second boot raises no notice for the group', 0, $mentions('"event_details" (wf-fixture)'));

register_post_type('lint_cpt', ['public' => true, 'show_in_rest' => true]);
$notices = [];
FieldGroups::register('other', $group('lint_group', ['lint_cpt'], [['id' => 'z', 'type' => 'no_such_type']]));
check('lint finding reported', 1, $mentions('unknown type "no_such_type"'));
check('lint finding does not skip the group', ['lint_group'], $ids('lint_cpt'));

$notices = [];
App::boot(['prefix' => 'junk', 'field_groups' => ['not-an-array']]);
check('non-array field_groups entry reported', 1, $mentions('must be an array'));

$notices = [];
register_post_type('late_bad_a', ['public' => true]);
register_post_type('late_bad_b', ['public' => true]);
FieldGroups::register('other', $group('late_bad_a_group', ['late_bad_a'], [['id' => 'y', 'type' => 'text']]));
FieldGroups::register('other', $group('late_bad_b_group', ['late_bad_b'], [['id' => 'y', 'type' => 'text']]));
check('late rejection reported once', 1, $mentions('"late_bad_a_group"'));
check('second late registration does not repeat earlier notices', 1, $mentions('"late_bad_b_group"'));
check('late registration leaves existing groups alone', 0, $mentions('"bad_group"'));

check('valid groups survive every re-boot', ['event_details', 'event_extras', 'event_featured_extras', 'event_seo'], $ids('event'));

finish();
