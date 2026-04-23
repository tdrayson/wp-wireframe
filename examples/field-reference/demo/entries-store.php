<?php

/**
 * Demo data store for the `table` field example.
 *
 * Backed by a WP transient so the demo is self-contained (no custom DB
 * table required) and mutations actually persist across requests. The
 * entries are seeded automatically if the store is empty.
 *
 * @package FieldReference
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

const FIELD_REFERENCE_ENTRIES_TRANSIENT = 'field_reference_demo_entries';

/**
 * Seed the store with fake entries if it's empty.
 *
 * @return array<int, array<string, mixed>>
 */
function field_reference_entries_all(): array
{
    $entries = get_transient(FIELD_REFERENCE_ENTRIES_TRANSIENT);

    if (is_array($entries)) {
        return $entries;
    }

    $names = [
        'Ada Lovelace', 'Alan Turing', 'Grace Hopper', 'Linus Torvalds',
        'Margaret Hamilton', 'Donald Knuth', 'Barbara Liskov', 'Dennis Ritchie',
        'Radia Perlman', 'Guido van Rossum', 'Brendan Eich', 'Anita Borg',
        'Ken Thompson', 'Edsger Dijkstra', 'Frances Allen', 'Tim Berners-Lee',
        'Hedy Lamarr', 'John McCarthy', 'Jean Sammet', 'Richard Stallman',
    ];

    $statuses = ['active', 'pending', 'archived'];
    $entries  = [];

    foreach ($names as $index => $name) {
        $slug = sanitize_title($name);
        $entries[] = [
            'id'       => $index + 1,
            'name'     => $name,
            'email'    => $slug . '@example.test',
            'status'   => $statuses[$index % count($statuses)],
            'created'  => gmdate('Y-m-d H:i:s', strtotime("-{$index} days") ?: time()),
        ];
    }

    set_transient(FIELD_REFERENCE_ENTRIES_TRANSIENT, $entries, DAY_IN_SECONDS);

    return $entries;
}

/**
 * Persist the full entries array back to the transient.
 *
 * @param array<int, array<string, mixed>> $entries
 */
function field_reference_entries_save(array $entries): void
{
    set_transient(FIELD_REFERENCE_ENTRIES_TRANSIENT, array_values($entries), DAY_IN_SECONDS);
}

/**
 * Data callback for the `table` field.
 *
 * Receives a normalized query array from the framework and returns
 * `['items' => [...], 'total' => int]`.
 *
 * @param array{page:int, per_page:int, search:string, orderby:string, order:string, filters:array} $query
 * @return array{items: array<int, array<string, mixed>>, total: int}
 */
function field_reference_entries_fetch(array $query): array
{
    $entries = field_reference_entries_all();

    // Search across name and email.
    if ($query['search'] !== '') {
        $needle  = strtolower($query['search']);
        $entries = array_values(array_filter($entries, static function (array $row) use ($needle): bool {
            return str_contains(strtolower((string) $row['name']), $needle)
                || str_contains(strtolower((string) $row['email']), $needle);
        }));
    }

    // Filters (DataViews sends an array of { field, operator, value }).
    foreach ($query['filters'] as $filter) {
        $fieldKey = $filter['field'] ?? null;
        $operator = $filter['operator'] ?? 'is';
        $value    = $filter['value'] ?? null;

        if ($fieldKey === null || $value === null) {
            continue;
        }

        $entries = array_values(array_filter($entries, static function (array $row) use ($fieldKey, $operator, $value): bool {
            $matches = ($row[$fieldKey] ?? null) === $value;
            return $operator === 'isNot' ? !$matches : $matches;
        }));
    }

    // Sort.
    if ($query['orderby'] !== '') {
        $key = $query['orderby'];
        $dir = $query['order'] === 'desc' ? -1 : 1;

        usort($entries, static function (array $a, array $b) use ($key, $dir): int {
            return $dir * (($a[$key] ?? '') <=> ($b[$key] ?? ''));
        });
    }

    $total  = count($entries);
    $offset = ($query['page'] - 1) * $query['per_page'];
    $items  = array_slice($entries, $offset, $query['per_page']);

    return ['items' => $items, 'total' => $total];
}

