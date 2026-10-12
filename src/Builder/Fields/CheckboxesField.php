<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

use Wireframe\Builder\Concerns\HasOptions;

/**
 * Checkbox group storing an array of option keys (`checkboxes`).
 */
final class CheckboxesField extends Field
{
    use HasOptions;

    protected const TYPE = 'checkboxes';
}
