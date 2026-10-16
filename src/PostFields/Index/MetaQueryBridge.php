<?php

declare(strict_types=1);

namespace Wireframe\PostFields\Index;

use WP_Meta_Query;
use WP_Query;
use Wireframe\PostFields\FieldGroups;

/**
 * Lets `meta_query`, `meta_key` and `orderby` use `{repeater}.{subfield}` keys for indexed subfields of grouped post types.
 */
final class MetaQueryBridge
{
    /**
     * Lift `suppress_filters` when a query uses an indexed dotted key, so the orderby rewrite runs under `get_posts()`.
     *
     * @param WP_Query $query The query about to run.
     */
    public static function prepare(WP_Query $query): void
    {
        if (!self::usesBridgedKey($query)) {
            return;
        }

        $query->set('suppress_filters', false);
    }

    /**
     * Rebuild a post meta query's SQL when it holds a bridged clause; leave every other query untouched.
     *
     * @param array       $sql             JOIN and WHERE from core.
     * @param array       $queries         Sanitised meta query.
     * @param string      $type            Meta type.
     * @param string      $primaryTable    Primary table.
     * @param string      $primaryIdColumn Its ID column.
     * @param object|null $context         The query object, such as a WP_Query.
     * @return array
     */
    public static function rewrite(array $sql, array $queries, string $type, string $primaryTable, string $primaryIdColumn, ?object $context = null): array
    {
        if ($type !== 'post' || !self::hasBridgedClause($queries)) {
            return $sql;
        }

        $query = new BridgedMetaQuery(self::markOrderOnly($queries, $context));

        return $query->bridgedSql($primaryTable, $primaryIdColumn);
    }

    /**
     * Point ORDER BY terms on a bridged clause's dropped alias at the post's lowest (ASC) or highest (DESC) value, empty last.
     *
     * @param array<string, string> $clauses `posts_clauses` pieces.
     * @param WP_Query              $query   The query being built.
     * @return array<string, string>
     */
    public static function orderby(array $clauses, WP_Query $query): array
    {
        $orderby = $clauses['orderby'] ?? '';

        if ($orderby === '' || !$query->meta_query instanceof WP_Meta_Query) {
            return $clauses;
        }

        foreach (self::droppedAliases($query->meta_query->get_clauses()) as $alias => $clause) {
            $orderby = self::replaceTerms($orderby, $alias, $clause);
        }

        $clauses['orderby'] = $orderby;

        return $clauses;
    }

