<?php

use Wireframe\Meta;
use Wireframe\PostFields\ColumnRenderer;
use Wireframe\PostFields\Columns;
use Wireframe\PostFields\FieldGroups;

require __DIR__ . '/assert.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

$created = [];
$event   = static function (array $meta = [], string $status = 'draft') use (&$created): int {
    $id        = make_event($meta, $status);
    $created[] = $id;

    return $id;
};
$speaker = static function (string $title, string $status = 'publish') use (&$created): int {
    $id        = wp_insert_post(['post_type' => 'speaker', 'post_title' => $title, 'post_status' => $status]);
    $created[] = $id;

    return $id;
};
$field   = static fn (string $id): array => FieldGroups::flatFields('event')[$id];
$cell    = static function (string $column, int $postId): string {
    ob_start();
    do_action('manage_event_posts_custom_column', $column, $postId);

    return (string) ob_get_clean();
};
$columns = static fn (): array => (array) apply_filters('manage_event_posts_columns', ['cb' => '', 'title' => 'Title', 'date' => 'Date']);
$sorted  = static function (string $orderby, string $order = 'asc'): array {
    set_current_screen('edit-event');
    $query = new WP_Query();
    $GLOBALS['wp_the_query'] = $query;
    $query->query(['post_type' => 'event', 'post_status' => 'any', 'orderby' => $orderby, 'order' => $order, 'posts_per_page' => -1, 'fields' => 'ids']);

    return array_map('intval', $query->posts);
};

as_user('administrator');
Columns::register();

// Column registration.
$cols = $columns();
$keys = array_keys($cols);
check('column goes right after title', 'wireframe_event_starts', $keys[array_search('title', $keys, true) + 1]);
check('declared columns keep their order after title', ['title', 'wireframe_event_starts', 'wireframe_is_featured', 'wireframe_price', 'wireframe_genres', 'wireframe_admin_notes', 'wireframe_logo', 'wireframe_speakers', 'wireframe_sessions', 'date'], array_slice($keys, 1));
check('label defaults to the field label', 'Starts', $cols['wireframe_event_starts']);
check('fields without column get none', false, isset($cols['wireframe_secret_code']));
$sortable = (array) apply_filters('manage_edit-event_sortable_columns', []);
check('sortable columns', ['wireframe_price', 'wireframe_event_starts'], array_keys($sortable));

// Renderer defaults.
check('toggle on', true, str_contains(ColumnRenderer::render($field('is_featured'), true, 0), '✓'));
check('toggle off is blank', '', ColumnRenderer::render($field('is_featured'), false, 0));
check('checkboxes use option labels', 'Jazz, Folk', ColumnRenderer::render($field('genres'), ['jazz', 'folk'], 0));
check('unknown option falls back to its value', 'Jazz, zzz', ColumnRenderer::render($field('genres'), ['jazz', 'zzz'], 0));
check('option labels are escaped', 'a &lt;b&gt;', ColumnRenderer::render(['type' => 'select', 'args' => ['options' => ['x' => 'a <b>']]], 'x', 0));
check('repeater counts rows', '2 rows', ColumnRenderer::render($field('sessions'), [['venue' => 'a'], ['venue' => 'b']], 0));
check('repeater with one row', '1 row', ColumnRenderer::render($field('sessions'), [['venue' => 'a']], 0));
check('date uses the site format', wp_date(get_option('date_format'), strtotime('2026-03-05 UTC'), new DateTimeZone('UTC')), ColumnRenderer::render($field('event_starts'), '2026-03-05', 0));
check('time uses the site format', wp_date(get_option('time_format'), strtotime('1970-01-01 14:30 UTC'), new DateTimeZone('UTC')), ColumnRenderer::render(['type' => 'time'], '14:30', 0));
check('color swatch with hex', true, (bool) preg_match('/background:#ff0000.*#ff0000/s', ColumnRenderer::render(['type' => 'color'], '#ff0000', 0)));
check('invalid color is just escaped text', '<b>', html_entity_decode(ColumnRenderer::render(['type' => 'color'], '<b>', 0)));
check('invalid color is escaped', false, str_contains(ColumnRenderer::render(['type' => 'color'], '<b>', 0), '<b>'));
check('text is escaped', '&lt;script&gt;alert(1)&lt;/script&gt;', ColumnRenderer::render(['type' => 'text'], '<script>alert(1)</script>', 0));
check('long text is cut to 80 characters', 80, mb_strlen(html_entity_decode(ColumnRenderer::render(['type' => 'text'], str_repeat('é', 200), 0))));
check('password is masked', false, str_contains(ColumnRenderer::render(['type' => 'password'], 'hunter2', 0), 'hunter2'));

