<?php

/**
 * Tiny assertion harness for post-fields tests, loaded by each `*.test.php`.
 */

declare(strict_types=1);

// Notices must not print or abort: tests assert on them through `doing_it_wrong_run`.
add_filter('doing_it_wrong_trigger_error', '__return_false');

$GLOBALS['wf_test_passed'] = 0;
$GLOBALS['wf_test_failed'] = 0;
$GLOBALS['wf_test_posts']  = [];

add_action('wp_insert_post', static function (int $postId, WP_Post $post, bool $update): void {
    if (!$update && $post->post_type !== 'revision') {
        $GLOBALS['wf_test_posts'][] = $postId;
    }
}, 10, 3);
add_action('add_attachment', static function (int $postId): void {
    $GLOBALS['wf_test_posts'][] = $postId;
});

/**
 * Record one assertion and print its result.
 *
 * @param string $label    What is being checked.
 * @param mixed  $expected Expected value, compared with `===`.
 * @param mixed  $actual   Actual value.
 */
function check(string $label, mixed $expected, mixed $actual): void
{
    if ($expected === $actual) {
        $GLOBALS['wf_test_passed']++;
        echo "PASS  $label\n";

        return;
    }

    $GLOBALS['wf_test_failed']++;
    echo "FAIL  $label\n";
    echo '      expected: ' . var_export($expected, true) . "\n";
    echo '      actual:   ' . var_export($actual, true) . "\n";
}

/**
 * Delete the posts the suite created, print the totals and exit non-zero if any check failed.
 */
function finish(): never
{
    foreach (array_reverse($GLOBALS['wf_test_posts']) as $postId) {
        wp_delete_post($postId, true);
    }

    $failed = $GLOBALS['wf_test_failed'];
    echo "\n{$GLOBALS['wf_test_passed']} passed, $failed failed\n";

    exit($failed > 0 ? 1 : 0);
}

/**
 * Dispatch an in-process REST request as the current user.
 *
 * @param string $method HTTP method.
 * @param string $route  Route such as `/wp/v2/event/12`.
 * @param array  $params JSON body (ignored for GET).
 * @param array  $query  Query-string params.
 */
function rest(string $method, string $route, array $params = [], array $query = []): WP_REST_Response
{
    $request = new WP_REST_Request($method, $route);
    $request->set_query_params($query);

    if ($params !== [] && $method !== 'GET') {
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode($params));
    }

    return rest_do_request($request);
}

/**
 * Switch the current user to a test account with the given role.
 *
 * Accounts are created on first use. '', 'guest', 'logged_out' and 'none' log out.
 *
 * @param string $role Role slug.
 * @return int User ID, or 0 when logged out.
 */
function as_user(string $role): int
{
    if (in_array($role, ['', 'guest', 'logged_out', 'none'], true)) {
        wp_set_current_user(0);

        return 0;
    }

    $login = "wf_test_$role";
    $user  = get_user_by('login', $login);
    $id    = $user ? $user->ID : wp_insert_user([
        'user_login' => $login,
        'user_pass'  => wp_generate_password(),
        'user_email' => "$login@example.com",
        'role'       => $role,
    ]);

    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }

    wp_set_current_user((int) $id);

    return (int) $id;
}

/**
 * Create an `event` post.
 *
 * @param array  $meta   Meta written through `meta_input`.
 * @param string $status Post status.
 * @return int Post ID.
 */
function make_event(array $meta = [], string $status = 'draft'): int
{
    $id = wp_insert_post([
        'post_type'   => 'event',
        'post_status' => $status,
        'post_title'  => 'Event',
        'meta_input'  => $meta,
    ], true);

    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }

    return $id;
}

/**
 * Insert bare posts straight into the table, for batch tests that need hundreds.
 *
 * @param string $postType Post type.
 * @param int    $count    How many.
 * @return list<int> Post IDs, ascending.
 */
function bulk_posts(string $postType, int $count): array
{
    global $wpdb;

    $blank = array_fill_keys(['post_content', 'post_excerpt', 'to_ping', 'pinged', 'post_content_filtered'], '');
    $ids   = [];

    for ($i = 0; $i < $count; $i++) {
        $wpdb->insert($wpdb->posts, $blank + ['post_type' => $postType, 'post_status' => 'publish', 'post_title' => "Bulk {$i}"]);
        $ids[] = (int) $wpdb->insert_id;
    }

    return $ids;
}

/**
 * Delete posts made by `bulk_posts()`, with their meta and index rows.
 *
 * @param list<int> $ids Post IDs.
 */
function delete_bulk_posts(array $ids): void
{
    global $wpdb;

    $in = implode(',', array_map('intval', $ids));

    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})");
    $wpdb->query("DELETE FROM {$wpdb->prefix}wireframe_index WHERE post_id IN ({$in})");
    $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$in})");
    wp_cache_flush();
}

/**
 * Run a callback, counting post meta cache loads and whether a cached sentinel survives it.
 *
 * @param callable $run   Callback.
 * @param string   $group Cache group for the sentinel.
 * @return array{meta: int, kept: mixed}
 */
function count_meta_loads(callable $run, string $group): array
{
    global $wpdb;

    $loads = 0;
    $count = static function (string $sql) use (&$loads, $wpdb): string {
        if (str_contains($sql, "meta_key, meta_value FROM {$wpdb->postmeta}")) {
            $loads++;
        }

        return $sql;
    };

    wp_cache_set('kept', 1, $group);
    add_filter('query', $count);
    $run();
    remove_filter('query', $count);

    return ['meta' => $loads, 'kept' => wp_cache_get('kept', $group)];
}
