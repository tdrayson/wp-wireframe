<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

class EmailField extends BaseField
{
    public static function type(): string
    {
        return 'email';
    }

    public static function defaultRules(array $args): string
    {
        return 'email';
    }

    public static function sanitize(mixed $value, array $args): mixed
    {
        return is_string($value) ? sanitize_email($value) : '';
    }
}
