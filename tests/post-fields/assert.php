<?php

/**
 * Tiny assertion harness for post-fields tests, loaded by each `*.test.php`.
 */

declare(strict_types=1);

// Notices must not print or abort: tests assert on them through `doing_it_wrong_run`.
add_filter('doing_it_wrong_trigger_error', '__return_false');

$GLOBALS['wf_test_passed'] = 0;
$GLOBALS['wf_test_failed'] = 0;

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
 * Print the totals and exit non-zero if any check failed.
 */
function finish(): never
{
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
