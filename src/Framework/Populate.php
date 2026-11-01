<?php

declare(strict_types=1);

namespace Wireframe\Framework;

use WP_Query;
use WP_Term_Query;
use WP_User_Query;

/**
 * Resolves `args.populate` option lists for select, checkboxes and radio fields from posts, users, terms or callbacks.
 */
final class Populate
{
    public const SEARCH_LIMIT = 20;

    private const TYPES = ['select', 'radio', 'checkboxes'];

    /** Internal `populate` key for a field's stored values, which stay valid once the query no longer returns them. */
    private const STORED = '_stored';

    /** Default `return_format` value and label properties, by query. */
    private const FORMATS = [
        'posts' => ['ID', 'post_title'],
        'users' => ['ID', 'display_name'],
        'terms' => ['term_id', 'name'],
    ];

    /** Object properties that hold integers, by query. */
    private const INTEGER_PROPERTIES = [
        'posts' => ['ID', 'post_parent', 'menu_order'],
        'users' => ['ID'],
        'terms' => ['term_id', 'term_taxonomy_id', 'parent', 'count', 'term_group'],
    ];

    /** Query args that limit results to given values, by query and value property. */
    private const LIMITERS = [
        'posts' => ['ID' => 'post__in', 'post_name' => 'post_name__in'],
        'users' => ['ID' => 'include', 'user_login' => 'login__in', 'user_nicename' => 'nicename__in'],
        'terms' => ['term_id' => 'include', 'term_taxonomy_id' => 'term_taxonomy_id', 'slug' => 'slug', 'name' => 'name'],
    ];

    /**
     * Merge a field's populated options into `args.options` by `position`.
     *
     * `$onlyValues` limits `query` lookups to those values with no limit; a `fetch` field given none looks nothing up.
     *
     * @param array      $field      Field config.
     * @param array|null $onlyValues Values to look up, such as stored or submitted ones; null for the full list.
     * @return array The field.
     */
    public static function resolve(array $field, ?array $onlyValues = null): array
    {
        if (!self::isPopulated($field)) {
            return $field;
        }

        if ($onlyValues === null && self::fetches($field)) {
            $onlyValues = [];
        }

        $only     = $onlyValues === null ? null : self::valuesOf($onlyValues);
        $populate = $field['args']['populate'];
        $static   = self::staticOptions($field);
        $found    = self::lookup($field, $only);
        $found   += self::kept($populate, $only, $static + $found);

        $field['args']['options'] = self::merge($populate, $static, $found);

        return $field;
    }

    /**
     * Resolve every populated field and repeater subfield of a config for the browser.
     *
     * Options become an ordered `[{value, label}]` list, and `populate` only `{fetch, field}`, `field` being the search path.
     *
     * @param array                $config       Normalised config.
     * @param array<string, mixed> $storedValues Stored values by field id; `fetch` fields look up only these.
     * @return array The config.
     */
    public static function resolveConfig(array $config, array $storedValues): array
    {
        foreach ($config['tabs'] ?? [] as $t => $tab) {
            foreach ($tab['sections'] ?? [] as $s => $section) {
                foreach ($section['fields'] ?? [] as $f => $field) {
                    $id = (string) ($field['id'] ?? '');
                    $config['tabs'][$t]['sections'][$s]['fields'][$f] = self::forBrowser($field, $storedValues[$id] ?? null, $id);
                }
            }
        }

        return $config;
    }

    /**
     * Search a populated field's options by label: static ones, then the query re-run with the search term.
     *
     * @param array  $field  Field config.
     * @param string $search Search term.
     * @param int    $limit  Most results to return, capped at 20.
     * @return list<array{value: int|string, label: string}>
     */
    public static function search(array $field, string $search, int $limit = self::SEARCH_LIMIT): array
    {
        if (!self::isPopulated($field)) {
            return [];
        }

        $limit  = max(1, min(self::SEARCH_LIMIT, $limit));
        $static = self::matching(self::staticOptions($field), $search);
        $found  = self::lookup($field, null, $search, $limit);
        $merged = array_slice(self::merge($field['args']['populate'], $static, $found), 0, $limit, true);

        return self::toList($merged, self::valueType($field['args']));
    }

