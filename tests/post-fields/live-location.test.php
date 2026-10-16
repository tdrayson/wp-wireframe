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
check('data carries the static location context', ['post_type' => 'event', 'post_id' => $plain, 'user_role' => ['administrator'], 'post_terms' => []], $data['context'] ?? null);

global $wp_meta_boxes;
$wp_meta_boxes = [];
do_action('add_meta_boxes', 'event', get_post($plain));
check('the box is registered for live toggling', true, isset($wp_meta_boxes['event']['normal']['default']['wireframe-group-wf-fixture__event_featured_extras']));
check('a box whose group does not apply starts hidden', ['wireframe-group-inapplicable'], apply_filters('postbox_classes_event_wireframe-group-wf-fixture__event_featured_extras', []));
EditorIntegration::flush();
$wp_meta_boxes = [];
do_action('add_meta_boxes', 'event', get_post($tagged));
check('a box whose group applies starts shown', [], apply_filters('postbox_classes_event_wireframe-group-wf-fixture__event_featured_extras', []));

$response = rest('POST', "/wp/v2/event/$plain", ['meta' => ['featured_blurb' => 'Hi']]);
check('writing a field whose group does not apply is refused', 403, $response->get_status());
check('nothing was written', '', get_post_meta($plain, 'featured_blurb', true));

$response = rest('POST', "/wp/v2/event/$plain", ['event_type' => [$featuredId], 'meta' => ['featured_blurb' => 'Hi']]);
check('assigning the term in the same request allows the write', 200, $response->get_status());
check('the value is stored', 'Hi', get_post_meta($plain, 'featured_blurb', true));

$response = rest('POST', "/wp/v2/event/$plain", ['event_type' => []]);
check('removing the term saves', 200, $response->get_status());
check('a group that stops applying keeps its values', 'Hi', get_post_meta($plain, 'featured_blurb', true));
check('an edit-context read still carries it, for live toggling', 'Hi', $response->get_data()['meta']['featured_blurb'] ?? null);

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
    'wireframe_fields' => array_combine(array_map(static fn (string $id): string => "wf-test__$id", array_keys($groups)), array_map('wp_json_encode', $groups)),
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
check('a content group that only statically matches leaves it unlocked', false, $lock($publishedDoc));
check('the published post still gets the group for live toggling', true, $entryOf(EditorIntegration::data(get_post($publishedDoc)), 'live_doc_content') !== null);

// R25: edit-context reads relax live rules; view reads and access still gate.
FieldGroups::register('wf-test', [
    'id'       => 'featured_public',
    'title'    => 'Featured public',
    'location' => ['all' => [
        ['field' => 'post_type', 'operator' => 'equals', 'value' => 'event'],
        ['field' => 'post_terms', 'operator' => 'contains', 'value' => $featuredId],
    ]],
    'config'   => ['fields' => [
        ['id' => 'featured_tagline', 'type' => 'text', 'public' => true],
        ['id' => 'featured_secret', 'type' => 'text', 'access' => ['view' => 'administrator', 'edit' => 'administrator']],
    ]],
]);
EditorIntegration::flush();

$stored = $event($valid + ['featured_blurb' => 'Kept', 'featured_tagline' => 'Shown', 'featured_secret' => 'Hush'], 'publish');
as_user('editor');
$editMeta = rest('GET', "/wp/v2/event/$stored", [], ['context' => 'edit'])->get_data()['meta'] ?? [];
check('edit read: a stored value of a group that does not apply yet is sent', 'Kept', $editMeta['featured_blurb'] ?? null);
check('edit read: a field the user cannot view is still withheld', false, array_key_exists('featured_secret', $editMeta));
as_user('guest');
$viewMeta = rest('GET', "/wp/v2/event/$stored")->get_data()['meta'] ?? [];
check('view read: a public field of a group that does not apply is withheld', false, array_key_exists('featured_tagline', $viewMeta));
wp_set_object_terms($stored, [$featuredId], 'event_type');
$viewMeta = rest('GET', "/wp/v2/event/$stored")->get_data()['meta'] ?? [];
check('view read: it is public once the group applies', 'Shown', $viewMeta['featured_tagline'] ?? null);
wp_set_object_terms($stored, [], 'event_type');
as_user('administrator');
check('edit read: an admin gets the access-gated field', 'Hush', rest('GET', "/wp/v2/event/$stored", [], ['context' => 'edit'])->get_data()['meta']['featured_secret'] ?? null);

