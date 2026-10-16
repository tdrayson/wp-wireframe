<?php

use Wireframe\App;
use Wireframe\Framework\Fields\NumberField;
use Wireframe\PostFields\FieldGroups;

require __DIR__ . '/assert.php';

// The fixture CPT has no editor support, so autosaves need a type that has one. REST routes are built on the first request.
register_post_type('memo', ['public' => true, 'show_in_rest' => true, 'supports' => ['title', 'editor', 'revisions']]);
App::boot(['prefix' => 'memo', 'field_groups' => [[
    'id'       => 'memo_group',
    'title'    => 'Memo',
    'location' => ['memo'],
    'config'   => ['fields' => [['id' => 'memo_secret', 'type' => 'text', 'access' => 'administrator']]],
]]]);

$revisionsHave = static function (string $route, string $key): bool {
    $items = (array) rest('GET', $route, [], ['context' => 'edit'])->get_data();
    $hits  = array_filter($items, static fn ($item): bool => is_array($item) && array_key_exists($key, $item['meta'] ?? []));

    return $hits !== [];
};

$save = static fn (int $id, array $meta): WP_REST_Response => rest('POST', "/wp/v2/event/$id", ['meta' => $meta]);

as_user('administrator');

$registered = array_keys(get_registered_meta_keys('post', 'event'));
sort($registered);
$expected = ['admin_notes', 'event_starts', 'event_tags', 'extra_note', 'gallery', 'genres', 'host', 'internal_ref', 'is_featured', 'logo', 'price', 'public_tagline', 'secret_code', 'seo_noindex', 'seo_title', 'sessions', 'speakers', 'ticket_url'];
check('every top-level field of every event group registered', $expected, $registered);
check('post group registered on post', true, array_key_exists('post_subtitle', get_registered_meta_keys('post', 'post')));
check('post group not registered on event', false, in_array('post_subtitle', $registered, true));
check('sidebar toggle schema is boolean', 'boolean', get_registered_meta_keys('post', 'event')['seo_noindex']['type'] ?? null);

check('scalar default via get_post_meta', true, (bool) get_post_meta(make_event(), 'is_featured', true));

$id = make_event();

check('checkboxes save accepted', 200, $save($id, ['genres' => ['jazz', 'folk']])->get_status());
$genres = get_post_meta($id, 'genres');
sort($genres);
check('checkboxes stored one row per value', ['folk', 'jazz'], $genres);
check('checkboxes row count', 2, count(get_post_meta($id, 'genres')));

$save($id, ['genres' => []]);
check('emptied checkboxes stay empty', [], get_post_meta($id, 'genres'));

$rows = [['starts' => '2026-11-01', 'venue' => 'Leeds', 'seats' => 40, 'notes' => '', 'tags' => ['a']]];
check('repeater save accepted', 200, $save($id, ['sessions' => $rows])->get_status());
check('repeater round-trip', 'Leeds', get_post_meta($id, 'sessions', true)[0]['venue'] ?? null);
$read = rest('GET', "/wp/v2/event/$id", [], ['context' => 'edit'])->get_data()['meta']['sessions'] ?? null;
check('repeater reads back through REST', ['a'], $read[0]['tags'] ?? null);

check('single file save accepted', 200, $save($id, ['logo' => 12])->get_status());
check('single file stored as scalar', '12', get_post_meta($id, 'logo', true));

check('multiple files save accepted', 200, $save($id, ['gallery' => [3, 1, 2]])->get_status());
check('multiple files keep order', [3, 1, 2], get_post_meta($id, 'gallery', true));

// A non-numeric string fails core's REST type check, so the sanitiser is exercised on direct writes.
check('REST rejects a non-numeric price', 400, $save($id, ['price' => '12abc'])->get_status());
update_post_meta($id, 'price', '12abc');
$priceArgs = FieldGroups::flatFields('event')['price']['args'] ?? [];
check('price sanitised by NumberField', (string) NumberField::sanitize('12abc', $priceArgs), get_post_meta($id, 'price', true));

// Saves to a published post must pass its required fields.
$published = make_event(['event_starts' => '2026-11-01', 'ticket_url' => 'https://example.com/tickets', 'secret_code' => 's3cret', 'public_tagline' => 'Hi', 'genres' => 'jazz', 'admin_notes' => 'private', 'internal_ref' => 'R1', 'extra_note' => 'hidden'], 'publish');
wp_update_post(['ID' => $published, 'post_title' => 'Event, revised']);

