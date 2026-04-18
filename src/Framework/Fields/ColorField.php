<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

class ColorField extends BaseField
{
    public static function type(): string
    {
        return 'color';
    }

    public static function defaultRules(array $args): string
    {
        return 'regex:/^#([0-9a-fA-F]{3}){1,2}$/';
    }

    public static function sanitize(mixed $value, array $args): mixed
    {
        if (!is_string($value)) {
            return '';
        }

        return preg_match('/^#([0-9a-fA-F]{3}){1,2}$/', $value) ? $value : '';
    }
}
