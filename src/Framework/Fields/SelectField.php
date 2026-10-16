<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

use Wireframe\Framework\Populate;

/**
 * Select field — single dropdown or multi-select token input.
 *
 * When `args.multiple` is true, behaves like CheckboxesField
 * (stores an array, validates each value against options).
 * Populated options must be resolved into `args.options` first; see Populate.
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
                if (!self::isOption($selectedValue, $options)) {
                    return sprintf('"%s" is not a valid option.', is_scalar($selectedValue) ? $selectedValue : gettype($selectedValue));
                }
            }

            return null;
        }

        // Single select.
        if ($value !== null && $value !== '' && !self::isOption($value, $options)) {
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

            return Populate::typed($args, array_values(
                array_filter($value, static fn (mixed $selectedValue): bool => self::isOption($selectedValue, $options))
            ));
        }

        // Single select.
        if ($allowCustom) {
            return is_string($value) ? sanitize_text_field($value) : '';
        }

        return self::isOption($value, $options) ? Populate::typed($args, $value) : ($args['default'] ?? '');
    }

    /**
     * Whether a value is a key of the options map.
     *
     * @param mixed $value   Submitted value.
     * @param array $options Options map.
     * @return bool
     */
    protected static function isOption(mixed $value, array $options): bool
    {
        return (is_int($value) || is_string($value)) && array_key_exists($value, $options);
    }
}
