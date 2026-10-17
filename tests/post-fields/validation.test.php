<?php

use Wireframe\App;
use Wireframe\Framework\Conditions;
use Wireframe\PostFields\EditorIntegration;
use Wireframe\PostFields\FieldGroups;
use Wireframe\PostFields\MetaRegistrar;
use Wireframe\PostFields\SaveValidator;

require __DIR__ . '/assert.php';

// Types used here must exist before the first REST request builds the routes.
register_post_type('note_cpt', ['public' => true, 'show_in_rest' => true, 'supports' => ['title', 'editor', 'revisions']]);
App::boot(['prefix' => 'note', 'field_groups' => [[
    'id'       => 'note_group',
    'title'    => 'Note',
    'location' => ['note_cpt'],
    'config'   => ['fields' => [['id' => 'note_source', 'type' => 'url', 'required' => true]]],
]]]);
App::boot(['prefix' => 'wf-settings', 'config' => ['sections' => [
    ['id' => 'main', 'fields' => [['id' => 'use_api', 'type' => 'toggle']]],
    [
        'id'         => 'api',
        'conditions' => ['all' => [['field' => 'use_api', 'operator' => 'truthy']]],
        'fields'     => [['id' => 'api_key', 'type' => 'text', 'required' => true]],
    ],
]]]);

register_post_type('gate_cpt', ['public' => true, 'show_in_rest' => true, 'supports' => ['title']]);
App::boot(['prefix' => 'gate', 'field_groups' => [[
    'id'       => 'gate_group',
    'title'    => 'Gate',
    'location' => ['gate_cpt'],
    'config'   => ['fields' => [
        ['id' => 'gate_flag', 'type' => 'toggle', 'access' => 'administrator'],
        ['id' => 'gate_reason', 'type' => 'text', 'required' => true, 'conditions' => ['all' => [['field' => 'gate_flag', 'operator' => 'truthy']]]],
    ]],
]]]);

register_post_type('pick_cpt', ['public' => true, 'show_in_rest' => true, 'supports' => ['title']]);
App::boot(['prefix' => 'pick', 'field_groups' => [[
    'id'       => 'pick_group',
    'title'    => 'Pick',
    'location' => ['pick_cpt'],
    'config'   => ['fields' => [
        ['id' => 'pick_choice', 'type' => 'select', 'default' => 'legacy', 'args' => ['populate' => ['type' => 'function', 'function' => static fn (): array => ['fresh' => 'Fresh']]]],
    ]],
]]]);

$save   = static fn (int $id, array $body): WP_REST_Response => rest('POST', "/wp/v2/event/$id", $body);
$errors = static fn (WP_REST_Response $response): array => (array) ($response->as_error()?->get_error_data()['errors'] ?? []);
$ticket = 'https://example.com/tickets';

as_user('administrator');

$draft = make_event();
$pick = (int) wp_insert_post(['post_type' => 'pick_cpt', 'post_status' => 'draft', 'post_title' => 'Pick']);
check('a config default counts as stored, so echoing it back stays valid', [], SaveValidator::validate($pick, 'pick_cpt', ['pick_choice' => 'legacy'], 'draft'));
check('a value neither stored, default nor populated is refused', ['pick_choice'], array_keys(SaveValidator::validate($pick, 'pick_cpt', ['pick_choice' => 'other'], 'draft')));
wp_delete_post($pick, true);

$savingPostId = static fn (): int => (new ReflectionProperty(MetaRegistrar::class, 'savingPostId'))->getValue();
MetaRegistrar::beginSave(get_post(make_event()));
$outer = $savingPostId();
$inner = get_post(make_event());
MetaRegistrar::beginSave($inner);
MetaRegistrar::endSave();
check('a nested save scope restores the outer one', $outer, $savingPostId());
MetaRegistrar::endSave();
check('closing the outer scope leaves none', 0, $savingPostId());
MetaRegistrar::beginSave($inner);
MetaRegistrar::beginRequest(null);
MetaRegistrar::beginSave(get_post(make_event()));
MetaRegistrar::endRequest(null);
check('a REST request unwinds a save its insert never closed', $inner->ID, $savingPostId());
MetaRegistrar::endSave();

$gated = (int) wp_insert_post(['post_type' => 'gate_cpt', 'post_status' => 'draft', 'post_title' => 'Gated', 'meta_input' => ['gate_flag' => true]]);
check('a required field shown by a value the user can read is enforced', ['gate_reason'], array_keys(SaveValidator::validate($gated, 'gate_cpt', [], 'publish')));
as_user('editor');
check('a condition on a value the user cannot read sees it empty, as their editor does', [], SaveValidator::validate($gated, 'gate_cpt', [], 'publish'));
as_user('administrator');
wp_delete_post($gated, true);

check('draft without event_starts saves', 200, $save($draft, ['status' => 'draft', 'meta' => ['price' => 5]])->get_status());

