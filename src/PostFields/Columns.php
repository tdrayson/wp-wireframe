<?php

declare(strict_types=1);

namespace Wireframe\PostFields;

use WP_Query;
use Wireframe\Framework\ConfigLoader;
use Wireframe\Meta;

/**
 * Adds a post type's `column` fields to its admin list table, with per-type cells and sorting.
 */
final class Columns
{
    private const PREFIX = 'wireframe_';

    private const SORT_CLAUSE = 'wireframe_sort';

    /** @var array<string, true> Post types already hooked. */
    private static array $hooked = [];

    private static bool $sortHooked = false;

    /** @var array<string, list<array>> Column definitions by post type and user. */
    private static array $definitions = [];

    /** @var array<string, array> Location contexts by user and post. */
    private static array $contexts = [];

    /**
     * Hook the list table filters of every post type with field groups; safe to call again.
     */
    public static function register(): void
    {
        foreach (array_keys(FieldGroups::all()) as $postType) {
            if (isset(self::$hooked[$postType])) {
                continue;
            }

            self::$hooked[$postType] = true;

            add_filter("manage_{$postType}_posts_columns", static fn (array $columns): array => self::addColumns($postType, $columns));
            add_action("manage_{$postType}_posts_custom_column", static fn (string $column, int $postId) => self::renderCell($postType, $column, $postId), 10, 2);
            add_filter("manage_edit-{$postType}_sortable_columns", static fn (array $columns): array => self::addSortable($postType, $columns));
        }

        if (!self::$sortHooked) {
            self::$sortHooked = true;

            add_action('pre_get_posts', [self::class, 'sort']);
        }
    }

    /**
     * Forget the per-request column definitions and location contexts.
     */
    public static function reset(): void
    {
        self::$definitions = [];
        self::$contexts    = [];
    }

    /**
     * Sort the main admin list query by a field column.
     *
     * Posts without a value stay in the list; a number or range sorts numerically.
     *
     * @param WP_Query $query Query being prepared.
     */
    public static function sort(WP_Query $query): void
    {
        $orderby = $query->get('orderby');

        if (!is_string($orderby) || !str_starts_with($orderby, self::PREFIX) || !is_admin() || !$query->is_main_query()) {
            return;
        }

        $postType = $query->get('post_type');
        $column   = self::find(is_string($postType) && $postType !== '' ? $postType : 'post', $orderby);

        if ($column === null || !$column['sortable']) {
            return;
        }

        $order = strtoupper((string) $query->get('order')) === 'DESC' ? 'DESC' : 'ASC';
        $key   = $column['field']['id'];
        $has   = ['key' => $key, 'compare' => 'EXISTS'];

        // Core's NUMERIC casts to SIGNED, which drops the fraction.
        if (in_array($column['field']['type'] ?? '', ['number', 'range'], true)) {
            $has['type'] = 'DECIMAL(20,6)';
        }

        $existing = $query->get('meta_query');
        $query->set('meta_query', array_filter([
            'relation' => 'AND',
            is_array($existing) && $existing !== [] ? $existing : null,
            ['relation' => 'OR', self::SORT_CLAUSE => $has, ['key' => $key, 'compare' => 'NOT EXISTS']],
        ]));
        $query->set('orderby', [self::SORT_CLAUSE => $order, 'ID' => $order]);
    }

    /**
     * Insert the current user's field columns into a post type's columns, after `title` unless a column says otherwise.
     *
     * @param string               $postType Post type slug.
     * @param array<string,string> $columns  Column labels by id.
     * @return array<string,string>
     */
    private static function addColumns(string $postType, array $columns): array
    {
        $added    = array_filter(self::definitions($postType), static fn (array $column): bool => !isset($columns[$column['id']]));
        $children = [];

        foreach ($added as $column) {
            $children[$column['after']][] = $column;
        }

        $result = [];

        foreach ($columns as $id => $label) {
            $result[$id] = $label;
            $result      = self::withChildren($result, (string) $id, $children);
        }

        // A column whose anchor isn't on the screen goes last.
        foreach ($added as $column) {
            if (!isset($result[$column['id']])) {
                $result[$column['id']] = $column['label'];
                $result                = self::withChildren($result, $column['id'], $children);
            }
        }

        return $result;
    }

    /**
     * Append the columns that follow an id, each followed by its own.
     *
     * @param array<string,string>       $result   Columns so far.
     * @param array<string, list<array>> $children Columns by the id they follow.
     * @param string                     $anchor   Id just placed.
     * @return array<string,string>
     */
    private static function withChildren(array $result, string $anchor, array $children): array
    {
        foreach ($children[$anchor] ?? [] as $column) {
            if (isset($result[$column['id']])) {
                continue;
            }

            $result[$column['id']] = $column['label'];
            $result                = self::withChildren($result, $column['id'], $children);
        }

        return $result;
    }

