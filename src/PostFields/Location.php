<?php

declare(strict_types=1);

namespace Wireframe\PostFields;

use WP_Post;
use WP_User;
use Wireframe\Framework\Conditions;

/**
 * Field group location rules: the conditions DSL evaluated against the current post and user.
 */
final class Location
{
    /** Params fixed for the duration of a request. */
    public const STATIC_PARAMS = ['post_type', 'post_id', 'user_role'];

    /** Params that change while a post is edited, re-evaluated live in the block editor. */
    public const LIVE_PARAMS = ['post_status', 'post_template', 'post_terms', 'post_parent', 'post_format'];

    /** Params whose rule values are cast to int. */
    private const INT_PARAMS = ['post_id', 'post_parent', 'post_terms'];

    /** Live values of a post not yet created. */
    private const NEW_POST = ['post_status' => 'draft', 'post_template' => '', 'post_terms' => [], 'post_parent' => 0, 'post_format' => 'standard'];

    /**
     * Expand the shorthand and check the rule tree's shape.
     *
     * @param mixed $location A post type, a list of post types, or a conditions tree.
     * @return array|null The rule tree, or null when invalid.
     */
    public static function normalize(mixed $location): ?array
    {
        if (is_string($location)) {
            $location = [$location];
        }

        if (!is_array($location) || $location === []) {
            return null;
        }

        if (array_is_list($location)) {
            return self::isPostTypeList($location)
                ? ['all' => [['field' => 'post_type', 'operator' => 'in', 'value' => $location]]]
                : null;
        }

        return self::isValidNode($location) ? self::castInts($location) : null;
    }

    /**
     * Get every post type a location can match.
     *
     * @param array $location Normalised rule tree.
     * @return list<string>|null Null when a branch doesn't pin `post_type` with `equals` or `in`.
     */
    public static function postTypes(array $location): ?array
    {
        $postTypes = self::nodePostTypes($location);

        return $postTypes === [] ? null : $postTypes;
    }

    /**
     * Build the values location rules are evaluated against, live params from the stored post unless overridden.
     *
     * @param int                  $postId   Post being edited or read; 0 for one not yet created.
     * @param WP_User|null         $user     Current user, or null when logged out.
     * @param string|null          $postType Post type, for a post not yet created; defaults to the post's.
     * @param array<string, mixed> $live     Live param values replacing the stored ones, such as a save's submitted state.
     * @return array<string, mixed>
     */
    public static function context(int $postId, ?WP_User $user, ?string $postType = null, array $live = []): array
    {
        $postType ??= (string) get_post_type($postId);

        $context = [
            'post_type' => $postType,
            'post_id'   => $postId,
            'user_role' => $user === null ? [] : array_values($user->roles),
            ...array_replace(self::storedLive($postId, $postType), $live),
        ];

        return (array) apply_filters('wp-wireframe/location_context', $context, $postId, $user, $postType);
    }

    /**
     * Whether a group's location matches a post and user.
     *
     * @param array        $group    Normalised group, or any array with a `location` rule tree.
     * @param int          $postId   Post ID; 0 for one not yet created.
     * @param WP_User|null $user     Current user, or null when logged out.
     * @param string|null  $postType Post type, for a post not yet created; defaults to the post's.
     * @return bool
     */
    public static function applies(array $group, int $postId, ?WP_User $user, ?string $postType = null): bool
    {
        return self::matches($group, self::context($postId, $user, $postType));
    }

    /**
     * Whether a group's location matches a context built by context().
     *
     * @param array                $group   Normalised group, or any array with a `location` rule tree.
     * @param array<string, mixed> $context Location context.
     * @return bool
     */
    public static function matches(array $group, array $context): bool
    {
        return Conditions::evaluate(self::expandListMembership($group['location'] ?? [], $context), $context);
    }

    /**
     * Whether a group's location can match a post and user in some live state: live rules count as met.
     *
     * @param array        $group  Normalised group, or any array with a `location` rule tree.
     * @param int          $postId Post ID.
     * @param WP_User|null $user   Current user, or null when logged out.
     * @return bool
     */
    public static function staticMatch(array $group, int $postId, ?WP_User $user): bool
    {
        $location = self::withoutLiveRules($group['location'] ?? []);

        return self::matches(['location' => $location], self::context($postId, $user));
    }

