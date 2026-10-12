<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

use Wireframe\Builder\Concerns\HasNumericBounds;
use Wireframe\Builder\Concerns\HasPlaceholder;
use Wireframe\Builder\Concerns\Copyable;

/**
 * Numeric input (`number`).
 */
final class NumberField extends Field
{
    use HasNumericBounds, HasPlaceholder, Copyable;

    protected const TYPE = 'number';

    /**
     * Store whole numbers only.
     *
     * @param bool $integer Whether the value is cast to int.
     * @return static
     */
    public function integer(bool $integer = true): static
    {
        return $this->arg('integer', $integer);
    }
}
