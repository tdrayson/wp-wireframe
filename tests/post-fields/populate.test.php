<?php

use Wireframe\App;
use Wireframe\Framework\Populate;
use Wireframe\Meta;
use Wireframe\PostFields\ClassicEditor;
use Wireframe\PostFields\EditorIntegration;
use Wireframe\PostFields\FieldGroups;

require __DIR__ . '/assert.php';

// Pages and groups must exist before the first REST request builds the routes.
register_post_type('pop_cpt', ['public' => true, 'show_in_rest' => true, 'supports' => ['title']]);
$speakerPopulate = ['type' => 'query', 'query' => 'posts', 'arguments' => ['post_type' => 'speaker'], 'fetch' => true];
App::boot([
    'prefix'       => 'wf-populate',
    'config'       => ['fields' => [
        ['id' => 'featured_speaker', 'type' => 'select', 'args' => ['populate' => $speakerPopulate]],
        ['id' => 'lineup', 'type' => 'checkboxes', 'args' => ['populate' => ['type' => 'query', 'query' => 'posts', 'arguments' => ['post_type' => 'speaker']]]],
    ]],
    'field_groups' => [[
        'id'       => 'pop_group',
        'title'    => 'Pop',
        'location' => ['pop_cpt'],
        'config'   => ['fields' => [
            ['id' => 'open_pick', 'type' => 'select', 'args' => ['populate' => $speakerPopulate]],
            ['id' => 'secret_pick', 'type' => 'select', 'access' => 'administrator', 'args' => ['populate' => $speakerPopulate]],
        ]],
    ]],
]);

$created  = [];
$speaker  = static function (string $title, string $status = 'publish') use (&$created): int {
    $id        = wp_insert_post(['post_type' => 'speaker', 'post_title' => $title, 'post_status' => $status]);
    $created[] = $id;

    return $id;
};
$event    = static function (array $meta = []) use (&$created): int {
    $id        = make_event($meta);
    $created[] = $id;

    return $id;
};
$field    = static fn (string $id): array => FieldGroups::flatFields('event')[$id];
$save     = static fn (int $id, array $meta): WP_REST_Response => rest('POST', "/wp/v2/event/$id", ['meta' => $meta]);
$errors   = static fn (WP_REST_Response $response): array => (array) ($response->as_error()?->get_error_data()['errors'] ?? []);
$search   = static fn (array $query, string $prefix = 'wf-fixture'): WP_REST_Response => rest('GET', "/$prefix/v1/populate", [], $query);
$byId     = static function (array $config, string $id): ?array {
    foreach ($config['tabs'] ?? [] as $tab) {
        foreach ($tab['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $candidate) {
                if (($candidate['id'] ?? '') === $id) {
                    return $candidate;
                }
            }
        }
    }

    return null;
};

$editor = as_user('editor');
$admin  = as_user('administrator');

$ada    = $speaker('Ada');
$grace  = $speaker('Grace');
$drafty = $speaker('Drafty', 'draft');
$post   = wp_insert_post(['post_type' => 'post', 'post_title' => 'Not a speaker', 'post_status' => 'publish']);
$created[] = $post;
$term   = static function (string $name): int {
    $existing = term_exists($name, 'event_type');

    return (int) ($existing['term_id'] ?? wp_insert_term($name, 'event_type')['term_id']);
};
$terms  = [$term('Populate workshop'), $term('Populate keynote')];

// Resolution.
check('resolve limits a query to the given values', [$ada => 'Ada'], Populate::resolve($field('speakers'), [$ada])['args']['options']);
check('a fetch field resolves no query without values', [], Populate::resolve($field('speakers'))['args']['options']);
check('a draft is not resolved', [], Populate::resolve($field('speakers'), [$drafty])['args']['options']);
check('a post of another type is not resolved', [], Populate::resolve($field('speakers'), [$post])['args']['options']);

$expectedTerms = get_terms(['taxonomy' => 'event_type', 'hide_empty' => false, 'fields' => 'id=>name']);
check('terms list every event_type term, empty ones included', $expectedTerms, Populate::resolve($field('event_tags'))['args']['options']);
check('term options include the new terms', true, isset($expectedTerms[$terms[0]], $expectedTerms[$terms[1]]));

$hostOptions = Populate::resolve($field('host'))['args']['options'];
check('static options come first by default', [0, 'None'], [array_key_first($hostOptions), reset($hostOptions)]);
check('users with the role are listed', 'wf_test_editor', $hostOptions[$editor] ?? null);
check('users without the role are not', false, isset($hostOptions[$admin]));

$before = ['type' => 'radio', 'args' => ['options' => ['0' => 'None'], 'populate' => ['type' => 'function', 'function' => static fn (): array => ['x' => 'X'], 'position' => 'before']]];
check('position before puts populated options first', ['x', 0], array_keys(Populate::resolve($before)['args']['options']));

