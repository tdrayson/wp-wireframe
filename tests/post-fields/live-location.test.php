<?php

use Wireframe\PostFields\ClassicEditor;
use Wireframe\PostFields\ClassicSave;
use Wireframe\PostFields\EditorIntegration;
use Wireframe\PostFields\FieldGroups;
use Wireframe\PostFields\Location;
use Wireframe\PostFields\SaveValidator;

require __DIR__ . '/assert.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

$created = [];

$byId = static function (string $postType, string $id): ?array {
    foreach (FieldGroups::groupsFor($postType) as $group) {
        if ($group['id'] === $id) {
            return $group;
        }
    }

    return null;
};

$entryOf = static function (array $data, string $id): ?array {
    foreach ($data['groups'] as $group) {
        if ($group['id'] === $id) {
            return $group;
        }
    }

    return null;
};

$event = static function (array $meta = [], string $status = 'draft') use (&$created): int {
    $id        = make_event($meta, $status);
    $created[] = $id;

    return $id;
};

$featured = get_term_by('name', 'Featured', 'event_type');
check('the fixture creates the Featured event type', true, $featured instanceof WP_Term);
$featuredId = (int) $featured->term_id;

$group = $byId('event', 'event_featured_extras');
check('event_featured_extras is registered', true, $group !== null);
check('its post_terms rule holds the term id as an int', $featuredId, $group['location']['all'][1]['value'] ?? null);

$admin  = get_userdata(as_user('administrator'));
$plain  = $event();
$tagged = $event();
wp_set_object_terms($tagged, [$featuredId], 'event_type');

$context = Location::context($plain, $admin);
check(
    'context carries the live params',
    ['post_type', 'post_id', 'user_role', 'post_status', 'post_template', 'post_terms', 'post_parent', 'post_format'],
    array_keys($context)
);
check('live defaults', ['draft', '', [], 0, 'standard'], [$context['post_status'], $context['post_template'], $context['post_terms'], $context['post_parent'], $context['post_format']]);
check('post_terms holds the assigned term id', [$featuredId], Location::context($tagged, $admin)['post_terms']);

check('the featured group does not apply without the term', false, Location::applies($group, $plain, $admin));
check('the featured group applies with the term', true, Location::applies($group, $tagged, $admin));
check('static match ignores live params', true, Location::staticMatch($group, $plain, $admin));
check('static match still checks static params', false, Location::staticMatch(
    ['location' => ['all' => [['field' => 'user_role', 'operator' => 'contains', 'value' => 'editor'], ['field' => 'post_status', 'operator' => 'equals', 'value' => 'draft']]]],
    $plain,
    $admin
));
check('static match keeps a filter-added param', false, Location::staticMatch(['location' => ['all' => [['field' => 'site_mode', 'operator' => 'equals', 'value' => 'on']]]], $plain, $admin));

$termIn = ['location' => Location::normalize(['all' => [['field' => 'post_terms', 'operator' => 'in', 'value' => [(string) $featuredId]]]])];
check('post_terms in matches any listed term', true, Location::applies($termIn, $tagged, $admin));
check('post_terms in misses an untagged post', false, Location::applies($termIn, $plain, $admin));
$topLevel = ['location' => Location::normalize(['all' => [['field' => 'post_parent', 'operator' => 'equals', 'value' => '0']]])];
check('post_parent values are cast to int', true, Location::applies($topLevel, $plain, $admin));
check('live overrides replace the stored state', true, Location::matches($group, Location::context($plain, $admin, null, ['post_terms' => [$featuredId]])));

$data = EditorIntegration::data(get_post($plain));
$entry = $entryOf($data, 'event_featured_extras');
check('data sends a group that only statically matches', true, $entry !== null);
check('the entry carries its normalised location', $group['location'], $entry['location'] ?? null);
check('data lists the REST taxonomies', [['slug' => 'event_type', 'restBase' => 'event_type']], $data['taxonomies'] ?? null);
check('data carries the static location context', ['post_type' => 'event', 'post_id' => $plain, 'user_role' => ['administrator']], $data['context'] ?? null);

global $wp_meta_boxes;
$wp_meta_boxes = [];
do_action('add_meta_boxes', 'event', get_post($plain));
check('the box is registered for live toggling', true, isset($wp_meta_boxes['event']['normal']['default']['wireframe-group-event_featured_extras']));
check('a box whose group does not apply starts hidden', ['wireframe-group-inapplicable'], apply_filters('postbox_classes_event_wireframe-group-event_featured_extras', []));
EditorIntegration::flush();
$wp_meta_boxes = [];
do_action('add_meta_boxes', 'event', get_post($tagged));
check('a box whose group applies starts shown', [], apply_filters('postbox_classes_event_wireframe-group-event_featured_extras', []));

$response = rest('POST', "/wp/v2/event/$plain", ['meta' => ['featured_blurb' => 'Hi']]);
check('writing a field whose group does not apply is refused', 403, $response->get_status());
check('nothing was written', '', get_post_meta($plain, 'featured_blurb', true));

$response = rest('POST', "/wp/v2/event/$plain", ['event_type' => [$featuredId], 'meta' => ['featured_blurb' => 'Hi']]);
check('assigning the term in the same request allows the write', 200, $response->get_status());
check('the value is stored', 'Hi', get_post_meta($plain, 'featured_blurb', true));

$response = rest('POST', "/wp/v2/event/$plain", ['event_type' => []]);
check('removing the term saves', 200, $response->get_status());
check('a group that stops applying keeps its values', 'Hi', get_post_meta($plain, 'featured_blurb', true));
check('its field is no longer readable', false, array_key_exists('featured_blurb', $response->get_data()['meta'] ?? []));

