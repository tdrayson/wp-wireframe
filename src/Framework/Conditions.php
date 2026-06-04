<?php

declare(strict_types=1);

namespace Wireframe\Framework;

/**
 * Server-side condition evaluator.
 *
 * Mirrors the client-side conditions.js DSL so that hidden fields
 * can be skipped during validation and sanitization.
 */
final class Conditions
{
    /**
     * Evaluate a condition node (may be a combinator or single rule).
     *
     * @param array|null $condition The condition object.
     * @param array      $values    Current field values.
     * @return bool True if the condition evaluates to true, false otherwise.
     */
    public static function evaluate(array|null $condition, array $values): bool
    {
        if ($condition === null) {
            return true;
        }

        // AND combinator.
        if (isset($condition['all']) && is_array($condition['all'])) {
            foreach ($condition['all'] as $rule) {
                if (!self::evaluate($rule, $values)) {
                    return false;
                }
            }
            return true;
        }

        // OR combinator.
        if (isset($condition['any']) && is_array($condition['any'])) {
            foreach ($condition['any'] as $rule) {
                if (self::evaluate($rule, $values)) {
                    return true;
                }
            }
            return false;
        }

        // Single rule.
        if (isset($condition['field'], $condition['operator'])) {
            return self::evaluateRule($condition, $values);
        }

        return true;
    }

    /**
     * Evaluate a single condition rule.
     *
     * @param array $rule   The rule: { field, operator, value }.
     * @param array $values Current field values.
     * @return bool True if the rule evaluates to true, false otherwise.
     */
    private static function evaluateRule(array $rule, array $values): bool
    {
        $field    = $rule['field'];
        $operator = $rule['operator'];
        $expected = $rule['value'] ?? null;
        $actual   = $values[$field] ?? null;

        return match ($operator) {
            'equals'       => $actual === $expected,
            'not_equals'   => $actual !== $expected,
            'truthy'       => !empty($actual),
            'falsy'        => empty($actual),
            'in'           => is_array($expected) && in_array($actual, $expected, true),
            'not_in'       => is_array($expected) && !in_array($actual, $expected, true),
            'between'      => self::evaluateBetween($actual, $expected),
            'starts_with'  => is_string($actual) && str_starts_with($actual, (string) $expected),
            'ends_with'    => is_string($actual) && str_ends_with($actual, (string) $expected),
            'contains'     => self::evaluateContains($actual, $expected),
            'not_contains' => !self::evaluateContains($actual, $expected),
            'is_empty'     => self::isEmpty($actual),
            'is_not_empty' => !self::isEmpty($actual),
            'gt'           => (float) $actual > (float) $expected,
            'gte'          => (float) $actual >= (float) $expected,
            'lt'           => (float) $actual < (float) $expected,
            'lte'          => (float) $actual <= (float) $expected,
            default        => false,
        };
    }

    /**
     * Check if a value is between two values.
     *
     * @param mixed $actual   The value to check.
     * @param mixed $expected The value to check for.
     * @return bool True if the value is between the expected values, false otherwise.
     */
    private static function evaluateBetween(mixed $actual, mixed $expected): bool
    {
        if (!is_array($expected) || count($expected) < 2) {
            return false;
        }

        $num = (float) $actual;
        return $num >= (float) $expected[0] && $num <= (float) $expected[1];
    }

    /**
     * Check if a value contains another value.
     *
     * @param mixed $actual   The value to check.
     * @param mixed $expected The value to check for.
     * @return bool True if the value contains the expected value, false otherwise.
     */
    private static function evaluateContains(mixed $actual, mixed $expected): bool
    {
        if (is_string($actual)) {
            return str_contains($actual, (string) $expected);
        }

        if (is_array($actual)) {
            return in_array($expected, $actual, true);
        }

        return false;
    }

    /**
     * Check if a value is empty.
     *
     * @param mixed $value The value to check.
     * @return bool True if the value is empty, false otherwise.
     */
    private static function isEmpty(mixed $value): bool
    {
        return $value === '' || $value === null || (is_array($value) && empty($value));
    }

    /**
     * Build a visibility map for all fields given current values.
     *
     * @param array $flatFields Flat field map from ConfigLoader::flatFields().
     * @param array $values     Current field values.
     * @return array<string, bool> Field ID → visible.
     */
    public static function visibilityMap(array $flatFields, array $values): array
    {
        $map = [];

        foreach ($flatFields as $fieldId => $fieldConfig) {
            $conditions = $fieldConfig['conditions'] ?? null;
            $map[$fieldId] = self::evaluate($conditions, $values);
        }

        return $map;
    }
}
