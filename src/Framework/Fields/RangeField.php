<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

class RangeField extends NumberField
{
    public static function type(): string
    {
        return 'range';
    }
}
