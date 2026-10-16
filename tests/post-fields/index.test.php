<?php

use Wireframe\Meta;
use Wireframe\PostFields\FieldGroups;
use Wireframe\PostFields\Index\IndexTable;

require __DIR__ . '/assert.php';

global $wpdb;

as_user('administrator');

$table = $wpdb->prefix . 'wireframe_index';
$rows  = static fn (int $id): array => $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE post_id = %d ORDER BY id", $id), ARRAY_A);
$count = static fn (int $id, ?string $subfield = null): int => count(array_filter(
    $rows($id),
    static fn (array $row): bool => $subfield === null || $row['subfield'] === $subfield
));

check('the index table exists', $table, $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wireframe_index'"));
check('the schema version is stored', true, (int) get_option('wireframe_index_db_version') >= 1);

$id = make_event();
Meta::set($id, 'sessions', [['starts' => '2026-11-01', 'venue' => 'Leeds', 'seats' => 40, 'notes' => 'x', 'tags' => ['a', 'b']]]);

check('one row per indexed value, multi-value subfields included', 5, $count($id));
check('a subfield that is not indexed has no rows', 0, $count($id, 'notes'));
check('a multi-value subfield writes a row per value', 2, $count($id, 'tags'));
check('rows carry the repeater key and row index', [['sessions', '0']], array_values(array_unique(array_map(
    static fn (array $row): array => [$row['field'], $row['row_index']],
    $rows($id)
), SORT_REGULAR)));

$cell = static fn (int $id, string $subfield): array => array_values(array_filter($rows($id), static fn (array $row): bool => $row['subfield'] === $subfield))[0] ?? ['absent' => true];

$col = static fn (int $id, string $subfield, string $column): mixed => array_key_exists($column, $cell($id, $subfield)) ? $cell($id, $subfield)[$column] : 'absent';

$seats  = $cell($id, 'seats');
$starts = $cell($id, 'starts');
$venue  = $cell($id, 'venue');
check('a number fills value_num', 40.0, (float) ($seats['value_num'] ?? null));
check('a number also fills value_str', '40', $seats['value_str'] ?? null);
check('a date fills value_date', '2026-11-01 00:00:00', $starts['value_date'] ?? null);
check('a date also fills value_str', '2026-11-01', $starts['value_str'] ?? null);
check('text leaves value_num empty', null, $col($id, 'venue', 'value_num'));
check('text leaves value_date empty', null, $col($id, 'venue', 'value_date'));

update_post_meta($id, 'sessions', [['starts' => '', 'venue' => 'York', 'seats' => '', 'notes' => '', 'tags' => []]]);
check('updating through update_post_meta leaves nothing of the old rows', 0, count(array_filter($rows($id), static fn (array $row): bool => $row['value_str'] === 'Leeds')));
check('blank subfields are not indexed as empty strings', [], array_filter(array_column($rows($id), 'value_str'), static fn (string $v): bool => $v === ''));

update_post_meta($id, 'sessions', [
    ['venue' => 'A', 'seats' => 1, 'tags' => ['c']],
    ['venue' => 'B', 'seats' => 2, 'tags' => []],
]);
check('rows are numbered by position', ['0', '0', '0', '1', '1'], array_column($rows($id), 'row_index'));

update_post_meta($id, 'sessions', [['venue' => '2026-13-45']]);
check('an impossible date leaves value_date empty', null, $col($id, 'venue', 'value_date'));
update_post_meta($id, 'sessions', [['venue' => '12abc']]);
check('a non-numeric value leaves value_num empty', null, $col($id, 'venue', 'value_num'));
update_post_meta($id, 'sessions', [['venue' => '2026-11-01 18:30']]);
check('a time without seconds is completed', '2026-11-01 18:30:00', $col($id, 'venue', 'value_date'));
update_post_meta($id, 'sessions', [['venue' => str_repeat('é', 300)]]);
check('value_str is cut to 191 characters', 191, mb_strlen($cell($id, 'venue')['value_str'] ?? ''));

delete_post_meta($id, 'sessions');
check('deleting the meta removes the rows', 0, $count($id));

Meta::set($id, 'sessions', [['venue' => 'Leeds']]);
check('rows exist before the post is deleted', true, $count($id) > 0);
wp_delete_post($id, true);
check('deleting the post removes its rows', 0, $count($id));

$id = make_event();
rest('POST', "/wp/v2/event/$id", ['title' => 'First', 'meta' => ['sessions' => [['venue' => 'Leeds', 'seats' => 5]]]]);
rest('POST', "/wp/v2/event/$id", ['title' => 'Second', 'meta' => ['sessions' => [['venue' => 'Hull', 'seats' => 6]]]]);
$revisions = array_keys(wp_get_post_revisions($id));
check('a REST save writes the rows', ['Hull'], array_column(array_filter($rows($id), static fn (array $row): bool => $row['subfield'] === 'venue'), 'value_str'));
check('the REST saves created revisions', true, $revisions !== []);
check('revisions have no index rows', 0, array_sum(array_map($count, $revisions)));

$base = $count($id, 'notes');
check('notes has no rows before reindexing', 0, $base);

$fixture = require __DIR__ . '/fixture/config/event.php';
$group   = static fn (array $config): array => [
    'id'       => 'event_details',
    'location' => ['event', 'classic_event'],
    'display'  => ['position' => 'content'],
    'config'   => $config,
];
$withNotesIndexed = $fixture;
foreach ($withNotesIndexed['tabs'] as &$tab) {
    foreach ($tab['sections'] as &$section) {
        foreach ($section['fields'] as &$field) {
            foreach ($field['args']['subfields'] ?? [] as $i => $subfield) {
                if ($subfield['id'] === 'notes') {
                    $field['args']['subfields'][$i]['indexed'] = true;
                }
            }
        }
        unset($field);
    }
    unset($section);
}
unset($tab);

Meta::set($id, 'sessions', [['venue' => 'Hull', 'notes' => 'keep']]);
FieldGroups::register('wf-fixture', $group($withNotesIndexed));
check('marking a subfield indexed does not touch existing rows', 0, $count($id, 'notes'));
check('reindex reports the posts it processed', true, Meta::reindex('event') >= 1);
check('reindex creates rows for the newly indexed subfield', 1, $count($id, 'notes'));

FieldGroups::register('wf-fixture', $group($fixture));
Meta::reindex();
check('reindex drops rows of a subfield that is no longer indexed', 0, $count($id, 'notes'));
check('reindex keeps the rest', true, $count($id, 'venue') === 1);
check('reindexing a post type without repeaters processes nothing', 0, Meta::reindex('post'));

update_option('wireframe_index_db_version', 99);
IndexTable::install();
check('install never lowers the stored version', 99, (int) get_option('wireframe_index_db_version'));
update_option('wireframe_index_db_version', IndexTable::VERSION);

$wpdb->query("RENAME TABLE {$table} TO {$table}_kept");
update_option('wireframe_index_db_version', 0);
$blockCreate = static fn (string $sql): string => str_contains($sql, "CREATE TABLE {$table} ") ? 'SELECT 1' : $sql;
add_filter('query', $blockCreate);
IndexTable::install();
remove_filter('query', $blockCreate);
check('a failed install leaves the version, so it is retried', 0, (int) get_option('wireframe_index_db_version'));
IndexTable::install();
check('the retry installs the table', [IndexTable::VERSION, $table], [(int) get_option('wireframe_index_db_version'), $wpdb->get_var("SHOW TABLES LIKE '{$table}'")]);
$wpdb->query("DROP TABLE {$table}");
$wpdb->query("RENAME TABLE {$table}_kept TO {$table}");

$lastChanged = wp_cache_get_last_changed('posts');
Meta::reindex('event');
check('reindex invalidates cached post queries', true, $lastChanged !== wp_cache_get_last_changed('posts'));

finish();
