<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

class RadioField extends SelectField
{
    public static function type(): string
    {
        return 'radio';
    }
}
