<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

class ToggleField extends BaseField
{
    public static function type(): string
    {
        return 'toggle';
    }

    public static function sanitize(mixed $value, array $args): mixed
    {
        return (bool) $value;
    }
}
