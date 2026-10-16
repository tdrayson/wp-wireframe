<?php

use Wireframe\Framework\Access\AccessResolver;
use Wireframe\Framework\ConfigLoader;
use Wireframe\PostFields\EditorIntegration;
use Wireframe\PostFields\FieldGroups;
use Wireframe\PostFields\Location;

require __DIR__ . '/assert.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

$notices = [];
add_action('doing_it_wrong_run', static function (string $function, string $message) use (&$notices): void {
    $notices[] = $message;
}, 10, 2);

$mentions = static function (string $needle) use (&$notices): int {
    return count(array_filter($notices, static fn ($m) => str_contains($m, $needle)));
};

$fieldIds = static function (array $config): array {
    $ids = [];

    foreach ($config['tabs'] ?? [] as $tab) {
        foreach ($tab['sections'] ?? [] as $section) {
            $ids = [...$ids, ...array_column($section['fields'] ?? [], 'id')];
        }
    }

    return $ids;
};

$groupOf = static function (array $data, string $id): ?array {
    foreach ($data['groups'] as $group) {
        if ($group['id'] === $id) {
            return $group;
        }
    }

    return null;
};

$admin = as_user('administrator');
$event = get_post(make_event());
$data  = EditorIntegration::data($event);

check('payload keys', ['postId', 'postType', 'supportsTitle', 'debug', 'groups'], array_keys($data));
check('debug follows SCRIPT_DEBUG', defined('SCRIPT_DEBUG') && SCRIPT_DEBUG, $data['debug']);
check('post id', $event->ID, $data['postId']);
check('post type', 'event', $data['postType']);
check('event supports title', true, $data['supportsTitle']);
check('admin gets every event group, sorted', ['event_details', 'event_extras', 'event_seo'], array_column($data['groups'], 'id'));
check('positions', ['content', 'meta_box', 'sidebar'], array_column($data['groups'], 'position'));
check('group keys', ['id', 'title', 'position', 'collapsed', 'order', 'config'], array_keys($data['groups'][0]));
check('seo display', ['SEO', true, 20], [$data['groups'][2]['title'], $data['groups'][2]['collapsed'], $data['groups'][2]['order']]);
check('admin config has admin_notes', true, in_array('admin_notes', $fieldIds($groupOf($data, 'event_details')['config']), true));

as_user('editor');
$data = EditorIntegration::data($event);
check('editor does not get event_extras', ['event_details', 'event_seo'], array_column($data['groups'], 'id'));
check('editor config has no admin_notes', false, in_array('admin_notes', $fieldIds($groupOf($data, 'event_details')['config']), true));
check('editor config keeps public fields', true, in_array('public_tagline', $fieldIds($groupOf($data, 'event_details')['config']), true));
check('explicit user wins over the current one', 3, count(EditorIntegration::data($event, get_userdata($admin))['groups']));

FieldGroups::register('wf-test', [
    'id'       => 'locked_away',
    'title'    => 'Locked away',
    'location' => ['event'],
    'config'   => ['fields' => [['id' => 'locked_note', 'type' => 'text', 'access' => 'administrator']]],
]);
check('a group with no viewable field is not sent', false, in_array('locked_away', array_column(EditorIntegration::data($event)['groups'], 'id'), true));
check('the same group is sent to admin', true, in_array('locked_away', array_column(EditorIntegration::data($event, get_userdata($admin))['groups'], 'id'), true));

check('content group adds editor support', true, post_type_supports('event', 'editor'));
check('content group locks the template', 'all', get_post_type_object('event')->template_lock);
check('content group empties the template', [], get_post_type_object('event')->template);
check('event uses the block editor', true, use_block_editor_for_post_type('event'));
check('post template is left alone', false, get_post_type_object('post')->template_lock);
check('editor settings lock the event canvas', 'all', apply_filters('block_editor_settings_all', [], new WP_Block_Editor_Context(['post' => $event]))['templateLock'] ?? null);
check('editor settings leave posts alone', false, isset(apply_filters('block_editor_settings_all', [], new WP_Block_Editor_Context(['post' => get_post((int) wp_insert_post(['post_type' => 'post', 'post_title' => 'P']))]))['templateLock']));
check('no replace_editor takeover', false, apply_filters('replace_editor', false, $event));