$seen     = null;
$function = ['id' => 'fn_pick', 'type' => 'select', 'args' => ['populate' => ['type' => 'function', 'function' => static function (array $field) use (&$seen): array {
    $seen = $field['id'];

    return ['x' => 'X'];
}]]];
check('function type resolves its map', ['x' => 'X'], Populate::resolve($function)['args']['options']);
check('the function receives the field', 'fn_pick', $seen);

add_filter('wp-wireframe/populate/colours', static fn (array $map, array $field): array => $map + ['red' => 'Red', 'blue' => 'Blue'], 10, 2);
check('custom type resolves its filter', ['red' => 'Red', 'blue' => 'Blue'], Populate::resolve(['type' => 'select', 'args' => ['populate' => ['type' => 'custom', 'custom' => 'colours']]])['args']['options']);
check('populate on another field type is ignored', ['a' => 'A'], Populate::resolve(['type' => 'text', 'args' => ['options' => ['a' => 'A'], 'populate' => ['type' => 'custom', 'custom' => 'colours']]])['args']['options']);

$subfields = Populate::resolveSubfields([['id' => 'who', 'type' => 'select', 'args' => ['populate' => $speakerPopulate]]], [['who' => $ada], ['who' => $drafty]]);
check('repeater subfields resolve against every row', [$ada => 'Ada'], $subfields[0]['args']['options']);

// Storage types.
check('a posts query stores integers', 'integer', Populate::valueType($field('speakers')['args']));
check('a static string key keeps it a string', 'string', Populate::valueType(['options' => ['none' => 'None'], 'populate' => ['query' => 'users']]));
check('a non-ID return format is a string', 'string', Populate::valueType(['populate' => ['query' => 'posts', 'return_format' => ['value' => 'post_name']]]));
check('callbacks store strings', 'string', Populate::valueType(['populate' => ['type' => 'custom', 'custom' => 'colours']]));
check('speakers meta schema holds integers', 'integer', get_registered_meta_keys('post', 'event')['speakers']['type'] ?? null);
check('host meta schema is an integer', 'integer', get_registered_meta_keys('post', 'event')['host']['type'] ?? null);

// REST saves.
$id = $event();
check('saving two speakers succeeds', 200, $save($id, ['speakers' => [$ada, $grace]])->get_status());
$rows = get_post_meta($id, 'speakers');
sort($rows);
check('two speakers store two rows', [(string) $ada, (string) $grace], $rows);
check('Meta reads speakers as integers', [$ada, $grace], Meta::get($id, 'speakers'));
check('REST reads speakers as integers', [$ada, $grace], rest('GET', "/wp/v2/event/$id", [], ['context' => 'edit'])->get_data()['meta']['speakers'] ?? null);

$response = $save($id, ['speakers' => [$ada, $drafty]]);
check('a draft speaker is refused', 400, $response->get_status());
check('the refusal names speakers', true, !empty($errors($response)['speakers']));
$response = $save($id, ['speakers' => [$post]]);
check('a post of another type is refused', true, !empty($errors($response)['speakers']));
check('refused values are not written', [$ada, $grace], Meta::get($id, 'speakers'));

check('a term saves', 200, $save($id, ['event_tags' => [$terms[0]]])->get_status());
check('the term is stored as an integer', [$terms[0]], Meta::get($id, 'event_tags'));
check('an unknown term is refused', true, !empty($errors($save($id, ['event_tags' => [999999]]))['event_tags']));
check('an editor host saves', 200, $save($id, ['host' => $editor])->get_status());
check('the host reads back as an integer', $editor, Meta::get($id, 'host'));
check('the static None option saves', 200, $save($id, ['host' => 0])->get_status());
check('an administrator host is refused', true, !empty($errors($save($id, ['host' => $admin]))['host']));

wp_update_post(['ID' => $grace, 'post_status' => 'draft']);
check('a speaker unpublished since does not block an unrelated save', 200, $save($id, ['price' => 4])->get_status());
check('resubmitting the unpublished speaker is refused', true, !empty($errors($save($id, ['speakers' => [$ada, $grace]]))['speakers']));
wp_update_post(['ID' => $grace, 'post_status' => 'publish']);

check('Meta::set drops values the query does not return', [$ada], (static function () use ($id, $ada, $post): array {
    Meta::set($id, 'speakers', [$ada, $post]);

    return Meta::get($id, 'speakers');
})());