$response = $save(make_event(), ['status' => 'publish', 'meta' => ['ticket_url' => $ticket]]);
check('publish without event_starts is refused', 400, $response->get_status());
check('refusal code', 'validation_failed', $response->as_error()?->get_error_code());
check('refusal names event_starts', true, !empty($errors($response)['event_starts']));

$locationArgs = [];
$recordArgs   = static function (array $context, int $postId, ?WP_User $user, string $postType) use (&$locationArgs): array {
    $locationArgs = [$postId, $postType];

    return $context;
};
add_filter('wp-wireframe/location_context', $recordArgs, 10, 4);
$response = rest('POST', '/wp/v2/event', ['title' => 'New', 'status' => 'publish']);
remove_filter('wp-wireframe/location_context', $recordArgs, 10);
check('creating a published event without event_starts is refused', 400, $response->get_status());
check('create refusal names event_starts', true, !empty($errors($response)['event_starts']));
check('the location context filter gets the post type of a post not yet created', [0, 'event'], $locationArgs);

$response = $save(make_event(), ['status' => 'future', 'date' => '2030-01-01T10:00:00', 'meta' => ['ticket_url' => $ticket]]);
check('scheduling without event_starts is refused', true, !empty($errors($response)['event_starts']));

$response = $save(make_event([], 'publish'), ['meta' => ['price' => 3]]);
check('a partial save to a published post missing a required value is refused', true, !empty($errors($response)['event_starts']));

$partial = make_event();
$save($partial, ['meta' => ['event_starts' => '2026-11-01', 'ticket_url' => $ticket]]);
check('publishing with only a status passes when required values were saved earlier', 200, $save($partial, ['status' => 'publish'])->get_status());
check('a published post stays valid on a later partial save', 200, $save($partial, ['meta' => ['price' => 8]])->get_status());

$hidden = make_event();
check('a required field hidden by a condition does not block publishing', 200, $save($hidden, [
    'status' => 'publish',
    'meta'   => ['is_featured' => false, 'event_starts' => '2026-11-01'],
])->get_status());

$kept = make_event(['ticket_url' => 'https://example.com/old']);
$save($kept, ['status' => 'publish', 'meta' => ['is_featured' => false, 'event_starts' => '2026-11-01']]);
check('a hidden field keeps its stored value', 'https://example.com/old', get_post_meta($kept, 'ticket_url', true));

$response = $save(make_event(), ['status' => 'publish', 'meta' => ['is_featured' => true, 'event_starts' => '2026-11-01']]);
check('a shown required field blocks publishing', 400, $response->get_status());
check('shown required error names ticket_url', true, !empty($errors($response)['ticket_url']));

$response = $save(make_event(), ['status' => 'publish', 'meta' => ['event_starts' => '2026-11-01']]);
check('an unsaved toggle default counts when deciding visibility', true, !empty($errors($response)['ticket_url']));

$response = $save(make_event(), ['status' => 'publish', 'meta' => [
    'event_starts' => '2026-11-01',
    'ticket_url'   => $ticket,
    'sessions'     => [['seats' => 'abc']],
]]);
check('an invalid repeater subfield is refused', 400, $response->get_status());
check('repeater errors nest by row and subfield', true, !empty($errors($response)['sessions'][0]['seats']));

$response = $save($draft, ['meta' => ['ticket_url' => 'not a url']]);
check('format rules still apply to drafts', true, !empty($errors($response)['ticket_url']));

check('withoutRequired strips required everywhere', [
    'a' => ['validation' => 'email'],
    'b' => ['validation' => ['max:5']],
    'r' => ['type' => 'repeater', 'args' => ['subfields' => [['id' => 's', 'validation' => '']]]],
], SaveValidator::withoutRequired([
    'a' => ['required' => true, 'validation' => 'required|email'],
    'b' => ['validation' => ['required', 'max:5']],
    'r' => ['type' => 'repeater', 'args' => ['subfields' => [['id' => 's', 'required' => true, 'validation' => 'required']]]],
]));

$fields = ['f' => [], 'r' => [], 'r.s' => []];
$config = ['tabs' => [
    ['id' => 't', 'sections' => [['id' => 'open', 'fields' => [['id' => 'f']]]]],
    ['id' => 'u', 'conditions' => ['all' => [['field' => 'f', 'operator' => 'truthy']]], 'sections' => [['id' => 'gated', 'fields' => [['id' => 'r']]]]],
]];
check('a hidden tab hides its fields and their subfields', ['f' => true, 'r' => false, 'r.s' => false], Conditions::withContainers(Conditions::visibilityMap($fields, []), $config, []));
check('container visibility adds no keys of its own', ['f' => true], Conditions::withContainers(['f' => true], $config, []));