// Files.
$pngPath = wp_tempnam('wf-col.png');
file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$image = media_handle_sideload(['name' => 'wf-col.png', 'tmp_name' => $pngPath], 0);
$pdf   = wp_insert_attachment(['post_mime_type' => 'application/pdf', 'post_title' => 'Doc', 'post_status' => 'inherit'], false, 0);
$created[] = $image;
$created[] = $pdf;
$html = ColumnRenderer::render($field('logo'), $image, 0);
check('single image file is a thumbnail', true, str_contains($html, '<img'));
check('thumbnail is 40px', true, str_contains($html, 'width:40px'));
check('non-image file gets an icon', true, str_contains(ColumnRenderer::render($field('logo'), $pdf, 0), '<img'));
check('missing file is blank', '', ColumnRenderer::render($field('logo'), 0, 0));
check('multiple files show the first plus the rest', true, str_contains(ColumnRenderer::render($field('gallery'), [$image, $pdf, $pdf], 0), '+2'));
check('one file in a list has no +N', false, str_contains(ColumnRenderer::render($field('gallery'), [$image], 0), '+'));

// Populated posts.
$ada   = $speaker('Ada');
$grace = $speaker('Grace', 'draft');
check('populated posts render as linked titles', true, str_contains(ColumnRenderer::render($field('speakers'), [$ada], 0), '>Ada</a>'));
check('populated post link goes to a URL', true, (bool) preg_match('#<a href="[^"]+">Ada</a>#', ColumnRenderer::render($field('speakers'), [$ada], 0)));
check('titles are joined with commas', true, str_contains(ColumnRenderer::render($field('speakers'), [$ada, $grace], 0), '</a>, <a '));
as_user('subscriber');
$visible = ColumnRenderer::render($field('speakers'), [$ada, $grace], 0);
check('posts the user cannot read are left out', [true, false], [str_contains($visible, '>Ada</a>'), str_contains($visible, 'Grace')]);
as_user('administrator');
check('populated terms render as plain labels', 'Featured', ColumnRenderer::render($field('event_tags'), [(int) term_exists('Featured', 'event_type')['term_id']], 0));

// Cells, filters and location.
$id = $event(['event_starts' => '2026-03-05', 'price' => 9, 'is_featured' => true]);
Meta::set($id, 'genres', ['jazz', 'folk']);
Meta::set($id, 'speakers', [$ada]);
check('cell shows the default output', 'Jazz, Folk', $cell('wireframe_genres', $id));
check('cell escapes an unfilled field to blank', '', $cell('wireframe_logo', $id));
check('speakers cell links the title', true, str_contains($cell('wireframe_speakers', $id), '>Ada</a>'));

$seen = [];
add_filter('wf-fixture/column/event/price', static function (string $html, mixed $value, int $postId, array $f) use (&$seen): string {
    $seen['specific'] = [$html, $value, $postId, $f['id']];

    return "<b>$html</b>";
}, 10, 4);
add_filter('wf-fixture/column', static function (string $html, mixed $value, int $postId, array $f) use (&$seen): string {
    if ($f['id'] === 'price') {
        $seen['generic'] = $html;
    }

    return $html;
}, 10, 4);
check('per-field filter replaces the html', '<b>9</b>', $cell('wireframe_price', $id));
check('per-field filter arguments', ['9', 9, $id, 'price'], $seen['specific']);
check('generic filter sees the per-field result', '<b>9</b>', $seen['generic']);
remove_all_filters('wf-fixture/column/event/price');
remove_all_filters('wf-fixture/column');
check('cell is plain again without filters', '9', $cell('wireframe_price', $id));

