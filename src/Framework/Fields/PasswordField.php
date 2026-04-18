<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

class PasswordField extends BaseField
{
    public static function type(): string
    {
        return 'password';
    }

    public static function sanitize(mixed $value, array $args): mixed
    {
        return is_string($value) ? sanitize_text_field($value) : '';
    }
}
