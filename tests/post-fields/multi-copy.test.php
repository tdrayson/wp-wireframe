<?php

/**
 * Two Strauss-prefixed copies of Wireframe in one site: the second copy is this repo with its namespace rewritten to
 * `WfCopy\Wireframe`, loaded after WordPress has booted. Covers what the copies share by string name.
 */

use Wireframe\PostFields\Bindings;
use Wireframe\PostFields\Cli\MigrateCommand;
use Wireframe\PostFields\Cli\ReindexCommand;
use Wireframe\PostFields\EditorIntegration;
use Wireframe\PostFields\FieldGroups;
use Wireframe\PostFields\Index\IndexTable;

require __DIR__ . '/assert.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

$root = dirname(__DIR__, 2);
$copy = sys_get_temp_dir() . '/wf-strauss-copy-' . getmypid();

/** Copy one PHP file into the fake copy with its namespace prefixed, as Strauss would. */
$prefixed = static function (string $from, string $to): void {
    $code = (string) file_get_contents($from);
    $code = str_replace(['namespace Wireframe;', "'Wireframe\\\\'"], ['namespace WfCopy\\Wireframe;', "'WfCopy\\\\Wireframe\\\\'"], $code);
    $code = (string) preg_replace('/(?<!\w)Wireframe\\\\(?=[A-Z])/', 'WfCopy\\Wireframe\\', $code);

    wp_mkdir_p(dirname($to));
    file_put_contents($to, $code);
};

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/src", FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $relative = substr($file->getPathname(), strlen("$root/"));

    if ($file->getExtension() === 'php' && !str_starts_with($relative, 'src/assets/')) {
        $prefixed($file->getPathname(), "$copy/$relative");
    }
}
$prefixed("$root/bootstrap.php", "$copy/bootstrap.php");
$prefixed("$root/autoload-map.php", "$copy/autoload-map.php");
symlink("$root/src/assets", "$copy/src/assets");

require "$copy/bootstrap.php";

WfCopy\Wireframe\App::boot([
    'prefix'       => 'wf-copy',
    'assets_url'   => 'http://example.com/copy/',
    'field_groups' => [[
        'id'       => 'copy_extras',
        'title'    => 'Copy extras',
        'location' => ['event', 'classic_event'],
        'config'   => ['fields' => [
            ['id' => 'copy_note', 'type' => 'text', 'public' => true],
            ['id' => 'copy_code', 'type' => 'text', 'required' => true],
            ['id' => 'copy_renamed', 'type' => 'text', 'previous_ids' => ['copy_legacy']],
            ['id' => 'copy_slots', 'type' => 'repeater', 'args' => ['subfields' => [['id' => 'label', 'type' => 'text', 'indexed' => true]]]],
        ]],
    ]],
]);

check('the copy loaded under its own namespace', true, class_exists(WfCopy\Wireframe\PostFields\FieldGroups::class));
check('the copy attached its group', ['copy_extras'], array_column(WfCopy\Wireframe\PostFields\FieldGroups::groupsFor('event'), 'id'));

$created = [];
$event   = static function (array $meta = [], string $type = 'event', string $status = 'publish') use (&$created): int {
    $id        = (int) wp_insert_post(['post_type' => $type, 'post_status' => $status, 'post_title' => 'Copy', 'meta_input' => $meta]);
    $created[] = $id;

    return $id;
};

as_user('administrator');

// Editor payload: both copies push into one global, read by the one bundle the shared handle loads.
$post = get_post($event());
set_current_screen('event');
$GLOBALS['post'] = $post;
EditorIntegration::enqueue();
WfCopy\Wireframe\PostFields\EditorIntegration::enqueue();
$inline = implode("\n", (array) wp_scripts()->get_data('wp-wireframe-post-fields', 'before'));
$merged = json_decode((string) shell_exec('node -e ' . escapeshellarg(
    'globalThis.window = globalThis;' . $inline . ';process.stdout.write(JSON.stringify(window.wireframePostFields));'
)), true);
$keys = array_column($merged['groups'] ?? [], 'key');
check('payload: both copies\' groups are merged', [true, true], [in_array('wf-fixture__event_details', $keys, true), in_array('wf-copy__copy_extras', $keys, true)]);
check('payload: no group is lost or doubled', count(EditorIntegration::data($post)['groups']) + 1, count($keys));
check('payload: the first copy seeds the rest of the payload', $post->ID, $merged['postId'] ?? null);

