<?php

use Wireframe\App;
use Wireframe\Framework\Conditions;
use Wireframe\PostFields\FieldGroups;
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

$save   = static fn (int $id, array $body): WP_REST_Response => rest('POST', "/wp/v2/event/$id", $body);
$errors = static fn (WP_REST_Response $response): array => (array) ($response->as_error()?->get_error_data()['errors'] ?? []);
$ticket = 'https://example.com/tickets';

as_user('administrator');

$draft = make_event();
check('draft without event_starts saves', 200, $save($draft, ['status' => 'draft', 'meta' => ['price' => 5]])->get_status());

$response = $save(make_event(), ['status' => 'publish', 'meta' => ['ticket_url' => $ticket]]);
check('publish without event_starts is refused', 400, $response->get_status());
check('refusal code', 'validation_failed', $response->as_error()?->get_error_code());
check('refusal names event_starts', true, !empty($errors($response)['event_starts']));

$response = rest('POST', '/wp/v2/event', ['title' => 'New', 'status' => 'publish']);
check('creating a published event without event_starts is refused', 400, $response->get_status());

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

as_user('administrator');
$note = (int) wp_insert_post(['post_type' => 'note_cpt', 'post_status' => 'publish', 'post_title' => 'Note', 'meta_input' => ['note_source' => $ticket]]);
$response = rest('POST', "/wp/v2/note_cpt/$note/autosaves", ['title' => 'Note, autosaved', 'meta' => ['note_source' => '']]);
check('autosaves are not validated', 200, $response->get_status());
check('the autosave is stored', 'Note, autosaved', wp_get_post_autosave($note)->post_title ?? null);

finish();