$echo = rest('POST', "/wp/v2/event/$stored", ['meta' => ['featured_blurb' => 'Kept', 'featured_secret' => 'Hush', 'price' => 5]]);
check('echoing the stored values of a group that does not apply saves', 200, $echo->get_status());
check('the rest of the save is written', '5', get_post_meta($stored, 'price', true));
$unset = $event();
$echo  = rest('POST', "/wp/v2/event/$unset", ['meta' => ['featured_blurb' => null, 'price' => 6]]);
check('echoing the null of a never-stored field that does not apply saves', 200, $echo->get_status());
check('and writes no row for it', false, metadata_exists('post', $unset, 'featured_blurb'));

// Native template lock, restored when the content group stops applying.
register_post_type('native_doc', ['label' => 'Native docs', 'public' => true, 'show_in_rest' => true, 'supports' => ['title', 'editor'], 'template' => [['core/paragraph']], 'template_lock' => 'insert']);
FieldGroups::register('wf-test', [
    'id'       => 'native_doc_content',
    'location' => ['all' => [
        ['field' => 'post_type', 'operator' => 'equals', 'value' => 'native_doc'],
        ['field' => 'post_status', 'operator' => 'equals', 'value' => 'draft'],
    ]],
    'display'  => ['position' => 'content'],
    'config'   => ['fields' => [['id' => 'native_body', 'type' => 'textarea']]],
]);
register_post_type('boxed_doc', ['label' => 'Boxed docs', 'public' => true, 'show_in_rest' => true, 'supports' => ['title', 'editor'], 'template' => [['core/paragraph']], 'template_lock' => 'all']);
FieldGroups::register('wf-test', ['id' => 'boxed_doc_box', 'location' => ['boxed_doc'], 'config' => ['fields' => [['id' => 'boxed_note', 'type' => 'text']]]]);
$nativeDraft     = (int) wp_insert_post(['post_type' => 'native_doc', 'post_status' => 'draft', 'post_title' => 'Doc']);
$nativePublished = (int) wp_insert_post(['post_type' => 'native_doc', 'post_status' => 'publish', 'post_title' => 'Doc']);
$boxed           = (int) wp_insert_post(['post_type' => 'boxed_doc', 'post_status' => 'draft', 'post_title' => 'Doc']);
$created         = [...$created, $nativeDraft, $nativePublished, $boxed];
check('the payload carries the native lock of a content post type', 'insert', EditorIntegration::data(get_post($nativePublished))['nativeLock'] ?? null);
check('an applying content group still locks fully', 'all', $lock($nativeDraft));
check('a content group that stops applying restores the native lock', 'insert', $lock($nativePublished));
check('the payload carries the native lock of a post type without a content group', 'all', EditorIntegration::data(get_post($boxed))['nativeLock'] ?? null);
check('without a content group the lock is left alone', 'untouched', apply_filters('block_editor_settings_all', ['templateLock' => 'untouched'], new WP_Block_Editor_Context(['post' => get_post($boxed)]))['templateLock'] ?? null);

// Non-REST taxonomies: their stored terms ride along in the static context.
register_taxonomy('event_private', 'event', ['label' => 'Private', 'show_in_rest' => false]);
$private   = wp_insert_term('Hidden tag', 'event_private');
$privateId = (int) $private['term_id'];
$withPrivate = $event();
wp_set_object_terms($withPrivate, [$privateId], 'event_private');
wp_set_object_terms($withPrivate, [$featuredId], 'event_type');
EditorIntegration::flush();
$payload = EditorIntegration::data(get_post($withPrivate));
check('context carries the stored terms of non-REST taxonomies only', [$privateId], $payload['context']['post_terms'] ?? null);
check('non-REST taxonomies are not listed for live terms', ['event_type'], array_column($payload['taxonomies'], 'slug'));

