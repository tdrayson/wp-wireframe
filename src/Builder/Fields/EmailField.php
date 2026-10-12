<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

use Wireframe\Builder\Concerns\HasPlaceholder;
use Wireframe\Builder\Concerns\Copyable;

/**
 * Email input with format validation (`email`).
 */
final class EmailField extends Field
{
    use HasPlaceholder, Copyable;

    protected const TYPE = 'email';
}
