<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

class HiddenField extends BaseField
{
    public static function type(): string
    {
        return 'hidden';
    }

    public static function sanitize(mixed $value, array $args): mixed
    {
        return is_string($value) ? sanitize_text_field($value) : '';
    }
}
