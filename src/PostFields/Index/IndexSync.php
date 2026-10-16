<?php

declare(strict_types=1);

namespace Wireframe\PostFields\Index;

use Wireframe\PostFields\FieldGroups;
use Wireframe\PostFields\StorageKind;

/**
 * Mirrors the `indexed` subfields of repeater meta into the index table.
 */
final class IndexSync
{
    private const MAX_NAME  = 64;
    private const MAX_STR   = 191;
    private const BATCH     = 200;
    private const NUM_LIMIT = 1e13;

    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/';

    /**
     * Keep the index in step with repeater meta writes and post deletion.
     */
    public static function register(): void
    {
        add_action('added_post_meta', [self::class, 'onMetaChange'], 10, 3);
        add_action('updated_post_meta', [self::class, 'onMetaChange'], 10, 3);
        add_action('deleted_post_meta', [self::class, 'onMetaChange'], 10, 3);
        add_action('deleted_post', [self::class, 'deletePost']);
    }

    /**
     * Re-sync a repeater whose meta changed, unless it has no indexed subfields or belongs to a revision.
     *
     * @param mixed  $metaId Meta ID, or IDs when deleting.
     * @param int    $postId Post ID.
     * @param string $key    Meta key.
     */
    public static function onMetaChange(mixed $metaId, int $postId, string $key): void
    {
        if (wp_is_post_revision($postId) !== false) {
            return;
        }

        $postType = get_post_type($postId);

        if ($postType === false || self::indexedSubfields($postType, $key) === []) {
            return;
        }

        self::syncField($postId, $key);
    }

    /**
     * Rewrite a post's index rows for one repeater from its stored value.
     *
     * @param int    $postId Post ID.
     * @param string $field  Repeater meta key.
     */
    public static function syncField(int $postId, string $field): void
    {
        global $wpdb;

        $table = IndexTable::name();
        $wpdb->delete($table, ['post_id' => $postId, 'field' => $field], ['%d', '%s']);

        $postType = get_post_type($postId);
        $rows     = get_post_meta($postId, $field, true);

        if ($postType === false || !is_array($rows)) {
            return;
        }

        $subfields = self::indexedSubfields($postType, $field);

        if ($subfields === []) {
            return;
        }

        $kinds  = self::subfieldKinds($postType, $field);
        $tuples = [];
        $args   = [];

        foreach (array_values($rows) as $rowIndex => $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($subfields as $subfield) {
                foreach (self::cellValues($row[$subfield] ?? null, $kinds[$subfield] === StorageKind::MULTI) as $value) {
                    [$str, $num, $date] = self::columns($value);

                    $tuples[] = sprintf('(%%d, %%s, %%d, %%s, %%s, %s, %s)', $num === null ? 'NULL' : '%s', $date === null ? 'NULL' : '%s');
                    array_push($args, $postId, $field, $rowIndex, $subfield, $str);

                    if ($num !== null) {
                        $args[] = $num;
                    }

                    if ($date !== null) {
                        $args[] = $date;
                    }
                }
            }
        }

        if ($tuples === []) {
            return;
        }

        $sql = "INSERT INTO {$table} (post_id, field, row_index, subfield, value_str, value_num, value_date) VALUES " . implode(', ', $tuples);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only; the table name is ours.
        $wpdb->query($wpdb->prepare($sql, $args));
    }

    /**
     * Remove every index row of a post.
     *
     * @param int $postId Post ID.
     */
    public static function deletePost(int $postId): void
    {
        global $wpdb;

        $wpdb->delete(IndexTable::name(), ['post_id' => $postId], ['%d']);
    }

    /**
     * Rebuild the index rows of every repeater on a post type, or on every post type with groups.
     *
     * @param string|null $postType Post type slug, or null for all.
     * @return int Posts processed.
     */
    public static function reindex(?string $postType = null): int
    {
        global $wpdb;

        $types     = $postType === null ? array_keys(FieldGroups::all()) : [$postType];
        $processed = 0;

        foreach ($types as $type) {
            $repeaters = self::repeaterIds($type);

            if ($repeaters === []) {
                continue;
            }

            $last = 0;

            do {
                $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID LIMIT %d",
                    $type,
                    $last,
                    self::BATCH
                )));

                foreach ($ids as $id) {
                    foreach ($repeaters as $repeater) {
                        self::syncField($id, $repeater);
                    }

                    $last = $id;
                    $processed++;
                }
            } while (count($ids) === self::BATCH);
        }

        return $processed;
    }

    /**
     * Get the ids of a repeater's direct subfields that are marked `indexed` and store a scalar or choice-set value.
     *
     * @param string $postType Post type slug.
     * @param string $field    Top-level field id.
     * @return string[]
     */
    public static function indexedSubfields(string $postType, string $field): array
    {
        return array_map('strval', array_keys(self::subfieldKinds($postType, $field, true)));
    }

    /**
     * Get the storage kind of each direct subfield of a repeater, keyed by id; optionally only the indexable ones.
     *
     * @return array<string, string>
     */
    private static function subfieldKinds(string $postType, string $field, bool $indexedOnly = false): array
    {
        $repeater = FieldGroups::flatFields($postType)[$field] ?? null;

        if ($repeater === null || strlen($field) > self::MAX_NAME || StorageKind::for($repeater) !== StorageKind::REPEATER) {
            return [];
        }

        $kinds = [];

        foreach ($repeater['args']['subfields'] ?? [] as $subfield) {
            $id   = (string) ($subfield['id'] ?? '');
            $kind = StorageKind::for($subfield);

            if ($id === '' || strlen($id) > self::MAX_NAME) {
                continue;
            }

            if ($indexedOnly && (empty($subfield['indexed']) || !in_array($kind, [StorageKind::SCALAR, StorageKind::MULTI], true))) {
                continue;
            }

            $kinds[$id] = $kind;
        }

        return $kinds;
    }

    /**
     * Get the ids of a post type's top-level repeaters.
     *
     * @return string[]
     */
    private static function repeaterIds(string $postType): array
    {
        $ids = [];

        foreach (FieldGroups::flatFields($postType) as $id => $field) {
            if (!str_contains((string) $id, '.') && StorageKind::for($field) === StorageKind::REPEATER) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * Get the values to index for one cell: each value of a choice set, otherwise the scalar itself; blanks are skipped.
     *
     * @return list<scalar>
     */
    private static function cellValues(mixed $cell, bool $multi): array
    {
        $values = $multi ? array_values((array) $cell) : [$cell];

        return array_values(array_filter($values, static fn (mixed $v): bool => is_scalar($v) && $v !== ''));
    }

    /**
     * Parse a value into the str, num and date columns; num and date are null when it doesn't parse.
     *
     * @param scalar $value Cell value.
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private static function columns(mixed $value): array
    {
        $string = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        $num    = null;

        if (is_numeric($string) && abs((float) $string) < self::NUM_LIMIT) {
            $num = number_format((float) $string, 6, '.', '');
        }

        return [mb_substr($string, 0, self::MAX_STR), $num, self::date($string)];
    }

    /**
     * Normalise a `Y-m-d[ H:i[:s]]` string to a DATETIME, or null when it isn't a real date.
     */
    private static function date(string $value): ?string
    {
        if (preg_match(self::DATE_PATTERN, $value) !== 1) {
            return null;
        }

        $full = match (strlen($value)) {
            10      => $value . ' 00:00:00',
            16      => $value . ':00',
            default => $value,
        };

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $full);

        return $parsed !== false && $parsed->format('Y-m-d H:i:s') === $full ? $full : null;
    }
}
