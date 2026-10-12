<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

use Wireframe\Builder\Concerns\HasPlaceholder;
use Wireframe\Builder\Concerns\Copyable;

/**
 * URL input with format validation (`url`).
 */
final class UrlField extends Field
{
    use HasPlaceholder, Copyable;

    protected const TYPE = 'url';
}