FieldGroups::register('wf-test', [
    'id'       => 'featured_required',
    'title'    => 'Featured required',
    'location' => ['all' => [
        ['field' => 'post_type', 'operator' => 'equals', 'value' => 'event'],
        ['field' => 'post_terms', 'operator' => 'contains', 'value' => $featuredId],
    ]],
    'config'   => ['fields' => [['id' => 'featured_ticket', 'type' => 'text', 'required' => true]]],
]);
EditorIntegration::flush();

$valid   = ['event_starts' => '2026-11-01', 'ticket_url' => 'https://example.com/tickets'];
$publish = $event();
$response = rest('POST', "/wp/v2/event/$publish", ['status' => 'publish', 'meta' => $valid]);
check('publishing without the term skips the featured group', 200, $response->get_status());

$publish  = $event();
$response = rest('POST', "/wp/v2/event/$publish", ['status' => 'publish', 'event_type' => [$featuredId], 'meta' => $valid]);
check('publishing while adding the term validates the group it brings in', 400, $response->get_status());
check('the error names its required field', true, isset($response->get_data()['data']['errors']['featured_ticket']));

$response = rest('POST', "/wp/v2/event/$publish", ['status' => 'publish', 'event_type' => [$featuredId], 'meta' => $valid + ['featured_ticket' => 'VIP']]);
check('filling it in the same request publishes', 200, $response->get_status());
check('and stores it', 'VIP', get_post_meta($publish, 'featured_ticket', true));

$tagged2 = $event($valid);
wp_set_object_terms($tagged2, [$featuredId], 'event_type');
$response = rest('POST', "/wp/v2/event/$tagged2", ['status' => 'publish', 'event_type' => []]);
check('publishing while removing the term skips the group it leaves', 200, $response->get_status());

check(
    'validate takes live overrides',
    true,
    isset(SaveValidator::validate($plain, 'event', $valid, 'publish', $admin, ['post_terms' => [$featuredId]])['featured_ticket'])
);

register_taxonomy_for_object_type('event_type', 'classic_event');
FieldGroups::register('wf-test', [
    'id'       => 'classic_featured',
    'title'    => 'Classic featured',
    'location' => ['all' => [
        ['field' => 'post_type', 'operator' => 'equals', 'value' => 'classic_event'],
        ['field' => 'post_terms', 'operator' => 'contains', 'value' => $featuredId],
    ]],
    'config'   => ['fields' => [['id' => 'classic_ticket', 'type' => 'text', 'required' => true]]],
]);
EditorIntegration::flush();

$classic   = (int) wp_insert_post(['post_type' => 'classic_event', 'post_status' => 'draft', 'post_title' => 'Classic', 'meta_input' => $valid]);
$created[] = $classic;
$postarr   = static fn (array $groups, array $extra = []): array => wp_slash(array_merge([
    'ID'               => $classic,
    'post_ID'          => $classic,
    '_wireframe_nonce' => wp_create_nonce('wireframe_classic_save'),
    'wireframe_fields' => array_map('wp_json_encode', $groups),
], $extra));
$guarded = static fn (array $postarr): string => ClassicSave::guardStatus(['post_status' => 'publish', 'post_type' => 'classic_event'], $postarr)['post_status'];

check('classic: publishing while ticking the term enforces its group', 'draft', $guarded($postarr([], ['tax_input' => ['event_type' => [0, $featuredId]]])));
check('classic: the value filled in the same submit counts', 'publish', $guarded($postarr(['classic_featured' => ['classic_ticket' => 'VIP']], ['tax_input' => ['event_type' => [0, $featuredId]]])));
check('classic: without the term the group is skipped', 'publish', $guarded($postarr([])));

$classicPost = get_post($classic);
check('classic payload leaves out a group that does not apply on load', false, $entryOf(ClassicEditor::data($classicPost), 'classic_featured') !== null);
wp_set_object_terms($classic, [$featuredId], 'event_type');
EditorIntegration::flush();
check('classic payload includes it once it applies', true, $entryOf(ClassicEditor::data($classicPost), 'classic_featured') !== null);

register_post_type('live_doc', ['label' => 'Live docs', 'public' => true, 'show_in_rest' => true, 'supports' => ['title']]);
FieldGroups::register('wf-test', [
    'id'       => 'live_doc_content',
    'location' => ['all' => [
        ['field' => 'post_type', 'operator' => 'equals', 'value' => 'live_doc'],
        ['field' => 'post_status', 'operator' => 'equals', 'value' => 'draft'],
    ]],
    'display'  => ['position' => 'content'],
    'config'   => ['fields' => [['id' => 'live_body', 'type' => 'textarea']]],
]);
$draftDoc     = (int) wp_insert_post(['post_type' => 'live_doc', 'post_status' => 'draft', 'post_title' => 'Doc']);
$publishedDoc = (int) wp_insert_post(['post_type' => 'live_doc', 'post_status' => 'publish', 'post_title' => 'Doc']);
$created      = [...$created, $draftDoc, $publishedDoc];
$lock         = static fn (int $id): mixed => apply_filters('block_editor_settings_all', [], new WP_Block_Editor_Context(['post' => get_post($id)]))['templateLock'] ?? null;

check('a content group that applies locks the canvas', 'all', $lock($draftDoc));
check('a content group that only statically matches leaves it unlocked', null, $lock($publishedDoc));
check('the published post still gets the group for live toggling', true, $entryOf(EditorIntegration::data(get_post($publishedDoc)), 'live_doc_content') !== null);

foreach ($created as $id) {
    wp_delete_post($id, true);
}

finish();
