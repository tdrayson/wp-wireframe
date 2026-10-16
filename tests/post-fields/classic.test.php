<?php

use Wireframe\PostFields\ClassicEditor;

require __DIR__ . '/assert.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

$make = static function (array $meta = [], string $status = 'draft'): int {
    return (int) wp_insert_post([
        'post_type'   => 'classic_event',
        'post_status' => $status,
        'post_title'  => 'Classic',
        'meta_input'  => $meta,
    ]);
};

// Posts the classic edit form as core's post.php would, slashed, through edit_post().
$classicSave = static function (int $id, array $groups, array $extra = [], ?string $nonce = null): void {
    $_POST = wp_slash(array_merge([
        'post_ID'          => $id,
        'post_title'       => 'Classic',
        'post_status'      => get_post_status($id),
        '_wireframe_nonce' => $nonce ?? wp_create_nonce('wireframe_classic_save'),
        'wireframe_fields' => array_map('wp_json_encode', $groups),
    ], $extra));

    edit_post();
    $_POST = [];
};

$boxIds = static function (string $postType): array {
    $ids = [];

    foreach ($GLOBALS['wp_meta_boxes'][$postType] ?? [] as $priorities) {
        foreach ($priorities as $boxes) {
            $ids = [...$ids, ...array_keys($boxes)];
        }
    }

    return $ids;
};

$publish   = ['publish' => 'Publish'];
$transient = static fn (int $id): mixed => get_transient('wireframe_errors_' . get_current_user_id() . "_$id");
$valid     = ['event_starts' => '2026-11-01', 'ticket_url' => 'https://example.com/tickets'];

$admin = as_user('administrator');

$id = $make();
$classicSave($id, ['event_details' => ['price' => 15, 'genres' => ['jazz', 'folk']]]);
check('a valid save writes the scalar', '15', get_post_meta($id, 'price', true));
check('a valid save writes one row per multi value', ['jazz', 'folk'], get_post_meta($id, 'genres'));
check('a valid draft save leaves no errors', false, $transient($id));
$classicSave($id, ['event_details' => ['price' => null]]);
check('a null value deletes the meta, as over REST', false, metadata_exists('post', $id, 'price'));

$id = $make();
$classicSave($id, ['event_details' => ['price' => 15]], [], '');
check('no nonce: nothing written', '', get_post_meta($id, 'price', true));
$classicSave($id, ['event_details' => ['price' => 15]], [], 'bogus');
check('a bad nonce: nothing written', '', get_post_meta($id, 'price', true));

$id = $make();
$classicSave($id, ['event_details' => ['seo_title' => 'Smuggled', 'price' => 4]]);
check('a field posted under a group that does not own it is not written', '', get_post_meta($id, 'seo_title', true));
check('the owning group\'s field in the same payload is written', '4', get_post_meta($id, 'price', true));

$attachment = (int) wp_insert_attachment(['post_title' => 'Logo', 'post_mime_type' => 'image/png'], 'logo.png');
$classicSave($id, [
    'event_details' => ['logo' => $attachment, 'sessions' => [['venue' => 'Hall', 'seats' => 40]]],
    'event_seo'     => ['seo_title' => 'Seen', 'seo_noindex' => true],
]);
check('a single file stores its id', (string) $attachment, get_post_meta($id, 'logo', true));
check('a repeater stores its rows', 'Hall', get_post_meta($id, 'sessions', true)[0]['venue'] ?? null);
check('a sidebar group saves', ['Seen', '1'], [get_post_meta($id, 'seo_title', true), get_post_meta($id, 'seo_noindex', true)]);

as_user('editor');
$id = $make();
$classicSave($id, [
    'event_details' => ['admin_notes' => 'Editor wrote this', 'price' => 9],
    'event_extras'  => ['extra_note' => 'Not for editors'],
]);
check('editor: a field they cannot edit is not written', '', get_post_meta($id, 'admin_notes', true));
check('editor: a field from a group whose location does not apply is not written', '', get_post_meta($id, 'extra_note', true));
check('editor: their editable field is written', '9', get_post_meta($id, 'price', true));

as_user('administrator');
$id = $make();
$classicSave($id, ['event_details' => ['ticket_url' => $valid['ticket_url'], 'price' => 12]], $publish);
check('publishing with a required field empty keeps the post a draft', 'draft', get_post_status($id));
check('the errors transient names the field', true, !empty($transient($id)['errors']['event_starts']));
check('valid values are still written', '12', get_post_meta($id, 'price', true));
check('the kept draft\'s date floats again', '0000-00-00 00:00:00', get_post($id)->post_date_gmt);
check(
    'the redirect reports a draft update, not a publish',
    'post.php?post=1&action=edit&message=10',
    apply_filters('redirect_post_location', 'post.php?post=1&action=edit&message=6', $id)
);

$id = $make();
$classicSave($id, ['event_details' => []], $publish);
check('publishing with nothing edited still reports why the post stayed a draft', ['draft', true], [get_post_status($id), !empty($transient($id)['errors']['event_starts'])]);

$draftId = $make();
$classicSave($draftId, ['event_details' => ['ticket_url' => $valid['ticket_url'], 'price' => 12]]);
check('the same values saved as a draft write', '12', get_post_meta($draftId, 'price', true));
check('a draft save sets no errors', false, $transient($draftId));
check('the redirect of a draft save is left alone', 'post.php?message=7', apply_filters('redirect_post_location', 'post.php?message=7', $draftId));

