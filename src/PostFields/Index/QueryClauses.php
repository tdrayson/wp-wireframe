<?php

declare(strict_types=1);

namespace Wireframe\PostFields\Index;

use WP_Query;

/**
 * Compiles the `wireframe_query` WP_Query argument into EXISTS subqueries on the index table.
 *
 * Shape, nestable like `meta_query`:
 *
 *     [
 *         'relation' => 'AND',   // or 'OR', at any level
 *         ['field' => 'sessions', 'subfield' => 'starts', 'compare' => '>=', 'value' => '2026-11-01', 'type' => 'DATE'],
 *         ['same_row' => true, ['field' => 'sessions', 'subfield' => 'venue', 'value' => 'Leeds'], …],
 *     ]
 *
 * `type` is CHAR (default), NUMERIC, DECIMAL, SIGNED, DATE or DATETIME; DATE compares the date part only.
 * A clause matches when any one row satisfies it, so `!=` means "some row differs". Blank subfields
 * have no index row: they never match a value compare, and count as absent for EXISTS / NOT EXISTS.
 * Malformed clauses report doing-it-wrong and match nothing.
 */
final class QueryClauses
{
    public const QUERY_VAR = 'wireframe_query';

    public const APPLIED = '_wireframe_query_applied';

    private const COMPARES = ['=', '!=', '>', '>=', '<', '<=', 'IN', 'NOT IN', 'LIKE', 'BETWEEN', 'EXISTS', 'NOT EXISTS'];

    private const COLUMNS = [
        'CHAR'     => 'value_str',
        'NUMERIC'  => 'value_num',
        'DECIMAL'  => 'value_num',
        'SIGNED'   => 'value_num',
        'DATE'     => 'value_date',
        'DATETIME' => 'value_date',
    ];

    private const NONE = ['1=0', []];

    private const ALL = ['1=1', []];

    /**
     * Append the compiled `wireframe_query` to a query's WHERE, once per query across bundled copies.
     *
     * @param array<string, string> $clauses `posts_clauses` pieces.
     * @param WP_Query              $query   The query being built.
     * @return array<string, string>
     */
    public static function apply(array $clauses, WP_Query $query): array
    {
        global $wpdb;

        $tree = $query->get(self::QUERY_VAR);

        if (!is_array($tree) || $tree === [] || $query->get(self::APPLIED)) {
            return $clauses;
        }

        $clauses['where'] = ($clauses['where'] ?? '') . ' AND ' . self::compile($tree, "{$wpdb->posts}.ID");
        $query->set(self::APPLIED, true);

        return $clauses;
    }

    /**
     * Compile a `wireframe_query` tree into a prepared SQL condition.
     *
     * @param array  $query         Query tree.
     * @param string $postsIdColumn Trusted SQL column holding the post ID, such as `wp_posts.ID`.
     * @return string
     */
    public static function compile(array $query, string $postsIdColumn): string
    {
        if (isset($query['field'])) {
            return self::clause($query, $postsIdColumn);
        }

        if (!empty($query['same_row']) && self::relation($query) === 'AND') {
            return self::sameRow($query, $postsIdColumn);
        }

        return self::group($query, $postsIdColumn);
    }

    /**
     * Join a group's children with its relation; an OR same_row group lands here as it needs no shared row.
     */
    private static function group(array $group, string $postId): string
    {
        $parts = array_map(static fn (array $child): string => self::compile($child, $postId), self::children($group));

        if ($parts === []) {
            return '1=1';
        }

        return '(' . implode(' ' . self::relation($group) . ' ', $parts) . ')';
    }

    /**
     * Compile one clause into an EXISTS (or NOT EXISTS) subquery.
     */
    private static function clause(array $clause, string $postId): string
    {
        global $wpdb;

        $match = self::match($clause, 'i');

        if ($match === null) {
            return self::invalid('A wireframe_query clause needs a field, a subfield, a known compare and type, and a value that fits the compare.');
        }

        [$sql, $args, $negated] = $match;
        $table = IndexTable::name();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and column are trusted identifiers.
        $subquery = $wpdb->prepare("SELECT 1 FROM {$table} i WHERE i.post_id = {$postId} AND i.field = %s AND {$sql}", [$clause['field'], ...$args]);

        return ($negated ? 'NOT EXISTS' : 'EXISTS') . " ({$subquery})";
    }