$late = register_post_type('late_cpt', ['public' => true, 'show_in_rest' => true, 'supports' => ['title']]);
FieldGroups::register('wf-test', [
    'id'       => 'late_content',
    'location' => ['late_cpt'],
    'display'  => ['position' => 'content'],
    'config'   => ['fields' => [['id' => 'late_note', 'type' => 'text']]],
]);
check('a late content group locks the template', ['all', true], [get_post_type_object('late_cpt')->template_lock, post_type_supports('late_cpt', 'editor')]);
register_post_type('late_cpt', ['public' => true, 'show_in_rest' => true, 'supports' => ['title']]);
check('re-registering the post type keeps the lock', ['all', []], [get_post_type_object('late_cpt')->template_lock, get_post_type_object('late_cpt')->template]);

register_post_type('lock_cpt', ['public' => true, 'show_in_rest' => true, 'supports' => ['title']]);
$lockedPost  = get_post((int) wp_insert_post(['post_type' => 'lock_cpt', 'post_status' => 'draft', 'post_title' => 'Locked']));
$skippedPost = get_post((int) wp_insert_post(['post_type' => 'lock_cpt', 'post_status' => 'draft', 'post_title' => 'Skipped']));
FieldGroups::register('wf-test', [
    'id'       => 'lock_content',
    'location' => ['all' => [
        ['field' => 'post_type', 'operator' => 'equals', 'value' => 'lock_cpt'],
        ['field' => 'post_id', 'operator' => 'not_in', 'value' => [$skippedPost->ID]],
    ]],
    'display'  => ['position' => 'content'],
    'config'   => ['fields' => [['id' => 'lock_note', 'type' => 'text', 'access' => 'administrator']]],
]);
$lockOf = static fn (WP_Post $post): ?string => apply_filters('block_editor_settings_all', [], new WP_Block_Editor_Context(['post' => $post]))['templateLock'] ?? null;
as_user('administrator');
check('canvas locked where the content group applies', 'all', $lockOf($lockedPost));
check('canvas left alone on a post the location excludes', null, $lockOf($skippedPost));
as_user('editor');
check('canvas left alone for a user who can view none of its fields', null, $lockOf($lockedPost));

$notices = [];
register_post_type('untitled_cpt', ['public' => true, 'show_in_rest' => true, 'supports' => ['custom-fields']]);
FieldGroups::register('wf-test', [
    'id'       => 'untitled_content',
    'location' => ['untitled_cpt'],
    'display'  => ['position' => 'content'],
    'config'   => ['fields' => [['id' => 'untitled_note', 'type' => 'text']]],
]);
check('a content group adds title support', true, post_type_supports('untitled_cpt', 'title'));
check('adding title support is reported', 1, $mentions('"untitled_content"'));
register_post_type('untitled_cpt', ['public' => true, 'show_in_rest' => true, 'supports' => ['custom-fields']]);
check('re-registering keeps title support', true, post_type_supports('untitled_cpt', 'title'));

as_user('administrator');
FieldGroups::register('wf-test', [
    'id'       => 'folded',
    'title'    => 'Folded',
    'location' => ['event'],
    'display'  => ['position' => 'meta_box', 'collapsed' => true, 'order' => 3],
    'config'   => ['fields' => [['id' => 'folded_note', 'type' => 'text']]],
]);
FieldGroups::register('wf-test', [
    'id'       => 'trailing',
    'title'    => 'Trailing',
    'location' => ['event'],
    'display'  => ['position' => 'meta_box', 'order' => 20],
    'config'   => ['fields' => [['id' => 'trailing_note', 'type' => 'text']]],
]);

global $wp_meta_boxes;
$wp_meta_boxes = [];
do_action('add_meta_boxes', 'event', $event);

