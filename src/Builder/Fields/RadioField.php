<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

use Wireframe\Builder\Concerns\HasOptions;

/**
 * Radio button group (`radio`).
 */
final class RadioField extends Field
{
    use HasOptions;

    protected const TYPE = 'radio';
}
