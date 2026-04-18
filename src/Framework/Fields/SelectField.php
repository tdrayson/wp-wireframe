<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

/**
 * Select field — single dropdown or multi-select token input.
 *
 * When `args.multiple` is true, behaves like CheckboxesField
 * (stores an array, validates each value against options).
 */
class SelectField extends BaseField
{
    public static function type(): string
    {
        return 'select';
    }

    /**
     * Validate the submitted value against declared options.
     *
     * @param mixed $value The submitted value (string or array).
     * @param array $args  Field arguments from config.
     * @return string|null Error message, or null if valid.
     */
    public static function validate(mixed $value, array $args): ?string
    {
        $options     = $args['options'] ?? [];
        $allowCustom = $args['allow_custom'] ?? false;
        $isMultiple  = !empty($args['multiple']);

        if ($allowCustom) {
            return null;
        }

        // Multi-select: validate each selected value.
        if ($isMultiple) {
            if (!is_array($value)) {
                return null;
            }

            foreach ($value as $selectedValue) {
                if (!array_key_exists($selectedValue, $options)) {
                    return sprintf('"%s" is not a valid option.', $selectedValue);
                }
            }

            return null;
        }

        // Single select.
        if ($value !== null && $value !== '' && !array_key_exists($value, $options)) {
            return 'Selected value is not allowed.';
        }

        return null;
    }

    /**
     * Sanitize the value for storage.
     *
     * @param mixed $value The submitted value.
     * @param array $args  Field arguments from config.
     * @return mixed Sanitized string (single) or array (multi).
     */
    public static function sanitize(mixed $value, array $args): mixed
    {
        $options     = $args['options'] ?? [];
        $allowCustom = $args['allow_custom'] ?? false;
        $isMultiple  = !empty($args['multiple']);

        // Multi-select: filter to valid options.
        if ($isMultiple) {
            if (!is_array($value)) {
                return $args['default'] ?? [];
            }

            if ($allowCustom) {
                return array_map('sanitize_text_field', $value);
            }

            return array_values(
                array_filter($value, fn(string $selectedValue) => array_key_exists($selectedValue, $options))
            );
        }

        // Single select.
        if ($allowCustom) {
            return is_string($value) ? sanitize_text_field($value) : '';
        }

        return array_key_exists($value, $options) ? $value : ($args['default'] ?? '');
    }
}
