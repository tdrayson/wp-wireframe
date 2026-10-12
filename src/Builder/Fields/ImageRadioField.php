<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

use Wireframe\Builder\Concerns\HasOptions;

/**
 * Radio group rendered as image tiles (`image_radio`).
 */
final class ImageRadioField extends Field
{
    use HasOptions;

    protected const TYPE = 'image_radio';
}