$classicSave($draftId, ['event_details' => ['ticket_url' => 'not a url']]);
check('an invalid value is not written', $valid['ticket_url'], get_post_meta($draftId, 'ticket_url', true));
check('an invalid value on a draft is reported', true, !empty($transient($draftId)['errors']['ticket_url']));

$id = $make();
$classicSave($id, ['event_details' => $valid], $publish);
check('filling the required fields publishes', 'publish', get_post_status($id));
check('a successful publish sets no errors', false, $transient($id));

$id = $make(['ticket_url' => 'https://example.com/old']);
$classicSave($id, ['event_details' => ['is_featured' => false, 'event_starts' => '2026-11-01']], $publish);
check('a required field hidden by a condition does not block publishing', 'publish', get_post_status($id));
check('the hidden field keeps its stored value', 'https://example.com/old', get_post_meta($id, 'ticket_url', true));

$id = $make($valid, 'publish');
$classicSave($id, ['event_details' => ['event_starts' => '', 'price' => 3]]);
check('updating a published post with a required field emptied keeps it published', 'publish', get_post_status($id));
check('the emptied required value is not written', '2026-11-01', get_post_meta($id, 'event_starts', true));
check('the published update is reported', true, !empty($transient($id)['errors']['event_starts']));

$id = $make();
$classicSave($id, ['event_details' => ['price' => 1]], $publish);
$post = get_post($id);
$data = ClassicEditor::data($post);
$byId = array_column($data['groups'], null, 'id');
check('localised errors carry the failed save', true, !empty($data['errors']['event_starts']));
check('errors are read once', false, $transient($id));
check('groups carry their values', 1, $byId['event_details']['values']['price'] ?? null);
check('a group\'s values hold only its own fields', false, array_key_exists('seo_title', $byId['event_details']['values'] ?? []));
check('values default like the editor', true, $byId['event_details']['values']['is_featured'] ?? null);
check('a saved post is not new', false, $data['isNew']);
check('an auto-draft is new', true, ClassicEditor::data(get_post($make([], 'auto-draft')))['isNew']);

set_current_screen('classic_event');
$GLOBALS['post'] = $post;
ob_start();
do_action('admin_notices');
check('the failed save shows an admin notice', true, str_contains((string) ob_get_clean(), 'Some fields need attention.'));

as_user('editor');
check('editor: values hold no field they cannot view', false, array_key_exists('admin_notes', array_column(ClassicEditor::data($post)['groups'], null, 'id')['event_details']['values']));
as_user('administrator');

global $wp_meta_boxes;
$wp_meta_boxes = [];
do_action('add_meta_boxes', 'classic_event', $post);
check('a sidebar group registers a side box', 'SEO', $wp_meta_boxes['classic_event']['side']['low']['wireframe-group-event_seo']['title'] ?? null);
check('a meta_box group registers a normal box', 'Extras', $wp_meta_boxes['classic_event']['normal']['default']['wireframe-group-event_extras']['title'] ?? null);
check('a content group registers no box', false, in_array('wireframe-group-event_details', $boxIds('classic_event'), true));

$box = $wp_meta_boxes['classic_event']['side']['low']['wireframe-group-event_seo'];
ob_start();
call_user_func($box['callback'], $post, $box);
$boxHtml = (string) ob_get_clean();
check('a box prints its mount', true, str_contains($boxHtml, 'class="wireframe-group"'));
check('a box prints its hidden input', true, str_contains($boxHtml, 'name="wireframe_fields[event_seo]"'));

ob_start();
do_action('edit_form_after_title', $post);
$afterTitle = (string) ob_get_clean();
check('the content group prints after the title', true, str_contains($afterTitle, 'id="wireframe-content-event_details"'));
check('the content group prints its hidden input', true, str_contains($afterTitle, 'name="wireframe_fields[event_details]"'));
check('the nonce prints once', 1, substr_count($afterTitle, 'name="_wireframe_nonce"'));

$fresh = as_user('author');
delete_user_option($fresh, 'closedpostboxes_classic_event');
do_action('add_meta_boxes', 'classic_event', get_post($make()));
check('a collapsed sidebar box starts closed', ['wireframe-group-event_seo'], get_user_option('closedpostboxes_classic_event', $fresh));

as_user('administrator');
$event = get_post(make_event());
$wp_meta_boxes = [];
do_action('add_meta_boxes', 'event', $event);
check('block editor post: no side box', false, isset($wp_meta_boxes['event']['side']));
check('block editor post: its meta box stays', true, isset($wp_meta_boxes['event']['normal']['default']['wireframe-group-event_extras']));
ob_start();
do_action('edit_form_after_title', $event);
check('block editor post: nothing printed after the title', '', ob_get_clean());

check('a registered field key is protected meta', true, is_protected_meta('admin_notes', 'post'));
check('a sidebar group key is protected meta', true, is_protected_meta('seo_title', 'post'));
check('an unregistered key stays unprotected', false, is_protected_meta('some_other_key', 'post'));
check('other meta types are left alone', false, is_protected_meta('admin_notes', 'user'));

finish();
