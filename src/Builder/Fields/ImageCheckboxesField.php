<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

use Wireframe\Builder\Concerns\HasOptions;

/**
 * Checkbox group rendered as image tiles (`image_checkboxes`).
 */
final class ImageCheckboxesField extends Field
{
    use HasOptions;

    protected const TYPE = 'image_checkboxes';
}