// Browser payloads.
$withAda = get_post($event());
Meta::set($withAda->ID, 'speakers', [$ada]);
$data    = EditorIntegration::data($withAda);
$details = $data['groups'][0];
$speakersField = $byId($details['config'], 'speakers');
check('groups carry their prefix', 'wf-fixture', $details['prefix'] ?? null);
check('fetch ships only the stored labels', [['value' => $ada, 'label' => 'Ada']], $speakersField['args']['options'] ?? null);
check('fetch ships its field path, not its query', ['fetch' => true, 'field' => 'speakers'], $speakersField['args']['populate'] ?? null);
$hostField = $byId($details['config'], 'host');
check('options ship as an ordered list', ['value' => 0, 'label' => 'None'], $hostField['args']['options'][0] ?? null);
check('the classic payload resolves too', [['value' => $ada, 'label' => 'Ada']], $byId(ClassicEditor::data($withAda)['groups'][0]['config'], 'speakers')['args']['options'] ?? null);

$browser = Populate::resolveConfig(['tabs' => [['id' => 't', 'sections' => [['id' => 's', 'fields' => [$function]]]]]], []);
check('callables never reach the browser', ['fetch' => false, 'field' => 'fn_pick'], $browser['tabs'][0]['sections'][0]['fields'][0]['args']['populate']);
check('the browser config encodes', true, is_string(wp_json_encode($browser)));

// Search route.
$response = $search(['context' => 'post:event', 'field' => 'speakers', 'search' => 'Gra']);
check('search finds Grace', [['value' => $grace, 'label' => 'Grace']], $response->get_data());
check('search skips drafts', [], $search(['context' => 'post:event', 'field' => 'speakers', 'search' => 'Draft'])->get_data());
check('search on a field without fetch is refused', 403, $search(['context' => 'post:event', 'field' => 'event_tags', 'search' => 'W'])->get_status());
check('search on an unknown context is refused', 403, $search(['context' => 'nope:event', 'field' => 'speakers'])->get_status());
check('search under another prefix is refused', 403, $search(['context' => 'post:event', 'field' => 'speakers'], 'wf-populate')->get_status());

for ($i = 0; $i < 22; $i++) {
    $speaker("Bulk speaker $i");
}
check('search is capped at 20', 20, count((array) $search(['context' => 'post:event', 'field' => 'speakers', 'search' => 'Bulk'])->get_data()));
check('Populate::search caps the limit too', 20, count(Populate::search($field('speakers'), 'Bulk', 100)));

check('an admin may search a gated field', 200, $search(['context' => 'post:pop_cpt', 'field' => 'secret_pick', 'search' => 'Ada'], 'wf-populate')->get_status());
as_user('editor');
check('an editor may search an open field', [['value' => $ada, 'label' => 'Ada']], $search(['context' => 'post:pop_cpt', 'field' => 'open_pick', 'search' => 'Ada'], 'wf-populate')->get_data());
check('an editor may not search a field they cannot view', 403, $search(['context' => 'post:pop_cpt', 'field' => 'secret_pick', 'search' => 'Ada'], 'wf-populate')->get_status());
check('an editor may not search a settings page they cannot view', 403, $search(['context' => 'settings:default', 'field' => 'featured_speaker'], 'wf-populate')->get_status());
as_user('subscriber');
check('a user who cannot edit the type is refused', 403, $search(['context' => 'post:event', 'field' => 'speakers', 'search' => 'Gra'])->get_status());
as_user('logged_out');
check('logged out is refused', 401, $search(['context' => 'post:event', 'field' => 'speakers', 'search' => 'Gra'])->get_status());
as_user('administrator');

// Settings page.
check('settings search finds Ada', [['value' => $ada, 'label' => 'Ada']], $search(['context' => 'settings:default', 'field' => 'featured_speaker', 'search' => 'Ada'], 'wf-populate')->get_data());
$response = rest('POST', '/wf-populate/v1/settings/default', ['featured_speaker' => $post]);
check('settings refuse an ID the query does not return', true, !empty($errors($response)['featured_speaker']));
$response = rest('POST', '/wf-populate/v1/settings/default', ['featured_speaker' => (string) $ada, 'lineup' => [$grace]]);
check('settings accept a populated value', 200, $response->get_status());
check('settings store the value as an integer', $ada, get_option('wf_populate_settings')['featured_speaker'] ?? null);
check('settings store checkboxes as integers', [$grace], get_option('wf_populate_settings')['lineup'] ?? null);
$config = rest('GET', '/wf-populate/v1/settings/default')->get_data()['config'];
check('settings config ships the stored label', [['value' => $ada, 'label' => 'Ada']], $byId($config, 'featured_speaker')['args']['options'] ?? null);
check('settings config ships the full list without fetch', true, in_array(['value' => $grace, 'label' => 'Grace'], $byId($config, 'lineup')['args']['options'] ?? [], true));

delete_option('wf_populate_settings');
foreach ($created as $postId) {
    wp_delete_post($postId, true);
}
foreach ($terms as $termId) {
    wp_delete_term($termId, 'event_type');
}

finish();