    /**
     * Compile a same_row group into one subquery whose clauses all hold within a single repeater row.
     */
    private static function sameRow(array $group, string $postId): string
    {
        global $wpdb;

        $children = self::children($group);
        $field    = self::sharedField($children);

        if ($field === null) {
            return self::invalid('A "same_row" group must hold only plain, valid clauses on one repeater.');
        }

        $table    = IndexTable::name();
        $from     = "{$table} i0";
        $where    = ["i0.post_id = {$postId}", 'i0.field = %s'];
        $args     = [$field];
        $positive = 0;

        foreach ($children as $index => $child) {
            if (self::compare($child) === 'NOT EXISTS') {
                [$sql, $childArgs] = self::match($child, "n{$index}");
                $where[]           = "NOT EXISTS (SELECT 1 FROM {$table} n{$index} WHERE " . self::sameRowAs("n{$index}") . " AND {$sql})";
                array_push($args, ...$childArgs);

                continue;
            }

            $alias = "i{$positive}";

            if ($positive > 0) {
                $from .= " INNER JOIN {$table} {$alias} ON " . self::sameRowAs($alias);
            }

            [$sql, $childArgs] = self::match($child, $alias);
            $where[]           = $sql;
            array_push($args, ...$childArgs);
            $positive++;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers are ours; values are placeholders.
        return 'EXISTS (' . $wpdb->prepare("SELECT 1 FROM {$from} WHERE " . implode(' AND ', $where), $args) . ')';
    }

    /**
     * Get the SQL tying an alias to the anchor row `i0`.
     */
    private static function sameRowAs(string $alias): string
    {
        return "{$alias}.post_id = i0.post_id AND {$alias}.field = i0.field AND {$alias}.row_index = i0.row_index";
    }

    /**
     * Get the repeater every child of a same_row group targets, or null when they aren't all valid clauses on one field.
     *
     * @param list<array> $children Group children.
     * @return string|null
     */
    private static function sharedField(array $children): ?string
    {
        $field = $children[0]['field'] ?? null;

        if (!is_string($field)) {
            return null;
        }

        foreach ($children as $child) {
            if (($child['field'] ?? null) !== $field || self::match($child, 'i0') === null) {
                return null;
            }
        }

        return $field;
    }

    /**
     * Build the row condition for a clause against one index alias: subfield plus value compare.
     *
     * @param array  $clause Clause.
     * @param string $alias  Index table alias.
     * @return array{0: string, 1: list<mixed>, 2: bool}|null SQL, its args, whether it is NOT EXISTS; null when malformed.
     */
    private static function match(array $clause, string $alias): ?array
    {
        $compare = self::compare($clause);
        $type    = $clause['type'] ?? 'CHAR';
        $type    = is_string($type) ? strtoupper(trim($type)) : '';
        $column  = self::COLUMNS[$type] ?? null;

        if ($compare === null || $column === null || !self::isName($clause['field'] ?? null) || !self::isName($clause['subfield'] ?? null)) {
            return null;
        }

        $sql  = "{$alias}.subfield = %s";
        $args = [$clause['subfield']];

        if ($compare === 'EXISTS' || $compare === 'NOT EXISTS') {
            return [$sql, $args, $compare === 'NOT EXISTS'];
        }

        $expression = $type === 'DATE' ? "DATE({$alias}.{$column})" : "{$alias}.{$column}";
        $condition  = self::condition($compare, $type, $expression, $clause['value'] ?? null);

        if ($condition === null) {
            return null;
        }

        return ["{$sql} AND {$condition[0]}", [...$args, ...$condition[1]], false];
    }

    /**
     * Get a clause's upper-cased compare, defaulting like `meta_query`; null when it isn't allowed.
     */
    private static function compare(array $clause): ?string
    {
        $compare = $clause['compare'] ?? match (true) {
            !array_key_exists('value', $clause) => 'EXISTS',
            is_array($clause['value'])          => 'IN',
            default                             => '=',
        };

        if (!is_string($compare)) {
            return null;
        }

        $compare = strtoupper(trim($compare));

        return in_array($compare, self::COMPARES, true) ? $compare : null;
    }

    /**
     * Build the value condition for a compare, or null when the value's shape doesn't fit it.
     *
     * @return array{0: string, 1: list<mixed>}|null
     */
    private static function condition(string $compare, string $type, string $expression, mixed $value): ?array
    {
        return match ($compare) {
            'LIKE'         => self::like($expression, $value),
            'IN', 'NOT IN' => self::in($compare, $type, $expression, $value),
            'BETWEEN'      => self::between($type, $expression, $value),
            default        => self::binary($compare, $type, $expression, $value),
        };
    }

    /**
     * Build a `LIKE '%value%'` condition.
     *
     * @return array{0: string, 1: list<mixed>}|null
     */
    private static function like(string $expression, mixed $value): ?array
    {
        global $wpdb;

        if (!is_scalar($value)) {
            return null;
        }

        return ["{$expression} LIKE %s", ['%' . $wpdb->esc_like(self::text($value)) . '%']];
    }

    /**
     * Build an IN / NOT IN condition; values that can't be stored in the column are dropped, as they never match.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function in(string $compare, string $type, string $expression, mixed $value): array
    {
        $literals = array_values(array_filter(array_map(
            static fn (mixed $item): ?array => self::literal($item, $type),
            is_array($value) ? array_values($value) : [$value]
        )));

        if ($literals === []) {
            return $compare === 'IN' ? self::NONE : self::ALL;
        }

        return ["{$expression} {$compare} (" . implode(', ', array_column($literals, 0)) . ')', array_column($literals, 1)];
    }

    /**
     * Build an inclusive BETWEEN condition from a two-value array.
     *
     * @return array{0: string, 1: list<mixed>}|null
     */
    private static function between(string $type, string $expression, mixed $value): ?array
    {
        if (!is_array($value) || count($value) !== 2) {
            return null;
        }

        [$low, $high] = array_map(static fn (mixed $bound): ?array => self::literal($bound, $type), array_values($value));

        if ($low === null || $high === null) {
            return self::NONE;
        }

        return ["{$expression} BETWEEN {$low[0]} AND {$high[0]}", [$low[1], $high[1]]];
    }

    /**
     * Build a comparison-operator condition; an unparseable value never matches, so only `!=` holds.
     *
     * @return array{0: string, 1: list<mixed>}|null
     */
    private static function binary(string $compare, string $type, string $expression, mixed $value): ?array
    {
        if (!is_scalar($value)) {
            return null;
        }

        $literal = self::literal($value, $type);

        if ($literal === null) {
            return $compare === '!=' ? self::ALL : self::NONE;
        }

        return ["{$expression} {$compare} {$literal[0]}", [$literal[1]]];
    }

    /**
     * Convert a value to a placeholder and argument for a type's column, or null when it doesn't parse.
     *
     * @return array{0: string, 1: string|float}|null
     */
    private static function literal(mixed $value, string $type): ?array
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = self::text($value);

        return match (self::COLUMNS[$type]) {
            'value_num'  => is_numeric($text) ? ['%f', (float) $text] : null,
            'value_date' => self::dateLiteral($text, $type),
            default      => ['%s', $text],
        };
    }

