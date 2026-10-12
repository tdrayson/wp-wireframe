<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

use Wireframe\Builder\Concerns\HasPlaceholder;
use Wireframe\Builder\Concerns\Copyable;

/**
 * Masked text input (`password`).
 */
final class PasswordField extends Field
{
    use HasPlaceholder, Copyable;

    protected const TYPE = 'password';
}
