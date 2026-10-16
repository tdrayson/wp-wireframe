<?php

declare(strict_types=1);

namespace Wireframe\PostFields\Index;

use WP_Meta_Query;

/**
 * A post meta query whose indexed `{repeater}.{subfield}` clauses compile onto the index table; other clauses keep core's SQL.
 */
final class BridgedMetaQuery extends WP_Meta_Query
{
    /** Marks the clause WP_Query built from a bare `meta_key`, which only orders. */
    public const ORDER_ONLY = '_wireframe_order_only';

    /** @var array<string, list<string>> JOINs core built for bridged clauses, by alias. */
    private array $bridgedJoins = [];

    /** @var array<string, true> Aliases used by core clauses. */
    private array $coreAliases = [];

    private string $postId = '';

    /**
     * Build the JOIN and WHERE as `get_sql()` would, without firing `get_meta_sql` again.
     *
     * @param string $primaryTable    Primary table, such as `wp_posts`.
     * @param string $primaryIdColumn Its ID column.
     * @return array{join: string, where: string}
     */
    public function bridgedSql(string $primaryTable, string $primaryIdColumn): array
    {
        global $wpdb;

        $this->table_aliases     = [];
        $this->clauses           = [];
        $this->bridgedJoins      = [];
        $this->coreAliases       = [];
        $this->meta_table        = $wpdb->postmeta;
        $this->meta_id_column    = 'post_id';
        $this->primary_table     = $primaryTable;
        $this->primary_id_column = $primaryIdColumn;
        $this->postId            = "{$primaryTable}.{$primaryIdColumn}";

        $sql = $this->get_sql_clauses();

        foreach ($this->sharedJoins() as $join) {
            $sql['join'] .= ' ' . $join;
        }

        if (str_contains($sql['join'], 'LEFT JOIN')) {
            $sql['join'] = str_replace('INNER JOIN', 'LEFT JOIN', $sql['join']);
        }

        // After the LEFT JOIN rule, so core's own joins stay as core wrote them.
        foreach ($this->droppedAliases() as $alias) {
            $sql['join'] .= " LEFT JOIN (SELECT NULL AS meta_id, NULL AS post_id, NULL AS meta_key, NULL AS meta_value) AS {$alias} ON 1=0";
        }

        return $sql;
    }

    /**
     * Swap a bridged clause's JOIN and WHERE for an index subquery, after core has assigned its alias.
     *
     * @param array      $clause       Query clause, by reference.
     * @param array      $parent_query Parent query array.
     * @param string|int $clause_key   Clause name or index.
     * @return array{join: list<string>, where: list<string>}
     */
    public function get_sql_for_clause(&$clause, $parent_query, $clause_key = '') // phpcs:ignore -- core's signature.
    {
        global $wpdb;

        $original = $clause;
        $sql      = parent::get_sql_for_clause($clause, $parent_query, $clause_key);
        $alias    = $clause['alias'];

        if (!MetaQueryBridge::bridges($original)) {
            $this->coreAliases[$alias] = true;

            return $sql;
        }

        $this->bridgedJoins[$alias] = [...($this->bridgedJoins[$alias] ?? []), ...$sql['join']];

        if (!empty($original[self::ORDER_ONLY])) {
            return ['join' => [], 'where' => []];
        }

        [$condition, $args] = QueryClauses::metaClause($original, $this->postId);

        if ($args !== []) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers are ours; values are placeholders.
            $condition = $wpdb->prepare($condition, $args);
        }

        return ['join' => [], 'where' => [$condition]];
    }

    /**
     * Get the aliases only bridged clauses use, kept as empty stubs so an ORDER BY the rewrite misses stays valid SQL.
     *
     * @return list<string>
     */
    private function droppedAliases(): array
    {
        return array_keys(array_diff_key($this->bridgedJoins, $this->coreAliases));
    }

    /**
     * Get the JOINs bridged clauses created that an OR sibling core clause went on to share.
     *
     * @return list<string>
     */
    private function sharedJoins(): array
    {
        $joins = [];

        foreach ($this->bridgedJoins as $alias => $aliasJoins) {
            if (isset($this->coreAliases[$alias])) {
                array_push($joins, ...$aliasJoins);
            }
        }

        return $joins;
    }
}
