<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

class DateField extends BaseField
{
    public static function type(): string
    {
        return 'date';
    }

    public static function defaultRules(array $args): string
    {
        return 'regex:/^\d{4}-\d{2}-\d{2}$/';
    }

    public static function sanitize(mixed $value, array $args): mixed
    {
        return is_string($value) ? sanitize_text_field($value) : '';
    }
}
