<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

class TextareaField extends BaseField
{
    public static function type(): string
    {
        return 'textarea';
    }

    public static function sanitize(mixed $value, array $args): mixed
    {
        return is_string($value) ? sanitize_textarea_field($value) : '';
    }
}
