<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

use Wireframe\Builder\Concerns\HasPlaceholder;
use Wireframe\Builder\Concerns\HasRows;
use Wireframe\Builder\Concerns\Copyable;

/**
 * Multi-line text input (`textarea`).
 */
final class TextareaField extends Field
{
    use HasPlaceholder, HasRows, Copyable;

    protected const TYPE = 'textarea';
}
