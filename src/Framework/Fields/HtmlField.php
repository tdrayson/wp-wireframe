<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

class HtmlField extends BaseField
{
    public static function type(): string
    {
        return 'html';
    }

    public static function isStateless(): bool
    {
        return true;
    }

    public static function sanitize(mixed $value, array $args): mixed
    {
        return null;
    }
}
