<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

/**
 * Base field with sensible defaults.
 * Extend and override only what your field type needs.
 */
abstract class BaseField implements FieldInterface
{
    public static function defaultRules(array $args): string
    {
        return '';
    }

    public static function sanitize(mixed $value, array $args): mixed
    {
        return is_string($value) ? sanitize_text_field($value) : '';
    }

    public static function validate(mixed $value, array $args): ?string
    {
        return null;
    }

    public static function isStateless(): bool
    {
        return false;
    }

    /**
     * Whether a value is a key of an options map.
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