    /**
     * Normalise a date value as the index stores it, cut to the date part for DATE.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function dateLiteral(string $text, string $type): ?array
    {
        $date = IndexSync::date($text);

        if ($date === null) {
            return null;
        }

        return ['%s', $type === 'DATE' ? substr($date, 0, 10) : $date];
    }

    /**
     * Stringify a scalar the way the index does.
     *
     * @param scalar $value Value.
     */
    private static function text(mixed $value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    /**
     * Check a field or subfield id is a non-empty string.
     */
    private static function isName(mixed $name): bool
    {
        return is_string($name) && $name !== '';
    }

    /**
     * Get a group's relation, AND unless it says OR.
     */
    private static function relation(array $group): string
    {
        $relation = $group['relation'] ?? 'AND';

        return is_string($relation) && strtoupper(trim($relation)) === 'OR' ? 'OR' : 'AND';
    }

    /**
     * Get a group's child clauses and groups.
     *
     * @return list<array>
     */
    private static function children(array $group): array
    {
        return array_values(array_filter($group, 'is_array'));
    }

    /**
     * Report a malformed query and return a condition that matches nothing.
     */
    private static function invalid(string $message): string
    {
        _doing_it_wrong(__CLASS__ . '::compile', esc_html($message), '1.2.0');

        return '1=0';
    }
}
