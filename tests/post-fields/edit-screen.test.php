<?php

use Wireframe\PostFields\EditScreen;

require __DIR__ . '/assert.php';

$fieldIds = static function (array $config): array {
    $ids = [];

    foreach ($config['tabs'] ?? [] as $tab) {
        foreach ($tab['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $ids[] = $field['id'];
            }
        }
    }

    return $ids;
};

$sectionIds = static fn (array $config): array => array_merge(...array_map(
    static fn (array $tab): array => array_column($tab['sections'] ?? [], 'id'),
    $config['tabs'] ?? []
));

as_user('administrator');
$id   = make_event();
$data = EditScreen::data(get_post($id));

check('admin sees the tabs', ['Details', 'Schedule'], array_column($data['config']['tabs'], 'title'));
check('admin sees admin_notes', true, in_array('admin_notes', $fieldIds($data['config']), true));
check('admin sees the internal section', true, in_array('internal', $sectionIds($data['config']), true));
check('post id', $id, $data['postId']);
check('post type', 'event', $data['postType']);
check('rest base', 'event', $data['restBase']);
check('draft is not new', false, $data['isNew']);
check('admin can publish', true, $data['canPublish']);
check('list url', admin_url('edit.php?post_type=event'), $data['listUrl']);
check('supports', ['title' => true, 'thumbnail' => true, 'author' => false], $data['supports']);
check('first taxonomy slug', 'event_type', $data['taxonomies'][0]['slug'] ?? null);
check('taxonomy shape', ['slug' => 'event_type', 'restBase' => 'event_type', 'hierarchical' => true, 'label' => 'Event types'], $data['taxonomies'][0] ?? null);
check('unlocked post has no holder', null, $data['lock']['holder']);
check('auto-draft is new', true, EditScreen::data(get_default_post_to_edit('event', true))['isNew']);

as_user('editor');
$data = EditScreen::data(get_post($id));
check('editor does not receive admin_notes', false, in_array('admin_notes', $fieldIds($data['config']), true));
check('editor does not receive the internal section', false, in_array('internal', $sectionIds($data['config']), true));
check('editor does not receive its field either', false, in_array('internal_ref', $fieldIds($data['config']), true));
check('editor keeps public fields', true, in_array('public_tagline', $fieldIds($data['config']), true));

$other = as_user('author');
wp_set_post_lock($id);
as_user('editor');
$lock = EditScreen::data(get_post($id))['lock'];

check('lock holder id', $other, $lock['holder']['id'] ?? null);
check('lock holder name', get_userdata($other)->display_name, $lock['holder']['name'] ?? null);
check('lock holder avatar is a url', true, is_string($lock['holder']['avatar'] ?? null) && $lock['holder']['avatar'] !== '');
check('lock value is the stored lock', get_post_meta($id, '_edit_lock', true), $lock['value']);

$url = $lock['takeOverUrl'];
parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
check('take-over url targets the edit link', true, str_starts_with($url, admin_url('post.php')));
check('take-over url asks for the lock', '1', $query['get-post-lock'] ?? null);
check('take-over url nonce is valid', 1, wp_verify_nonce($query['_wpnonce'] ?? '', 'lock-post_' . $id));

check('unowned post type is left alone', false, EditScreen::maybeReplace(false, get_post(make_post_of('post'))));
check('unowned post type keeps a true from another filter', true, EditScreen::maybeReplace(true, get_post(make_post_of('post'))));

check('owned type during screen construction answers true without rendering', [true, ''], (static function () use ($id): array {
    ob_start();
    $result = EditScreen::maybeReplace(false, get_post($id));

    return [$result, ob_get_clean()];
})());

finish();

function make_post_of(string $type): int
{
    return (int) wp_insert_post(['post_type' => $type, 'post_status' => 'draft', 'post_title' => 'Plain']);
}