    /**
     * Get the term IDs assigned to a post across its post type's taxonomies, post formats aside.
     *
     * @param int                  $postId   Post ID; 0 for one not yet created.
     * @param string               $postType Post type slug.
     * @param array<string, int[]> $replace  Term IDs by taxonomy, used instead of the stored ones.
     * @return list<int>
     */
    public static function terms(int $postId, string $postType, array $replace = []): array
    {
        $ids = [];

        foreach (get_object_taxonomies($postType) as $taxonomy) {
            if ($taxonomy === 'post_format') {
                continue;
            }

            $ids = [...$ids, ...($replace[$taxonomy] ?? self::storedTerms($postId, $taxonomy))];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Get the REST bases of a post type's REST-visible taxonomies, post formats aside.
     *
     * @param string $postType Post type slug.
     * @return array<string, string> REST base by taxonomy slug.
     */
    public static function restTaxonomies(string $postType): array
    {
        $bases = [];

        foreach (get_object_taxonomies($postType, 'objects') as $taxonomy) {
            if ($taxonomy->show_in_rest && $taxonomy->name !== 'post_format') {
                $bases[$taxonomy->name] = $taxonomy->rest_base ?: $taxonomy->name;
            }
        }

        return $bases;
    }

    /**
     * Get a post's stored live param values.
     */
    private static function storedLive(int $postId, string $postType): array
    {
        $post = $postId > 0 ? get_post($postId) : null;

        if (!$post instanceof WP_Post) {
            return self::NEW_POST;
        }

        return [
            'post_status'   => $post->post_status,
            'post_template' => (string) get_page_template_slug($post),
            'post_terms'    => self::terms($post->ID, $postType),
            'post_parent'   => (int) $post->post_parent,
            'post_format'   => get_post_format($post) ?: 'standard',
        ];
    }

    /**
     * Get the IDs of a post's stored terms in one taxonomy.
     *
     * @return list<int>
     */
    private static function storedTerms(int $postId, string $taxonomy): array
    {
        $terms = $postId > 0 ? get_the_terms($postId, $taxonomy) : false;

        return is_array($terms) ? array_map(static fn (object $term): int => (int) $term->term_id, $terms) : [];
    }

    /**
     * Replace every rule on a live param with an always-true node.
     */
    private static function withoutLiveRules(array $node): array
    {
        foreach (['all', 'any'] as $combinator) {
            if (isset($node[$combinator]) && is_array($node[$combinator])) {
                return [$combinator => array_map([self::class, 'withoutLiveRules'], $node[$combinator])];
            }
        }

        return in_array($node['field'] ?? null, self::LIVE_PARAMS, true) ? ['all' => []] : $node;
    }

    /**
     * Whether a list is non-empty strings only.
     */
    private static function isPostTypeList(array $list): bool
    {
        foreach ($list as $postType) {
            if (!is_string($postType) || $postType === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a node is a combinator of valid nodes or a `{field, operator}` rule.
     */
    private static function isValidNode(mixed $node): bool
    {
        if (!is_array($node)) {
            return false;
        }

        $combinators = array_intersect_key($node, ['all' => true, 'any' => true]);

        if (count($combinators) > 1 || ($combinators !== [] && isset($node['field']))) {
            return false;
        }

        $children = reset($combinators);

        if ($children !== false) {
            return is_array($children) && array_is_list($children) && array_filter($children, [self::class, 'isValidNode']) === $children;
        }

        return is_string($node['field'] ?? null) && is_string($node['operator'] ?? null);
    }

    /**
     * Cast the rule values of int params, since Conditions compares them strictly against the int context values.
     */
    private static function castInts(array $node): array
    {
        foreach (['all', 'any'] as $combinator) {
            if (isset($node[$combinator])) {
                $node[$combinator] = array_map([self::class, 'castInts'], $node[$combinator]);

                return $node;
            }
        }

        if (!in_array($node['field'], self::INT_PARAMS, true) || !array_key_exists('value', $node)) {
            return $node;
        }

        $node['value'] = is_array($node['value']) ? array_map('intval', $node['value']) : (int) $node['value'];

        return $node;
    }

    /**
     * Get the post types a node can match, or null when unbounded.
     *
     * @return list<string>|null
     */
    private static function nodePostTypes(array $node): ?array
    {
        if (isset($node['any'])) {
            $union = [];

            foreach ($node['any'] as $child) {
                $postTypes = self::nodePostTypes($child);

                if ($postTypes === null) {
                    return null;
                }

                $union = [...$union, ...$postTypes];
            }

            return array_values(array_unique($union));
        }

        if (isset($node['all'])) {
            $bounded = array_filter(array_map([self::class, 'nodePostTypes'], $node['all']), 'is_array');

            return $bounded === [] ? null : array_values(array_intersect(...array_values($bounded)));
        }

        return self::rulePostTypes($node);
    }

    /**
     * Get the post types a single rule pins, or null when it pins none.
     *
     * @return list<string>|null
     */
    private static function rulePostTypes(array $rule): ?array
    {
        if (($rule['field'] ?? null) !== 'post_type') {
            return null;
        }

        $value = $rule['value'] ?? null;

        return match ($rule['operator'] ?? null) {
            'equals' => is_string($value) && $value !== '' ? [$value] : null,
            'in'     => is_array($value) && self::isPostTypeList($value) ? array_values(array_unique($value)) : null,
            default  => null,
        };
    }

    /**
     * Rewrite `in`/`not_in` on list-valued params (user roles, terms) as any/none of `contains`.
     *
     * Conditions compares `in` against a scalar, so a role list would never match.
     */
    private static function expandListMembership(array $node, array $context): array
    {
        foreach (['all', 'any'] as $combinator) {
            if (isset($node[$combinator]) && is_array($node[$combinator])) {
                return [$combinator => array_map(static fn (array $child): array => self::expandListMembership($child, $context), $node[$combinator])];
            }
        }

        $operator = $node['operator'] ?? null;

        if (!in_array($operator, ['in', 'not_in'], true) || !is_array($context[$node['field'] ?? ''] ?? null) || !is_array($node['value'] ?? null)) {
            return $node;
        }

        $rules = array_map(static fn (mixed $value): array => [
            'field'    => $node['field'],
            'operator' => $operator === 'in' ? 'contains' : 'not_contains',
            'value'    => $value,
        ], array_values($node['value']));

        return [$operator === 'in' ? 'any' : 'all' => $rules];
    }
}
