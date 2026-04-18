<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

class CheckboxField extends ToggleField
{
    public static function type(): string
    {
        return 'checkbox';
    }
}