    /**
     * Get a field's args with populated options resolved for the given value or values, stored ones staying valid.
     *
     * @param array $field  Field config.
     * @param mixed $value  Submitted value.
     * @param mixed $stored The field's stored value, or repeater rows; null when nothing is stored.
     * @return array Field args.
     */
    public static function argsFor(array $field, mixed $value, mixed $stored = null): array
    {
        $field = self::keepingStored($field, $stored);

        if (!self::isPopulated($field)) {
            return $field['args'] ?? [];
        }

        return self::resolve($field, self::valuesOf($value))['args'];
    }

    /**
     * Resolve the populated subfields of a repeater against the values in every row.
     *
     * @param array $subfields Subfield configs.
     * @param mixed $rows      Repeater rows.
     * @return array Subfield configs.
     */
    public static function resolveSubfields(array $subfields, mixed $rows): array
    {
        $rows = is_array($rows) ? array_filter($rows, 'is_array') : [];

        return array_map(
            static fn (mixed $sub): mixed => is_array($sub) ? self::resolve($sub, self::column($rows, (string) ($sub['id'] ?? ''))) : $sub,
            $subfields
        );
    }

    /**
     * Get the JSON Schema type a field's values are stored as: `integer` for integer properties with integer static keys.
     *
     * @param array $args Field args.
     * @return string `integer` or `string`.
     */
    public static function valueType(array $args): string
    {
        $populate = $args['populate'] ?? null;

        if (!is_array($populate) || ($populate['type'] ?? 'query') !== 'query') {
            return 'string';
        }

        $query = (string) ($populate['query'] ?? 'posts');

        if (!in_array(self::format($populate)[0], self::INTEGER_PROPERTIES[$query] ?? [], true)) {
            return 'string';
        }

        foreach (array_keys((array) ($args['options'] ?? [])) as $key) {
            if (!is_int($key)) {
                return 'string';
            }
        }

        return 'integer';
    }

