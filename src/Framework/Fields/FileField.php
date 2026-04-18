<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

class FileField extends BaseField
{
    public static function type(): string
    {
        return 'file';
    }

    public static function sanitize(mixed $value, array $args): mixed
    {
        if (is_numeric($value)) {
            return [(int) $value];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_map('absint', array_filter($value, 'is_numeric'));
    }
}
