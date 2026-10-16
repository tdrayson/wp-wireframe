<?php

use Wireframe\Framework\Fields\NumberField;
use Wireframe\PostFields\FieldGroups;

require __DIR__ . '/assert.php';

$save = static fn (int $id, array $meta): WP_REST_Response => rest('POST', "/wp/v2/event/$id", ['meta' => $meta]);

as_user('administrator');

$registered = array_keys(get_registered_meta_keys('post', 'event'));
sort($registered);
$expected = ['admin_notes', 'event_starts', 'gallery', 'genres', 'is_featured', 'logo', 'price', 'public_tagline', 'secret_code', 'sessions', 'ticket_url'];
check('every top-level field registered', $expected, $registered);

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

$published = make_event(['secret_code' => 's3cret', 'public_tagline' => 'Hi', 'genres' => 'jazz'], 'publish');

as_user('');
$meta = rest('GET', "/wp/v2/event/$published")->get_data()['meta'] ?? [];
check('logged out: secret_code hidden', false, array_key_exists('secret_code', $meta));
check('logged out: multi-value genres hidden', false, array_key_exists('genres', $meta));
check('logged out: public_tagline shown', 'Hi', $meta['public_tagline'] ?? null);

as_user('editor');
$meta = rest('GET', "/wp/v2/event/$published", [], ['context' => 'edit'])->get_data()['meta'] ?? [];
check('editor, edit context: secret_code present', 's3cret', $meta['secret_code'] ?? null);
check('editor, edit context: admin_notes absent', false, array_key_exists('admin_notes', $meta));
check('editor cannot write admin_notes', 403, $save($published, ['admin_notes' => 'x'])->get_status());

as_user('administrator');
$meta = rest('GET', "/wp/v2/event/$published", [], ['context' => 'edit'])->get_data()['meta'] ?? [];
check('administrator, edit context: admin_notes present', true, array_key_exists('admin_notes', $meta));

check('revisions track event_starts', true, in_array('event_starts', wp_post_revision_meta_keys('event'), true));

finish();