    /**
     * Cast a value, or each value of a list, to the field's stored type.
     *
     * @param array $args  Field args.
     * @param mixed $value Sanitised value.
     * @return mixed
     */
    public static function typed(array $args, mixed $value): mixed
    {
        if (self::valueType($args) !== 'integer') {
            return $value;
        }

        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => is_numeric($item) ? (int) $item : $item, $value);
        }

        return is_numeric($value) ? (int) $value : $value;
    }

    /**
     * Whether a field is a select, checkboxes or radio with a `populate` definition.
     *
     * @param array $field Field config.
     * @return bool
     */
    public static function isPopulated(array $field): bool
    {
        return in_array($field['type'] ?? '', self::TYPES, true) && is_array($field['args']['populate'] ?? null);
    }

    /**
     * Whether a field searches as you type: a populated select with `fetch`.
     *
     * @param array $field Field config.
     * @return bool
     */
    public static function fetches(array $field): bool
    {
        return ($field['type'] ?? '') === 'select' && self::isPopulated($field) && !empty($field['args']['populate']['fetch']);
    }

    /**
     * Normalise a value, or a list of them, to its non-empty integer and string values.
     *
     * @param mixed $value Field value.
     * @return list<int|string>
     */
    public static function valuesOf(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter($values, static fn (mixed $item): bool => is_int($item) || (is_string($item) && $item !== '')));
    }

    /**
     * Resolve one field, or a repeater's subfields, for the browser.
     */
    private static function forBrowser(array $field, mixed $stored, string $path): array
    {
        if (($field['type'] ?? '') === 'repeater' && is_array($field['args']['subfields'] ?? null)) {
            $rows = is_array($stored) ? array_filter($stored, 'is_array') : [];

            foreach ($field['args']['subfields'] as $index => $sub) {
                $subId = (string) ($sub['id'] ?? '');
                $field['args']['subfields'][$index] = self::forBrowser($sub, self::column($rows, $subId), "{$path}.{$subId}");
            }

            return $field;
        }

        if (!self::isPopulated($field)) {
            return $field;
        }

        $field    = self::keepingStored($field, $stored);
        $fetch    = self::fetches($field);
        $resolved = self::resolve($field, $fetch ? self::valuesOf($stored) : null);

        $resolved['args']['options']  = self::toList($resolved['args']['options'], self::valueType($resolved['args']));
        $resolved['args']['populate'] = ['fetch' => $fetch, 'field' => $path];

        return $resolved;
    }

    /**
     * Mark a field's stored values, or each repeater subfield's across the stored rows, as valid options.
     */
    private static function keepingStored(array $field, mixed $stored): array
    {
        if (($field['type'] ?? '') === 'repeater' && is_array($field['args']['subfields'] ?? null)) {
            $rows = is_array($stored) ? array_filter($stored, 'is_array') : [];

            $field['args']['subfields'] = array_map(
                static fn (mixed $sub): mixed => is_array($sub) ? self::keepingStored($sub, self::column($rows, (string) ($sub['id'] ?? ''))) : $sub,
                $field['args']['subfields']
            );

            return $field;
        }

        if ($stored === null || !self::isPopulated($field)) {
            return $field;
        }

        $field['args']['populate'][self::STORED] = self::valuesOf($stored);

        return $field;
    }

    /**
     * Get the stored values, limited to `$only`, that aren't already options, each labelled by its value.
     *
     * @param list<int|string>|null     $only  Values being resolved; null for all.
     * @param array<int|string, string> $known Options found so far.
     * @return array<int|string, string>
     */
    private static function kept(array $populate, ?array $only, array $known): array
    {
        $stored = array_map('strval', (array) ($populate[self::STORED] ?? []));

        if ($only !== null) {
            $stored = array_intersect($stored, array_map('strval', $only));
        }

        $kept = [];

        foreach ($stored as $value) {
            if (!array_key_exists($value, $known)) {
                $kept[$value] = $value;
            }
        }

        return $kept;
    }

    /**
     * Look up a field's populated options: a callback's map, or a query limited to values or a search.
     *
     * @param list<int|string>|null $only Values to limit a query to.
     * @return array<int|string, string>
     */
    private static function lookup(array $field, ?array $only, ?string $search = null, int $limit = self::SEARCH_LIMIT): array
    {
        $populate = $field['args']['populate'];
        $type     = (string) ($populate['type'] ?? 'query');

        if ($type !== 'query') {
            $map = self::callbackOptions($field, $populate, $type);

            if ($only !== null) {
                return array_intersect_key($map, array_flip($only));
            }

            return $search === null ? $map : array_slice(self::matching($map, $search), 0, $limit, true);
        }

        if ($only === []) {
            return [];
        }

        return self::queryOptions($populate, $only, $search, $limit);
    }

    /**
     * Call a `function` populate's callable or apply a `custom` one's filter, keeping scalar labels.
     */
    private static function callbackOptions(array $field, array $populate, string $type): array
    {
        $custom = (string) ($populate['custom'] ?? '');

        $map = match (true) {
            $type === 'function' && is_callable($populate['function'] ?? null) => call_user_func($populate['function'], $field),
            $type === 'custom' && $custom !== ''                               => apply_filters("wp-wireframe/populate/{$custom}", [], $field),
            default                                                            => [],
        };

        return is_array($map) ? array_map('strval', array_filter($map, 'is_scalar')) : [];
    }

    /**
     * Run a populate query and map each object to `value => label` by `return_format`.
     */
    private static function queryOptions(array $populate, ?array $only, ?string $search, int $limit): array
    {
        $query = (string) ($populate['query'] ?? 'posts');

        [$valueKey, $labelKey] = self::format($populate);

        $args    = self::queryArgs($query, (array) ($populate['arguments'] ?? []), $valueKey, $only, $search, $limit);
        $options = [];

        foreach (self::run($query, $args) as $object) {
            $value = is_object($object) ? ($object->{$valueKey} ?? null) : null;
            $label = is_object($object) ? ($object->{$labelKey} ?? null) : null;

            if ((is_int($value) || is_string($value)) && is_scalar($label)) {
                $options[$value] = (string) $label;
            }
        }

        return $only === null ? $options : array_intersect_key($options, array_flip($only));
    }

    /**
     * Build the query args: the developer's over the defaults, then any value limit or search, then the fields we read.
     */
    private static function queryArgs(string $query, array $arguments, string $valueKey, ?array $only, ?string $search, int $limit): array
    {
        $args = $arguments + self::defaults($query, $arguments);

        if ($only !== null) {
            $args = array_replace($args, self::unlimited($query), self::limiter($query, $valueKey, $only, $arguments));
        }

        if ($search !== null) {
            $args = array_replace($args, self::searching($query, $search, $limit));
        }

        return array_replace($args, match ($query) {
            'posts' => ['fields' => '', 'no_found_rows' => true, 'ignore_sticky_posts' => true],
            'users' => ['fields' => 'all', 'count_total' => false],
            default => ['fields' => 'all'],
        });
    }

    /**
     * Defaults a picker wants: published posts by title, all of them; users by name; empty terms too.
     */
    private static function defaults(string $query, array $arguments): array
    {
        return match ($query) {
            'posts' => [
                'post_type'      => 'post',
                'post_status'    => ($arguments['post_type'] ?? '') === 'attachment' ? 'inherit' : 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ],
            'users' => ['orderby' => 'display_name'],
            'terms' => ['hide_empty' => false],
            default => [],
        };
    }

    /**
     * Args that lift a query's limit and offset.
     */
    private static function unlimited(string $query): array
    {
        return match ($query) {
            'posts' => ['posts_per_page' => -1, 'offset' => 0, 'paged' => 1],
            'users' => ['number' => -1, 'offset' => 0, 'paged' => 1],
            default => ['number' => 0, 'offset' => 0],
        };
    }

    /**
     * The arg limiting a query to the given values, unless the developer set it; results are intersected either way.
     */
    private static function limiter(string $query, string $valueKey, array $only, array $arguments): array
    {
        $key = self::LIMITERS[$query][$valueKey] ?? null;

        if ($key === null || array_key_exists($key, $arguments)) {
            return [];
        }

        $integer = in_array($valueKey, self::INTEGER_PROPERTIES[$query] ?? [], true);

        return [$key => $integer ? array_map('intval', $only) : array_map('strval', $only)];
    }

    /**
     * Args searching a query for a term, limited.
     */
    private static function searching(string $query, string $search, int $limit): array
    {
        $search = str_replace('*', '', $search);

        $args = match ($query) {
            'posts' => ['posts_per_page' => $limit, 'offset' => 0, 'paged' => 1],
            default => ['number' => $limit, 'offset' => 0],
        };

        if ($search === '') {
            return $args;
        }

        return $args + match ($query) {
            'posts' => ['s' => $search, 'search_columns' => ['post_title']],
            'users' => ['search' => "*{$search}*", 'search_columns' => ['display_name', 'user_nicename']],
            default => ['search' => $search],
        };
    }

    /**
     * Run a posts, users or terms query.
     *
     * @return array<object>
     */
    private static function run(string $query, array $args): array
    {
        $results = match ($query) {
            'posts' => (new WP_Query($args))->posts,
            'users' => (new WP_User_Query($args))->get_results(),
            'terms' => (new WP_Term_Query($args))->get_terms(),
            default => [],
        };

        return is_array($results) ? $results : [];
    }

    /**
     * Get the value and label properties of a query populate.
     *
     * @return array{0: string, 1: string}
     */
    private static function format(array $populate): array
    {
        [$value, $label] = self::FORMATS[(string) ($populate['query'] ?? 'posts')] ?? self::FORMATS['posts'];
        $format = (array) ($populate['return_format'] ?? []);

        return [(string) ($format['value'] ?? $value), (string) ($format['label'] ?? $label)];
    }

    /**
     * Merge static and populated options: populated after the static ones, or before them for `position => before`.
     */
    private static function merge(array $populate, array $static, array $found): array
    {
        return ($populate['position'] ?? 'after') === 'before' ? $found + $static : $static + $found;
    }

    /**
     * Get a field's static `args.options`.
     */
    private static function staticOptions(array $field): array
    {
        return is_array($field['args']['options'] ?? null) ? $field['args']['options'] : [];
    }

    /**
     * Keep the options whose label contains the search term.
     */
    private static function matching(array $options, string $search): array
    {
        if ($search === '') {
            return $options;
        }

        return array_filter($options, static fn (mixed $label): bool => is_scalar($label) && mb_stripos((string) $label, $search) !== false);
    }

    /**
     * Collect one subfield's values across repeater rows.
     *
     * @return list<int|string>
     */
    private static function column(array $rows, string $id): array
    {
        $values = [];

        foreach ($rows as $row) {
            $values = [...$values, ...self::valuesOf($row[$id] ?? null)];
        }

        return array_values(array_unique($values));
    }

    /**
     * Turn a `value => label` map into an ordered list, values cast to the stored type.
     *
     * @return list<array{value: int|string, label: mixed}>
     */
    private static function toList(array $options, string $type): array
    {
        $list = [];

        foreach ($options as $value => $label) {
            $list[] = ['value' => $type === 'integer' ? (int) $value : (string) $value, 'label' => $label];
        }

        return $list;
    }
}
