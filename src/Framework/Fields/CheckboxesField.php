<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

use Wireframe\Framework\Populate;

/**
 * Multi-checkbox field — stores an array of selected option keys.
 *
 * Structural validation ensures every selected value exists
 * in the declared options list. Populated options must be resolved
 * into `args.options` first; see Populate.
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
            if (!self::isOption($selectedValue, $options)) {
                return sprintf('"%s" is not a valid option.', is_scalar($selectedValue) ? $selectedValue : gettype($selectedValue));
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

        return Populate::typed($args, array_values(
            array_filter($value, static fn (mixed $selectedValue): bool => self::isOption($selectedValue, $options))
        ));
    }

    /**
     * Whether a value is a key of the options map.
     */
    private static function isOption(mixed $value, array $options): bool
    {
        return (is_int($value) || is_string($value)) && array_key_exists($value, $options);
    }
}