$filters = 0;
add_filter('wf-fixture/column', static function (string $html) use (&$filters): string {
    $filters++;

    return $html;
});
check('unknown column renders nothing', '', $cell('wireframe_nope', $id));
remove_all_filters('wf-fixture/column');
check('unknown column runs no filter', 0, $filters);

// A group that does not apply to the row leaves its cell blank.
$gated = $event();
wp_set_post_terms($gated, [], 'event_type');
FieldGroups::register('wf-fixture', [
    'id'       => 'col_gated',
    'title'    => 'Gated',
    'location' => ['all' => [
        ['field' => 'post_type', 'operator' => 'equals', 'value' => 'event'],
        ['field' => 'post_terms', 'operator' => 'contains', 'value' => (int) term_exists('Featured', 'event_type')['term_id']],
    ]],
    'config'   => ['fields' => [['id' => 'col_gated_note', 'type' => 'text', 'label' => 'Gated note', 'column' => true]]],
]);
update_post_meta($gated, 'col_gated_note', 'hello');
Columns::reset();
check('gated column is listed on the screen', true, isset($columns()['wireframe_col_gated_note']));
check('cell is blank when the group does not apply to the row', '', $cell('wireframe_col_gated_note', $gated));
wp_set_post_terms($gated, [(int) term_exists('Featured', 'event_type')['term_id']], 'event_type');
Columns::reset();
check('cell shows once the group applies', 'hello', $cell('wireframe_col_gated_note', $gated));

// Sorting.
$low  = $event(['price' => 9, 'event_starts' => '2026-05-01']);
$high = $event(['price' => 10, 'event_starts' => '2026-04-01']);
$none = $event();
$mine = static fn (array $ids): array => array_values(array_intersect($ids, [$low, $high, $none]));
check('price sorts numerically ascending', [$low, $high], array_slice($mine($sorted('wireframe_price')), -2));
check('price sorts numerically descending', [$high, $low], array_slice($mine($sorted('wireframe_price', 'desc')), 0, 2));
check('posts without a value stay in the list', true, in_array($none, $sorted('wireframe_price'), true));
check('date sorts by its stored string', [$high, $low], array_slice($mine($sorted('wireframe_event_starts')), -2));
$query = static function (string $orderby, bool $main = true): WP_Query {
    set_current_screen('edit-event');
    $q = new WP_Query();
    $main && ($GLOBALS['wp_the_query'] = $q);
    $q->query(['post_type' => 'event', 'orderby' => $orderby, 'fields' => 'ids']);

    return $q;
};
check('unsortable column leaves the query alone', ['wireframe_genres', ''], [$query('wireframe_genres')->get('orderby'), $query('wireframe_genres')->get('meta_query')]);
check('only the main admin query is sorted', '', $query('wireframe_price', false)->get('meta_query'));

// Access.
as_user('editor');
Columns::reset();
check('editor has no admin_notes column', false, isset($columns()['wireframe_admin_notes']));
check('editor still has public columns', true, isset($columns()['wireframe_price']));
check('editor cell is blank for a hidden field', '', $cell('wireframe_admin_notes', $id));
check('editor cannot sort by a hidden field', '', $query('wireframe_admin_notes')->get('meta_query'));
as_user('administrator');
Columns::reset();
check('administrator has the admin_notes column', true, isset($columns()['wireframe_admin_notes']));

foreach (array_reverse($created) as $postId) {
    wp_delete_post($postId, true);
}

finish();
