<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

class ImageCheckboxesField extends CheckboxesField
{
    public static function type(): string
    {
        return 'image_checkboxes';
    }
}