FieldGroups::register('wf-test', [
    'id'       => 'event_refunds',
    'title'    => 'Refunds',
    'location' => ['event'],
    'config'   => ['sections' => [[
        'id'         => 'refunds',
        'conditions' => ['all' => [['field' => 'price', 'operator' => 'gt', 'value' => 0]]],
        'fields'     => [['id' => 'refund_policy', 'type' => 'text', 'required' => true]],
    ]]],
]);

$valid = ['event_starts' => '2026-11-01', 'ticket_url' => $ticket];
check('a required field in a section hidden by its conditions does not block publishing', 200, $save(make_event(), ['status' => 'publish', 'meta' => $valid])->get_status());
$response = $save(make_event(), ['status' => 'publish', 'meta' => $valid + ['price' => 10]]);
check('the same field blocks publishing once its section shows', true, !empty($errors($response)['refund_policy']));

$publishAs = static function (string $role) use ($save, $valid): WP_REST_Response {
    as_user($role);

    return $save(make_event(), ['status' => 'publish', 'meta' => $valid]);
};

// Same as the fixture's event_extras, which applies to administrators only.
$registerExtras = static fn (bool $required): mixed => FieldGroups::register('wf-fixture', [
    'id'       => 'event_extras',
    'title'    => 'Extras',
    'location' => ['all' => [
        ['field' => 'post_type', 'operator' => 'equals', 'value' => 'event'],
        ['field' => 'user_role', 'operator' => 'contains', 'value' => 'administrator'],
    ]],
    'display'  => ['position' => 'meta_box'],
    'config'   => ['fields' => [['id' => 'extra_note', 'type' => 'textarea', 'required' => $required]]],
]);

$registerExtras(true);
check('administrator: a required field in event_extras is enforced', true, !empty($errors($publishAs('administrator'))['extra_note']));
check('editor: a required field in event_extras, whose location does not apply, does not block publishing', 200, $publishAs('editor')->get_status());

$registerExtras(false);
FieldGroups::register('wf-test', [
    'id'       => 'event_approval',
    'title'    => 'Approval',
    'location' => ['event'],
    'config'   => ['fields' => [['id' => 'approval_code', 'type' => 'text', 'required' => true, 'access' => 'administrator']]],
]);
check('administrator: an admin-only required field is enforced', true, !empty($errors($publishAs('administrator'))['approval_code']));
check('editor: an admin-only required field does not block publishing', 200, $publishAs('editor')->get_status());

$denySchedule = static fn (bool $allowed, array $context): bool
    => $context['level'] === 'tab' && $context['id'] === 'schedule' && in_array('editor', $context['user']?->roles ?? [], true) ? false : $allowed;
add_filter('wp-wireframe/access/resolve', $denySchedule, 10, 2);
as_user('editor');
$denied       = make_event(['event_starts' => '2026-11-01']);
$detailsGroup = array_values(array_filter(EditorIntegration::data(get_post($denied))['groups'], static fn (array $g): bool => $g['id'] === 'event_details'))[0] ?? [];
$tabIds       = array_column($detailsGroup['config']['tabs'] ?? [], 'id');
check('editor: a filter-denied tab with unruled fields is absent from the localised config', ['details'], $tabIds);
check('editor: its field is stripped from the REST read', false, array_key_exists('event_starts', rest('GET', "/wp/v2/event/$denied", [], ['context' => 'edit'])->get_data()['meta'] ?? []));
check('editor: a REST write to it is refused', 403, $save($denied, ['meta' => ['event_starts' => '2026-12-01']])->get_status());
check('editor: its required field does not block publishing', 200, $save(make_event(), ['status' => 'publish', 'meta' => ['ticket_url' => $ticket]])->get_status());
remove_filter('wp-wireframe/access/resolve', $denySchedule, 10);

as_user('administrator');
$note = (int) wp_insert_post(['post_type' => 'note_cpt', 'post_status' => 'publish', 'post_title' => 'Note', 'meta_input' => ['note_source' => $ticket]]);
$response = rest('POST', "/wp/v2/note_cpt/$note/autosaves", ['title' => 'Note, autosaved', 'meta' => ['note_source' => '']]);
check('autosaves are not validated', 200, $response->get_status());
check('the autosave is stored', 'Note, autosaved', wp_get_post_autosave($note)->post_title ?? null);
$noteDraft = (int) wp_insert_post(['post_type' => 'note_cpt', 'post_status' => 'draft', 'post_title' => 'Draft note']);
rest('POST', "/wp/v2/note_cpt/$noteDraft/autosaves", ['title' => 'Draft note, autosaved', 'status' => 'publish']);
check('an autosave cannot publish a draft', 'draft', get_post_status($noteDraft));

$settingsSave = static fn (array $values): int => rest('POST', '/wf-settings/v1/settings/default', $values)->get_status();
check('settings page: a required field in a hidden section does not block saving', 200, $settingsSave(['use_api' => false]));
check('settings page: the same field is enforced once its section shows', 400, $settingsSave(['use_api' => true]));

finish();