// Canvas: only the copy holding the content group sets the lock.
$settings = WfCopy\Wireframe\PostFields\EditorIntegration::filterEditorSettings(['templateLock' => 'all'], new WP_Block_Editor_Context(['post' => $post]));
check('canvas: a copy without the content group leaves the lock alone', 'all', $settings['templateLock']);
register_post_type('copy_locked', ['public' => true, 'show_in_rest' => true, 'template' => [['core/paragraph']], 'template_lock' => 'insert']);
FieldGroups::register('wf-fixture', ['id' => 'copy_locked_content', 'location' => ['copy_locked'], 'display' => ['position' => 'content'], 'config' => ['fields' => [['id' => 'locked_note', 'type' => 'text']]]]);
check('canvas: every copy reports the native lock', ['insert', 'insert'], [EditorIntegration::nativeLock('copy_locked'), WfCopy\Wireframe\PostFields\EditorIntegration::nativeLock('copy_locked')]);

// Bindings: the first copy's source asks every copy.
$render = static function (int $postId, string $key): string {
    $attrs  = ['metadata' => ['bindings' => ['content' => ['source' => Bindings::SOURCE, 'args' => ['key' => $key]]]]];
    $parsed = parse_blocks('<!-- wp:paragraph ' . wp_json_encode($attrs) . ' --><p></p><!-- /wp:paragraph -->')[0];

    return trim(wp_strip_all_tags((new WP_Block($parsed, ['postId' => $postId, 'postType' => 'event']))->render()));
};
$bound = $event(['copy_note' => 'From the copy', 'public_tagline' => 'From the first']);
check('bindings: a field of the second copy renders', 'From the copy', $render($bound, 'copy_note'));
check('bindings: a field of the first copy still renders', 'From the first', $render($bound, 'public_tagline'));
check('bindings: a key no copy owns renders empty', '', $render($bound, 'nobody_owns_this'));

// meta_query: one query mixing both copies' indexed subfields; values are unique to this run.
$hall = uniqid('hall-');
$slot = uniqid('slot-');
$both  = $event(['sessions' => [['venue' => $hall]], 'copy_slots' => [['label' => $slot]]]);
$first = $event(['sessions' => [['venue' => $hall]]]);
$event(['copy_slots' => [['label' => $slot]]]);
$found = get_posts(['post_type' => 'event', 'fields' => 'ids', 'post_status' => 'any', 'meta_query' => [
    ['key' => 'sessions.venue', 'value' => $hall],
    ['key' => 'copy_slots.label', 'value' => $slot],
]]);
check('meta_query: clauses from both copies are bridged', [$both], $found);
$found = get_posts(['post_type' => 'event', 'fields' => 'ids', 'post_status' => 'any', 'orderby' => 'ID', 'order' => 'ASC', 'meta_query' => [
    'relation' => 'OR',
    ['key' => 'sessions.venue', 'value' => $hall],
    ['key' => 'copy_slots.label', 'value' => 'nope'],
]]);
check('meta_query: an OR across copies keeps the first copy\'s clause', [$both, $first], $found);

// CLI: one command, every copy's groups.
global $wpdb;
$table = IndexTable::name();
$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE post_id = %d", $both));
apply_filters(ReindexCommand::FILTER, 0, 'event');
$fields = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT field FROM {$table} WHERE post_id = %d ORDER BY field", $both));
check('reindex: the shared filter rebuilds both copies\' repeaters', ['copy_slots', 'sessions'], $fields);
check('reindex: the command is registered once', true, !empty($GLOBALS['wireframe_cli_commands']['reindex']));

$legacy = $event();
$wpdb->insert($wpdb->postmeta, ['post_id' => $legacy, 'meta_key' => 'copy_legacy', 'meta_value' => 'old value']);
wp_cache_delete($legacy, 'post_meta');
apply_filters(MigrateCommand::FILTER, 0, 'event');
$keys = $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE 'copy\\_%%' ORDER BY meta_key", $legacy));
check('migrate: the shared filter migrates the second copy\'s aliases', ['copy_renamed'], $keys);

// Classic: each copy saves its own groups; neither clears the other's errors.
$classic = $event([], 'classic_event', 'draft');
$_POST   = wp_slash([
    'post_ID'          => $classic,
    'post_title'       => 'Copy',
    'post_status'      => 'draft',
    'publish'          => 'Publish',
    '_wireframe_nonce' => wp_create_nonce('wireframe_classic_save'),
    'wireframe_fields' => [
        'wf-fixture__event_details' => wp_json_encode(['ticket_url' => 'https://example.com/t']),
        'wf-copy__copy_extras'      => wp_json_encode(['copy_code' => 'X1']),
    ],
]);
edit_post();
$_POST  = [];
$errors = get_transient('wireframe_errors_' . get_current_user_id() . "_$classic")['errors'] ?? [];
check('classic: the post stays a draft', 'draft', get_post_status($classic));
check('classic: the second copy\'s valid save keeps the first copy\'s errors', ['event_starts'], array_keys($errors));
check('classic: the second copy\'s value is written', 'X1', get_post_meta($classic, 'copy_code', true));

foreach ($created as $id) {
    wp_delete_post($id, true);
}
unlink("$copy/src/assets");
exec('rm -rf ' . escapeshellarg($copy));

finish();
