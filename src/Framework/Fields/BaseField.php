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
}