$box = $wp_meta_boxes['event']['normal']['default']['wireframe-group-event_extras'] ?? null;
check('meta box title', 'Extras', $box['title'] ?? null);
check('order 3 is high priority', 'Folded', $wp_meta_boxes['event']['normal']['high']['wireframe-group-folded']['title'] ?? null);
check('order 20 is low priority', 'Trailing', $wp_meta_boxes['event']['normal']['low']['wireframe-group-trailing']['title'] ?? null);
check('no meta box for content or sidebar groups', [false, false], [
    isset($wp_meta_boxes['event']['normal']['default']['wireframe-group-event_details']),
    isset($wp_meta_boxes['event']['normal']['high']['wireframe-group-event_seo']),
]);

ob_start();
call_user_func($box['callback'], $event, $box);
check('meta box callback prints an empty mount', '<div class="wireframe-group"></div>', ob_get_clean());

$post = get_post((int) wp_insert_post(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Plain']));
do_action('add_meta_boxes', 'post', $post);
check('post gets its own box', 'Post extras', $wp_meta_boxes['post']['normal']['default']['wireframe-group-post_extras']['title'] ?? null);

as_user('editor');
$wp_meta_boxes = [];
do_action('add_meta_boxes', 'event', $event);
check('editor gets no Extras box', false, isset($wp_meta_boxes['event']['normal']['default']['wireframe-group-event_extras']));
do_action('add_meta_boxes', 'comment', get_comment((int) wp_insert_comment(['comment_post_ID' => $post->ID, 'comment_content' => 'Hi'])));
check('comment screen is ignored', false, isset($wp_meta_boxes['comment']));

$fresh = as_user('author');
delete_user_option($fresh, 'closedpostboxes_event');
check('collapsed box seeded closed for a user with no saved state', ['wireframe-group-folded'], get_user_option('closedpostboxes_event', $fresh));
update_user_option($fresh, 'closedpostboxes_event', []);
check('a saved state wins over collapsed', [], get_user_option('closedpostboxes_event', $fresh));

as_user('administrator');
set_current_screen('event');
$GLOBALS['post'] = $event;
// Core enqueues the post-bound media frame before enqueue_block_editor_assets.
wp_enqueue_media(['post' => $event->ID]);
EditorIntegration::enqueue();
$inline = implode("\n", (array) wp_scripts()->get_data('wp-wireframe-post-fields', 'before'));
check('bundle enqueued on a grouped post type', true, wp_script_is('wp-wireframe-post-fields', 'enqueued'));
check('payload printed before the bundle', true, str_contains($inline, 'window.wireframePostFields = {"postId":' . $event->ID));
check('media enqueued once', 1, did_action('wp_enqueue_media'));
check('media uploads still attach to the post', true, str_contains((string) wp_scripts()->get_data('media-views', 'data'), '"post":{"id":' . $event->ID));

wp_dequeue_script('wp-wireframe-post-fields');
set_current_screen('page');
$GLOBALS['post'] = get_post((int) wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Page']));
EditorIntegration::enqueue();
check('nothing enqueued without groups', false, wp_script_is('wp-wireframe-post-fields', 'enqueued'));

$notices = [];
register_post_type('flat_cpt', ['public' => true, 'show_in_rest' => true]);
FieldGroups::register('wf-test', [
    'id'       => 'flat',
    'title'    => 'Flat',
    'location' => ['flat_cpt'],
    'display'  => ['position' => 'sidebar'],
    'config'   => ['tabs' => [
        ['id' => 'a', 'title' => 'A', 'sections' => [['id' => 's1', 'title' => 'One', 'fields' => [['id' => 'f1', 'type' => 'text']]]]],
        [
            'id'         => 'b',
            'title'      => 'B',
            'access'     => 'administrator',
            'conditions' => ['all' => [['field' => 'f1', 'operator' => 'truthy']]],
            'sections'   => [['id' => 's2', 'title' => 'Two', 'fields' => [['id' => 'f2', 'type' => 'text']]]],
        ],
    ]],
]);
$flat = FieldGroups::groupsFor('flat_cpt')[0] ?? null;
$tabs = ConfigLoader::load($flat['config'] ?? '')['tabs'];
check('sidebar group with tabs is not skipped', 'flat', $flat['id'] ?? null);
check('sidebar tabs flattened to one', 1, count($tabs));
check('sections kept in tab order', ['s1', 's2'], array_column($tabs[0]['sections'], 'id'));
check('section titles kept', ['One', 'Two'], array_column($tabs[0]['sections'], 'title'));
check('tab title and conditions dropped', ['', false], [$tabs[0]['title'], isset($tabs[0]['conditions'])]);
check('tab access moves to its sections', [null, ['view' => 'administrator', 'edit' => 'administrator']], array_map(static fn ($s) => $s['access'] ?? null, $tabs[0]['sections']));
check('flat fields hold both sections', ['f1', 'f2'], array_keys(ConfigLoader::flatFields($flat['config'])));
check('flattening is reported', 1, $mentions('"flat"'));

$notices = [];
register_post_type('flat_ok_cpt', ['public' => true, 'show_in_rest' => true]);
FieldGroups::register('wf-test', [
    'id'       => 'flat_ok',
    'title'    => 'Flat ok',
    'location' => ['flat_ok_cpt'],
    'display'  => ['position' => 'sidebar'],
    'config'   => ['sections' => [['id' => 's', 'title' => 'S', 'fields' => [['id' => 'g', 'type' => 'text']]]]],
]);
check('a sidebar group with one untitled tab is not reported', 0, $mentions('"flat_ok"'));

$gatedTabs = ['tabs' => [
    ['id' => 'open', 'title' => 'Open', 'sections' => [['id' => 'o', 'fields' => [['id' => 'g_open', 'type' => 'text']]]]],
    ['id' => 'admin_tab', 'title' => 'Admin', 'access' => 'administrator', 'sections' => [
        ['id' => 'edit_only', 'access' => ['edit' => 'administrator'], 'fields' => [['id' => 'g_edit_only', 'type' => 'text']]],
        ['id' => 'bare', 'fields' => [['id' => 'g_bare', 'type' => 'text']]],
    ]],
    ['id' => 'strict_tab', 'title' => 'Strict', 'access' => 'manage_options', 'sections' => [
        ['id' => 'loose', 'access' => 'edit_others_posts', 'fields' => [['id' => 'g_loose', 'type' => 'text']]],
        ['id' => 'tangled', 'access' => 'edit_others_posts', 'fields' => [['id' => 'g_tangled', 'type' => 'text', 'access' => 'edit_posts']]],
    ]],
    ['id' => 'read_tab', 'title' => 'Read', 'access' => 'edit_others_posts', 'sections' => [
        ['id' => 'read_only', 'access' => ['edit' => 'manage_options'], 'fields' => [['id' => 'g_read_only', 'type' => 'text']]],
    ]],
]];
$gatedCases = [
    'g_edit_only' => 'tab role, section sets only edit',
    'g_bare'      => 'tab access, section without',
    'g_loose'     => 'section more permissive than its tab',
    'g_read_only' => 'tab allows view, section narrows edit',
];

$notices = [];
register_post_type('gate_cpt', ['public' => true, 'show_in_rest' => true, 'supports' => ['title']]);
FieldGroups::register('wf-test', ['id' => 'gated', 'title' => 'Gated', 'location' => ['gate_cpt'], 'display' => ['position' => 'sidebar'], 'config' => $gatedTabs]);
$gatedSlug = FieldGroups::groupsFor('gate_cpt')[0]['config'];
check('a section whose access cannot be merged is dropped', false, isset(ConfigLoader::flatFields($gatedSlug)['g_tangled']));
check('dropping a section is reported', 1, $mentions('"tangled"'));

ConfigLoader::register('wf-test-gated-tabs', $gatedTabs);
$accessOf = static function (string $slug, WP_User $user): array {
    $map  = (new AccessResolver('edit_posts'))->resolveForConfig(ConfigLoader::load($slug), $user);
    $view = array_diff($map->viewableFieldIds(), ['g_tangled']);
    $edit = array_diff($map->editable, ['g_tangled']);
    sort($view);
    sort($edit);

    return ['view' => $view, 'edit' => $edit];
};

foreach (['administrator', 'editor', 'author', 'contributor'] as $role) {
    $user = get_userdata(as_user($role));
    check("flattened access matches the tabbed config for $role", $accessOf('wf-test-gated-tabs', $user), $accessOf($gatedSlug, $user));
}

as_user('administrator');
$gatePost = (int) wp_insert_post([
    'post_type'   => 'gate_cpt',
    'post_status' => 'draft',
    'post_title'  => 'Gate',
    'meta_input'  => array_fill_keys(['g_open', ...array_keys($gatedCases)], 'v'),
]);
$gatedConfig = static fn (): array => $groupOf(EditorIntegration::data(get_post($gatePost)), 'gated')['config'] ?? [];
$readOnly    = static function (array $config): array {
    $flags = [];

    foreach ($config['tabs'] ?? [] as $tab) {
        foreach ($tab['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $flags[$field['id']] = !empty($field['readonly']);
            }
        }
    }

    return $flags;
};
$restMeta    = static fn (): array => rest('GET', "/wp/v2/gate_cpt/$gatePost", [], ['context' => 'edit'])->get_data()['meta'] ?? [];
// Core skips the auth check when the value is unchanged, so each role writes its own value.
$restWrite   = static fn (string $key): int => rest('POST', "/wp/v2/gate_cpt/$gatePost", ['meta' => [$key => wp_get_current_user()->user_login]])->get_status();

$adminConfig = $readOnly($gatedConfig());
$adminMeta   = $restMeta();

foreach ($gatedCases as $key => $label) {
    check("$label: admin config, editable", false, $adminConfig[$key] ?? null);
    check("$label: admin REST read", true, array_key_exists($key, $adminMeta));
    check("$label: admin REST write", 200, $restWrite($key));
}

as_user('editor');
$editorConfig = $readOnly($gatedConfig());
$editorMeta   = $restMeta();
$editorSees   = ['g_edit_only' => false, 'g_bare' => false, 'g_loose' => false, 'g_read_only' => true];

check('editor config keeps the ungated field', false, $editorConfig['g_open'] ?? null);

foreach ($gatedCases as $key => $label) {
    check("$label: editor config", $editorSees[$key] ? true : null, $editorConfig[$key] ?? null);
    check("$label: editor REST read", $editorSees[$key], array_key_exists($key, $editorMeta));
    check("$label: editor REST write refused", 403, $restWrite($key));
}

check('editor REST write: ungated field saves', 200, $restWrite('g_open'));

check(
    'post_id values are cast to int',
    ['all' => [['field' => 'post_id', 'operator' => 'not_in', 'value' => [42]]]],
    Location::normalize(['all' => [['field' => 'post_id', 'operator' => 'not_in', 'value' => ['42']]]])
);
check(
    'a scalar post_id is cast too',
    ['any' => [['all' => [['field' => 'post_id', 'operator' => 'equals', 'value' => 7]]]]],
    Location::normalize(['any' => [['all' => [['field' => 'post_id', 'operator' => 'equals', 'value' => '7']]]]])
);

$rule = ['field' => 'post_type', 'operator' => 'equals', 'value' => 'event'];
check('all plus any is invalid', null, Location::normalize(['all' => [$rule], 'any' => [$rule]]));
check('a combinator plus field is invalid', null, Location::normalize(['all' => [$rule], 'field' => 'post_type', 'operator' => 'equals']));
check('a nested mixed node is invalid', null, Location::normalize(['any' => [['all' => [$rule], 'any' => [$rule]]]]));

$notices = [];
FieldGroups::register('wf-test', ['id' => 'mixed', 'title' => 'Mixed', 'location' => ['all' => [$rule], 'any' => [$rule]], 'config' => ['fields' => [['id' => 'm', 'type' => 'text']]]]);
check('a group with a mixed location is skipped', false, in_array('mixed', array_column(FieldGroups::groupsFor('event'), 'id'), true));
check('a group with a mixed location is reported', 1, $mentions('"mixed"'));

finish();
