<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

/**
 * Multi-checkbox field — stores an array of selected option keys.
 *
 * Structural validation ensures every selected value exists
 * in the declared options list.
 */
class CheckboxesField extends BaseField
{
    public static function type(): string
    {
        return 'checkboxes';
    }

    /**
     * Validate that all selected values exist in the declared options.
     *
     * @param mixed $value The submitted value (expected array).
     * @param array $args  Field arguments from config.
     * @return string|null Error message, or null if valid.
     */
    public static function validate(mixed $value, array $args): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        $options = $args['options'] ?? [];

        foreach ($value as $selectedValue) {
            if (!array_key_exists($selectedValue, $options)) {
                return sprintf('"%s" is not a valid option.', $selectedValue);
            }
        }

        return null;
    }

    /**
     * Sanitize by filtering out any values not present in the declared options.
     *
     * @param mixed $value The submitted value.
     * @param array $args  Field arguments from config.
     * @return array Sanitized array of valid option keys.
     */
    public static function sanitize(mixed $value, array $args): mixed
    {
        if (!is_array($value)) {
            return $args['default'] ?? [];
        }

        $options = $args['options'] ?? [];

        return array_values(
            array_filter($value, fn(string $selectedValue) => array_key_exists($selectedValue, $options))
        );
    }
}