    /**
     * Whether a first-order clause targets an indexed subfield of a repeater on a grouped post type.
     *
     * @param array $clause Meta query clause.
     */
    public static function bridges(array $clause): bool
    {
        $key        = $clause['key'] ?? null;
        $compareKey = strtoupper((string) ($clause['compare_key'] ?? '='));

        if (!is_string($key) || substr_count($key, '.') !== 1 || !in_array($compareKey, ['=', 'EXISTS'], true)) {
            return false;
        }

        [$repeater, $subfield] = explode('.', $key);

        foreach (array_keys(FieldGroups::all()) as $postType) {
            if (in_array($subfield, IndexSync::indexedSubfields((string) $postType, $repeater), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replace every ORDER BY term on one dropped alias with its clause's index subquery.
     */
    private static function replaceTerms(string $orderby, string $alias, array $clause): string
    {
        $replace = static function (array $match) use ($clause): string {
            return self::orderTerm($clause, $match);
        };

        return (string) preg_replace_callback(self::termPattern($alias), $replace, $orderby);
    }

    /**
     * Match the ORDER BY terms core writes for an alias: `CAST(a.meta_value AS T)`, `a.meta_value+0` or `a.meta_value`, then a direction.
     */
    private static function termPattern(string $alias): string
    {
        $alias = preg_quote($alias, '/');
        $cast  = "CAST\\({$alias}\\.meta_value AS ([A-Z]+(?:\\([\\d,\\s]+\\))?)\\)";
        $plain = "(?<![\\w.]){$alias}\\.meta_value(\\+0)?";

        return "/(?:{$cast}|{$plain})\\s+(ASC|DESC)/i";
    }

    /**
     * Build the replacement for one ORDER BY term core wrote against a bridged clause's alias.
     *
     * @param array<int, string> $match Regex match: cast, `+0`, direction.
     */
    private static function orderTerm(array $clause, array $match): string
    {
        global $wpdb;

        $direction = strtoupper($match[3]);
        $type      = ($match[2] ?? '') !== '' ? 'NUMERIC' : self::orderType(strtoupper($match[1]));
        $value = QueryClauses::metaOrder($clause, "{$wpdb->posts}.ID", $type, $direction === 'DESC');

        return "{$value} IS NULL, {$value} {$direction}";
    }

    /**
     * Map the type core cast a meta value to onto an index column type.
     */
    private static function orderType(string $cast): string
    {
        if ($cast === 'DATE' || $cast === 'DATETIME') {
            return $cast;
        }

        $numeric = in_array($cast, ['SIGNED', 'UNSIGNED', 'NUMERIC'], true) || str_starts_with($cast, 'DECIMAL');

        return $numeric ? 'NUMERIC' : 'CHAR';
    }

    /**
     * Get the aliases only bridged clauses use, whose JOINs the rewrite dropped, each with its first clause.
     *
     * @param array<string, array> $clauses Core's flat clauses, with aliases.
     * @return array<string, array>
     */
    private static function droppedAliases(array $clauses): array
    {
        $bridged = [];
        $core    = [];

        foreach ($clauses as $clause) {
            $alias = (string) ($clause['alias'] ?? '');

            if (!self::bridges($clause)) {
                $core[$alias] = true;

                continue;
            }

            $bridged[$alias] ??= $clause;
        }

        return array_diff_key($bridged, $core);
    }

    /**
     * Whether a query's `meta_key`, `orderby` or `meta_query` uses a bridged key.
     */
    private static function usesBridgedKey(WP_Query $query): bool
    {
        foreach ([$query->get('meta_key'), ...self::orderbyKeys($query)] as $key) {
            if (is_string($key) && self::bridges(['key' => $key])) {
                return true;
            }
        }

        $metaQuery = $query->get('meta_query');

        return is_array($metaQuery) && self::hasBridgedClause($metaQuery);
    }

    /**
     * Get the keys a query orders by, from an `orderby` string or array.
     *
     * @return list<mixed>
     */
    private static function orderbyKeys(WP_Query $query): array
    {
        $orderby = $query->get('orderby');

        if (is_array($orderby)) {
            return array_keys($orderby);
        }

        return (array) preg_split('/[\s,]+/', (string) $orderby, -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Whether a meta query tree holds a bridged clause at any depth.
     */
    private static function hasBridgedClause(array $queries): bool
    {
        foreach ($queries as $child) {
            if (!is_array($child)) {
                continue;
            }

            if (isset($child['key']) ? self::bridges($child) : self::hasBridgedClause($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mark the clause WP_Query built from a bare `meta_key` that `orderby` uses, so it orders without filtering out posts that lack a value.
     */
    private static function markOrderOnly(array $queries, ?object $context): array
    {
        $first = $queries[0] ?? null;

        if (!$context instanceof WP_Query || !is_array($first)) {
            return $queries;
        }

        if (($first['key'] ?? null) !== $context->get('meta_key') || isset($first['value']) || isset($first['compare'])) {
            return $queries;
        }

        $orderedBy = array_intersect(['meta_value', 'meta_value_num', $first['key']], self::orderbyKeys($context));

        if ($orderedBy === []) {
            return $queries;
        }

        $queries[0][BridgedMetaQuery::ORDER_ONLY] = true;

        return $queries;
    }
}
