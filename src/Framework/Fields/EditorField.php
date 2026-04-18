<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

class EditorField extends BaseField
{
    public static function type(): string
    {
        return 'editor';
    }

    public static function sanitize(mixed $value, array $args): mixed
    {
        return is_string($value) ? wp_kses_post($value) : '';
    }
}
