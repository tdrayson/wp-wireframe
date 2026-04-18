<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

class UrlField extends BaseField
{
    public static function type(): string
    {
        return 'url';
    }

    public static function defaultRules(array $args): string
    {
        return 'url';
    }

    public static function sanitize(mixed $value, array $args): mixed
    {
        return is_string($value) ? esc_url_raw($value) : '';
    }
}
