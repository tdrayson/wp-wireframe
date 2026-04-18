<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

class ImageRadioField extends SelectField
{
    public static function type(): string
    {
        return 'image_radio';
    }
}