// REST live overrides through rest_pre_insert.
register_post_type('live_page', ['label' => 'Live pages', 'public' => true, 'hierarchical' => true, 'show_in_rest' => true, 'supports' => ['title', 'page-attributes', 'post-formats']]);
$liveRule = static fn (string $id, string $param, mixed $value): array => [
    'id'       => $id,
    'location' => ['all' => [['field' => 'post_type', 'operator' => 'equals', 'value' => 'live_page'], ['field' => $param, 'operator' => 'equals', 'value' => $value]]],
    'config'   => ['fields' => [['id' => "{$id}_field", 'type' => 'text', 'required' => true]]],
];
$parentPage = (int) wp_insert_post(['post_type' => 'live_page', 'post_status' => 'publish', 'post_title' => 'Parent']);
$livePage   = (int) wp_insert_post(['post_type' => 'live_page', 'post_status' => 'draft', 'post_title' => 'Child']);
$created    = [...$created, $livePage, $parentPage];
FieldGroups::register('wf-test', $liveRule('by_status', 'post_status', 'publish'));
FieldGroups::register('wf-test', $liveRule('by_template', 'post_template', 'tpl-wide'));
FieldGroups::register('wf-test', $liveRule('by_parent', 'post_parent', $parentPage));
FieldGroups::register('wf-test', $liveRule('by_format', 'post_format', 'aside'));
$preInsert = static function (array $prepared, array $params) use ($livePage): array {
    $request = new WP_REST_Request('POST', "/wp/v2/live_page/$livePage");
    $request->set_body_params($params);
    $result = apply_filters('rest_pre_insert_live_page', (object) (['ID' => $livePage, 'post_type' => 'live_page'] + $prepared), $request);

    return is_wp_error($result) ? array_keys($result->get_error_data()['errors']) : [];
};
check('rest: publishing alone brings in the status group', ['by_status_field'], $preInsert(['post_status' => 'publish'], ['status' => 'publish']));
check(
    'rest: the submitted template, parent and format bring in their groups',
    ['by_status_field', 'by_template_field', 'by_parent_field', 'by_format_field'],
    $preInsert(['post_status' => 'publish', 'post_parent' => $parentPage], ['status' => 'publish', 'template' => 'tpl-wide', 'parent' => $parentPage, 'format' => 'aside'])
);

// Classic guard: submitted page_template, post_format and post_parent.
foreach (['by_template' => ['post_template', 'tpl-wide'], 'by_parent' => ['post_parent', $parentPage], 'by_format' => ['post_format', 'aside']] as $id => [$param, $value]) {
    $rule = $liveRule("classic_$id", $param, $value);
    $rule['location']['all'][0]['value'] = 'classic_event';
    FieldGroups::register('wf-test', $rule);
}
EditorIntegration::flush();
$plainClassic = (int) wp_insert_post(['post_type' => 'classic_event', 'post_status' => 'draft', 'post_title' => 'Classic', 'meta_input' => $valid]);
$created[]    = $plainClassic;
$classicGuard = static fn (array $data, array $extra): string => ClassicSave::guardStatus(['post_status' => 'publish', 'post_type' => 'classic_event'] + $data, wp_slash([
    'ID'               => $plainClassic,
    'post_ID'          => $plainClassic,
    '_wireframe_nonce' => wp_create_nonce('wireframe_classic_save'),
    'wireframe_fields' => [],
] + $extra))['post_status'];
check('classic: no live changes publishes', 'publish', $classicGuard([], []));
check('classic: a submitted page_template brings in its group', 'draft', $classicGuard([], ['page_template' => 'tpl-wide']));
check('classic: the default template does not', 'publish', $classicGuard([], ['page_template' => 'default']));
check('classic: a submitted post_format brings in its group', 'draft', $classicGuard([], ['post_format' => 'aside']));
check('classic: a submitted post_parent brings in its group', 'draft', $classicGuard(['post_parent' => $parentPage], []));

wp_delete_term($privateId, 'event_private');

foreach ($created as $id) {
    wp_delete_post($id, true);
}

finish();