    /**
     * Mark the sortable field columns as sortable.
     *
     * @param string $postType Post type slug.
     * @param array  $columns  Sortable columns by id.
     * @return array
     */
    private static function addSortable(string $postType, array $columns): array
    {
        foreach (self::definitions($postType) as $column) {
            if ($column['sortable']) {
                $columns[$column['id']] = [$column['id'], false];
            }
        }

        return $columns;
    }

    /**
     * Echo a field column's cell, blank when the field's group doesn't apply to the row.
     *
     * @param string $postType Post type slug.
     * @param string $columnId Column id.
     * @param int    $postId   Row's post.
     */
    private static function renderCell(string $postType, string $columnId, int $postId): void
    {
        $column = self::find($postType, $columnId);

        if ($column === null || !Location::matches($column['group'], self::context($postId))) {
            return;
        }

        $field  = $column['field'];
        $value  = Meta::get($postId, $field['id']);
        $html   = ColumnRenderer::render($field, $value, $postId);
        $prefix = $column['group']['prefix'];

        /**
         * Filter: a field column's cell HTML, for one post type and field.
         *
         * @param string $html   Escaped HTML.
         * @param mixed  $value  Field value.
         * @param int    $postId Row's post.
         * @param array  $field  Field config.
         */
        $html = (string) apply_filters("{$prefix}/column/{$postType}/{$field['id']}", $html, $value, $postId, $field);

        /** Filter: any field column's cell HTML, after the per-field filter; same arguments. */
        echo apply_filters("{$prefix}/column", $html, $value, $postId, $field);
    }

    /**
     * Get the current user's column on a post type by id.
     *
     * @return array|null Column definition.
     */
    private static function find(string $postType, string $columnId): ?array
    {
        foreach (self::definitions($postType) as $column) {
            if ($column['id'] === $columnId) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Get the location context of a row for the current user, built once per request.
     *
     * @return array<string, mixed>
     */
    private static function context(int $postId): array
    {
        $user = wp_get_current_user();
        $key  = "{$user->ID}:$postId";

        return self::$contexts[$key] ??= Location::context($postId, $user->exists() ? $user : null);
    }

    /**
     * Get the columns a post type shows the current user, from the fields they can view.
     *
     * @return list<array{id: string, label: string, after: string, sortable: bool, field: array, group: array}>
     */
    private static function definitions(string $postType): array
    {
        $user = wp_get_current_user();
        $key  = "$postType:{$user->ID}";

        return self::$definitions[$key] ??= self::build($postType, $user);
    }

    /**
     * Build a post type's column definitions for a user.
     *
     * @return list<array>
     */
    private static function build(string $postType, \WP_User $user): array
    {
        $columns = [];

        foreach (FieldGroups::groupsFor($postType) as $group) {
            $wanted = array_filter(ConfigLoader::flatFields($group['config']), static fn (array $field, int|string $id): bool => self::wants($field, (string) $id), ARRAY_FILTER_USE_BOTH);

            if ($wanted === []) {
                continue;
            }

            $viewable = MetaRegistrar::accessMap($postType, $group, $user)->viewableFieldIds();

            foreach ($wanted as $id => $field) {
                $options = self::options($field);

                if (in_array((string) $id, $viewable, true) && !isset($columns[self::PREFIX . $id])) {
                    $columns[self::PREFIX . $id] = [
                        'id'       => self::PREFIX . $id,
                        'label'    => $options['label'] ?? (string) ($field['label'] ?? $id),
                        'after'    => $options['after'] ?? 'title',
                        'sortable' => !empty($options['sortable']) && StorageKind::for($field) === StorageKind::SCALAR,
                        'field'    => $field,
                        'group'    => $group,
                    ];
                }
            }
        }

        return array_values($columns);
    }

    /**
     * Whether a top-level, stored field opts into a column.
     */
    private static function wants(array $field, string $id): bool
    {
        return !str_contains($id, '.')
            && !empty($field['column'])
            && StorageKind::for($field) !== StorageKind::NONE;
    }

    /**
     * Get a field's column options, `label`, `sortable` and `after`, from `true` or an array.
     *
     * @return array{label?: string, sortable?: bool, after?: string}
     */
    private static function options(array $field): array
    {
        $column = is_array($field['column']) ? $field['column'] : [];

        return array_filter([
            'label'    => is_string($column['label'] ?? null) && $column['label'] !== '' ? $column['label'] : null,
            'sortable' => !empty($column['sortable']),
            'after'    => is_string($column['after'] ?? null) && $column['after'] !== '' ? $column['after'] : null,
        ], static fn (mixed $option): bool => $option !== null);
    }
}
