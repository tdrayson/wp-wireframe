<?php

use Wireframe\PostFields\FieldGroups;
use Wireframe\PostFields\Location;

require __DIR__ . '/assert.php';

$byId = static function (string $postType, string $id): ?array {
    foreach (FieldGroups::groupsFor($postType) as $group) {
        if ($group['id'] === $id) {
            return $group;
        }
    }

    return null;
};

check(
    'string shorthand expands to post_type in',
    ['all' => [['field' => 'post_type', 'operator' => 'in', 'value' => ['event']]]],
    Location::normalize('event')
);
check(
    'list shorthand expands to post_type in',
    ['all' => [['field' => 'post_type', 'operator' => 'in', 'value' => ['event', 'post']]]],
    Location::normalize(['event', 'post'])
);
check('rule tree is kept', ['any' => [['field' => 'post_id', 'operator' => 'equals', 'value' => 3]]], Location::normalize(['any' => [['field' => 'post_id', 'operator' => 'equals', 'value' => 3]]]));
check('empty string is invalid', null, Location::normalize(''));
check('empty list is invalid', null, Location::normalize([]));
check('a number is invalid', null, Location::normalize(12));
check('a rule without an operator is invalid', null, Location::normalize(['all' => [['field' => 'post_type', 'value' => 'event']]]));
check('a mixed list is invalid', null, Location::normalize(['event', 3]));

check('post types: union of branches', ['a', 'b', 'c'], Location::postTypes(['any' => [
    ['field' => 'post_type', 'operator' => 'equals', 'value' => 'a'],
    ['all' => [
        ['field' => 'post_type', 'operator' => 'in', 'value' => ['b', 'c']],
        ['field' => 'user_role', 'operator' => 'contains', 'value' => 'editor'],
    ]],
]]));
check('post types: no post_type rule is not finite', null, Location::postTypes(['all' => [['field' => 'user_role', 'operator' => 'contains', 'value' => 'editor']]]));
check('post types: one unpinned branch is not finite', null, Location::postTypes(['any' => [
    ['field' => 'post_type', 'operator' => 'equals', 'value' => 'a'],
    ['field' => 'post_id', 'operator' => 'equals', 'value' => 3],
]]));
check('post types: not_in does not pin', null, Location::postTypes(['all' => [['field' => 'post_type', 'operator' => 'not_in', 'value' => ['a']]]]));
check('post types: all narrows to the intersection', ['a'], Location::postTypes(['all' => [
    ['field' => 'post_type', 'operator' => 'in', 'value' => ['a', 'b']],
    ['field' => 'post_type', 'operator' => 'equals', 'value' => 'a'],
]]));

$extras = $byId('event', 'event_extras');
check('event_extras is registered', true, $extras !== null);

$admin  = get_userdata(as_user('administrator'));
$event  = make_event();
$editor = get_userdata(as_user('editor'));

check('context shape', ['post_type' => 'event', 'post_id' => $event, 'user_role' => ['editor']], Location::context($event, $editor));
check('context without a user has no roles', [], Location::context($event, null)['user_role']);
check('applies for administrator', true, Location::applies($extras, $event, $admin));
check('does not apply for editor', false, Location::applies($extras, $event, $editor));
check('does not apply logged out', false, Location::applies($extras, $event, null));

$post = (int) wp_insert_post(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Plain']);
check('does not apply to another post type', false, Location::applies($extras, $post, $admin));

$roleIn = ['location' => ['all' => [['field' => 'user_role', 'operator' => 'in', 'value' => ['author', 'editor']]]]];
check('user_role in matches any of the roles', true, Location::applies($roleIn, $event, $editor));
check('user_role in misses other roles', false, Location::applies($roleIn, $event, $admin));
$roleNotIn = ['location' => ['all' => [['field' => 'user_role', 'operator' => 'not_in', 'value' => ['author', 'editor']]]]];
check('user_role not_in excludes listed roles', false, Location::applies($roleNotIn, $event, $editor));
check('user_role not_in admits other roles', true, Location::applies($roleNotIn, $event, $admin));

$byPostId = ['location' => ['all' => [['field' => 'post_id', 'operator' => 'in', 'value' => [$event]]]]];
check('post_id in', true, Location::applies($byPostId, $event, $admin));
check('post_id in misses other posts', false, Location::applies($byPostId, $post, $admin));

add_filter('wp-wireframe/location_context', static function (array $context, int $postId, ?WP_User $user): array {
    $context['post_status'] = get_post_status($postId);

    return $context;
}, 10, 3);

$draftOnly = ['location' => ['all' => [['field' => 'post_status', 'operator' => 'equals', 'value' => 'draft']]]];
check('filter adds a param to the context', 'draft', Location::context($event, $admin)['post_status'] ?? null);
check('a rule on a filtered param evaluates', true, Location::applies($draftOnly, $event, $admin));
wp_update_post(['ID' => $event, 'post_status' => 'publish']);
check('a rule on a filtered param can fail', false, Location::applies($draftOnly, $event, $admin));

finish();