/**
 * Delete action — removes selected rows and returns a summary.
 *
 * @param array<int|string> $ids
 * @return array{success: bool, message: string}
 */
function field_reference_entries_delete(array $ids): array
{
    $ids      = array_map('intval', $ids);
    $entries  = field_reference_entries_all();
    $filtered = array_values(array_filter(
        $entries,
        static fn(array $row): bool => ! in_array((int) $row['id'], $ids, true)
    ));

    $removed = count($entries) - count($filtered);
    field_reference_entries_save($filtered);

    return [
        'success' => true,
        /* translators: %d: number of entries deleted. */
        'message' => sprintf(_n('%d entry deleted.', '%d entries deleted.', $removed, 'field-reference'), $removed),
    ];
}

/**
 * Archive action — flips status to "archived" on selected rows.
 *
 * @param array<int|string> $ids
 * @return array{success: bool, message: string}
 */
function field_reference_entries_archive(array $ids): array
{
    $ids     = array_map('intval', $ids);
    $entries = field_reference_entries_all();
    $changed = 0;

    foreach ($entries as &$row) {
        if (in_array((int) $row['id'], $ids, true) && $row['status'] !== 'archived') {
            $row['status'] = 'archived';
            $changed++;
        }
    }
    unset($row);

    field_reference_entries_save($entries);

    return [
        'success' => true,
        /* translators: %d: number of entries archived. */
        'message' => sprintf(_n('%d entry archived.', '%d entries archived.', $changed, 'field-reference'), $changed),
    ];
}

/**
 * Re-send action — fakes resending a notification for the selected entry.
 *
 * @param array<int|string> $ids
 * @return array{success: bool, message: string}
 */
function field_reference_entries_resend(array $ids): array
{
    $count = count($ids);

    return [
        'success' => true,
        /* translators: %d: number of notifications resent. */
        'message' => sprintf(_n('Notification resent to %d entry.', 'Notifications resent to %d entries.', $count, 'field-reference'), $count),
    ];
}

/**
 * Detail-view fetch — returns a single entry by id, or null if missing.
 *
 * @return array<string, mixed>|null
 */
function field_reference_entries_find(string $id): ?array
{
    $id      = (int) $id;
    $entries = field_reference_entries_all();

    foreach ($entries as $row) {
        if ((int) $row['id'] === $id) {
            return $row;
        }
    }

    return null;
}

/**
 * Detail-view render — builds the HTML shown when a row's "View" is clicked.
 *
 * @param array<string, mixed> $entry
 */
function field_reference_entries_render(array $entry): string
{
    $statusLabels = [
        'active'   => __('Active', 'field-reference'),
        'pending'  => __('Pending', 'field-reference'),
        'archived' => __('Archived', 'field-reference'),
    ];

    $rows = [
        __('ID', 'field-reference')      => esc_html((string) $entry['id']),
        __('Name', 'field-reference')    => esc_html((string) $entry['name']),
        __('Email', 'field-reference')   => '<a href="mailto:' . esc_attr($entry['email']) . '">' . esc_html((string) $entry['email']) . '</a>',
        __('Status', 'field-reference')  => esc_html($statusLabels[$entry['status']] ?? (string) $entry['status']),
        __('Created', 'field-reference') => esc_html((string) $entry['created']),
    ];

    $html  = '<table class="wp-list-table widefat fixed striped">';
    $html .= '<tbody>';
    foreach ($rows as $label => $value) {
        $html .= '<tr>';
        $html .= '<th scope="row" style="width:200px;">' . esc_html($label) . '</th>';
        $html .= '<td>' . $value . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    return $html;
}

/**
 * Resolve the detail-view title for a single entry.
 *
 * @param array<string, mixed> $entry
 */
function field_reference_entries_title(array $entry): string
{
    /* translators: %s: entry name */
    return sprintf(__('Entry: %s', 'field-reference'), (string) $entry['name']);
}

/**
 * Reset action — wipes the transient so the store re-seeds on next fetch.
 *
 * @return array{success: bool, message: string}
 */
function field_reference_entries_reset(): array
{
    delete_transient(FIELD_REFERENCE_ENTRIES_TRANSIENT);

    return [
        'success' => true,
        'message' => __('Demo entries reset to defaults.', 'field-reference'),
    ];
}
