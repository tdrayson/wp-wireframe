<?php

declare(strict_types=1);

namespace Wireframe\PostFields;

use WP_User;
use Wireframe\Framework\Conditions;

/**
 * Field group location rules: the conditions DSL evaluated against the current post and user.
 */
final class Location
{
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

        return self::isValidNode($location) ? $location : null;
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
     * Build the values location rules are evaluated against.
     *
     * @param int          $postId Post being edited or read.
     * @param WP_User|null $user   Current user, or null when logged out.
     * @return array<string, mixed>
     */
    public static function context(int $postId, ?WP_User $user): array
    {
        $context = [
            'post_type' => (string) get_post_type($postId),
            'post_id'   => $postId,
            'user_role' => $user === null ? [] : array_values($user->roles),
        ];

        return (array) apply_filters('wp-wireframe/location_context', $context, $postId, $user);
    }

    /**
     * Whether a group's location matches a post and user.
     *
     * @param array        $group  Normalised group, or any array with a `location` rule tree.
     * @param int          $postId Post ID.
     * @param WP_User|null $user   Current user, or null when logged out.
     * @return bool
     */
    public static function applies(array $group, int $postId, ?WP_User $user): bool
    {
        $context = self::context($postId, $user);

        return Conditions::evaluate(self::expandListMembership($group['location'] ?? [], $context), $context);
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

        $children = $node['all'] ?? $node['any'] ?? null;

        if ($children !== null) {
            return is_array($children) && array_is_list($children) && array_filter($children, [self::class, 'isValidNode']) === $children;
        }

        return is_string($node['field'] ?? null) && is_string($node['operator'] ?? null);
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
     * Rewrite `in`/`not_in` on list-valued params (user roles) as any/none of `contains`.
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
