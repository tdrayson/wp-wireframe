<?php

declare(strict_types=1);

namespace Wireframe\PostFields;

/**
 * Resolves `{repeater}.{row}.{subfield}` and `{repeater}.{subfield}` reads through `get_post_meta()` on grouped post types.
 */
final class DottedMeta
{
    /**
     * Read one cell, or a subfield across every row, from a repeater's stored value.
     *
     * @param mixed  $value    Short-circuit value so far.
     * @param int    $objectId Post ID.
     * @param string $metaKey  Meta key being read.
     * @param bool   $single   Whether a single value was asked for.
     * @return mixed Values for core to unwrap; null leaves the read to core.
     */
    public static function read(mixed $value, int $objectId, string $metaKey, bool $single): mixed
    {
        if ($value !== null || !str_contains($metaKey, '.')) {
            return $value;
        }

        $path = self::path($objectId, $metaKey);

        if ($path === null) {
            return $value;
        }

        [$repeater, $row, $subfield] = $path;

        // Through get_post_meta so rename aliases and defaults apply; the key isn't dotted, so this filter passes it by.
        $rows  = get_post_meta($objectId, $repeater, true);
        $cells = self::cells(is_array($rows) ? array_values($rows) : [], $row, $subfield);

        if ($cells === []) {
            return null;
        }

        return $single ? [$cells[0]] : $cells;
    }

    /**
     * Split a dotted key into repeater, row index (null for every row) and subfield, when it names a repeater subfield on the post's type.
     *
     * @return array{0: string, 1: int|null, 2: string}|null
     */
    private static function path(int $objectId, string $metaKey): ?array
    {
        $parts = explode('.', $metaKey);

        if (count($parts) === 2) {
            [$repeater, $subfield] = $parts;
            $row                   = null;
        } elseif (count($parts) === 3 && ctype_digit($parts[1])) {
            [$repeater, $row, $subfield] = [$parts[0], (int) $parts[1], $parts[2]];
        } else {
            return null;
        }

        $postType = get_post_type($objectId);

        if ($postType === false) {
            return null;
        }

        $fields = FieldGroups::flatFields($postType);

        if (!isset($fields[$repeater], $fields["{$repeater}.{$subfield}"]) || StorageKind::for($fields[$repeater]) !== StorageKind::REPEATER) {
            return null;
        }

        return [$repeater, $row, $subfield];
    }

    /**
     * Get the subfield's value in one row, or in every row with blanks for rows that lack it.
     *
     * @param list<mixed> $rows Repeater rows.
     * @return list<mixed>
     */
    private static function cells(array $rows, ?int $row, string $subfield): array
    {
        if ($row !== null) {
            $cell = is_array($rows[$row] ?? null) ? ($rows[$row][$subfield] ?? null) : null;

            return $cell === null ? [] : [$cell];
        }

        return array_map(static fn (mixed $r): mixed => is_array($r) ? ($r[$subfield] ?? '') : '', $rows);
    }
}