as_user('');
$meta = rest('GET', "/wp/v2/event/$published")->get_data()['meta'] ?? [];
check('logged out: secret_code hidden', false, array_key_exists('secret_code', $meta));
check('logged out: multi-value genres hidden', false, array_key_exists('genres', $meta));
check('logged out: public_tagline shown', 'Hi', $meta['public_tagline'] ?? null);

as_user('editor');
$meta = rest('GET', "/wp/v2/event/$published", [], ['context' => 'edit'])->get_data()['meta'] ?? [];
check('editor, edit context: secret_code present', 's3cret', $meta['secret_code'] ?? null);
check('editor, edit context: admin_notes absent', false, array_key_exists('admin_notes', $meta));
check('editor, edit context: field in admin-only section absent', false, array_key_exists('internal_ref', $meta));
check('editor cannot write admin_notes', 403, $save($published, ['admin_notes' => 'x'])->get_status());
check('editor cannot write a field in an admin-only section', 403, $save($published, ['internal_ref' => 'x'])->get_status());
check('editor, revisions: admin_notes absent', false, $revisionsHave("/wp/v2/event/$published/revisions", 'admin_notes'));
check('editor cannot write a field whose group location does not apply', 403, $save($published, ['extra_note' => 'x'])->get_status());
check('editor, edit context: field whose group location does not apply absent', false, array_key_exists('extra_note', $meta));
check('editor, revisions: field whose group location does not apply absent', false, $revisionsHave("/wp/v2/event/$published/revisions", 'extra_note'));
check('editor can write a field from another group', 200, $save($published, ['seo_title' => 'Hello'])->get_status());
check('editor, edit context: field from another group present', 'Hello', rest('GET', "/wp/v2/event/$published", [], ['context' => 'edit'])->get_data()['meta']['seo_title'] ?? null);

as_user('administrator');
$meta = rest('GET', "/wp/v2/event/$published", [], ['context' => 'edit'])->get_data()['meta'] ?? [];
check('administrator, edit context: admin_notes present', true, array_key_exists('admin_notes', $meta));
check('administrator, edit context: internal_ref present', 'R1', $meta['internal_ref'] ?? null);
check('administrator can write internal_ref', 200, $save($published, ['internal_ref' => 'R2'])->get_status());
check('administrator can write extra_note', 200, $save($published, ['extra_note' => 'N1'])->get_status());
check('administrator, edit context: extra_note present', 'N1', rest('GET', "/wp/v2/event/$published", [], ['context' => 'edit'])->get_data()['meta']['extra_note'] ?? null);
check('administrator, revisions: extra_note present', true, $revisionsHave("/wp/v2/event/$published/revisions", 'extra_note'));
$plain = (int) wp_insert_post(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Plain']);
check('post group writes on post', 200, rest('POST', "/wp/v2/posts/$plain", ['meta' => ['post_subtitle' => 'Sub']])->get_status());
check('post group stored on post', 'Sub', get_post_meta($plain, 'post_subtitle', true));
check('administrator, revisions: admin_notes present', true, $revisionsHave("/wp/v2/event/$published/revisions", 'admin_notes'));

check('revisions track event_starts', true, in_array('event_starts', wp_post_revision_meta_keys('event'), true));

register_post_type('late_meta', ['public' => true, 'show_in_rest' => true]);
App::boot(['prefix' => 'late-meta', 'field_groups' => [[
    'id'       => 'late_meta_group',
    'title'    => 'Late',
    'location' => ['late_meta'],
    'config'   => ['fields' => [['id' => 'late_field', 'type' => 'text']]],
]]]);
check('group claimed after wp_loaded gets its meta', true, array_key_exists('late_field', get_registered_meta_keys('post', 'late_meta')));

$memo = wp_insert_post(['post_type' => 'memo', 'post_status' => 'publish', 'post_title' => 'Memo', 'meta_input' => ['memo_secret' => 'a']]);
rest('POST', "/wp/v2/memo/$memo/autosaves", ['title' => 'Memo, autosaved', 'meta' => ['memo_secret' => 'b']]);
check('administrator, autosaves: gated field present', true, $revisionsHave("/wp/v2/memo/$memo/autosaves", 'memo_secret'));
as_user('editor');
check('editor, autosaves: gated field absent', false, $revisionsHave("/wp/v2/memo/$memo/autosaves", 'memo_secret'));

finish();
