<?php

declare(strict_types=1);

namespace Wireframe\Builder\Concerns;

/**
 * `args.options` setter for choice fields.
 */
trait HasOptions
{
    /**
     * Set the choices.
     *
     * @param array<string, string|array{label: string, image?: string}> $options Stored value → label
     *        (image variants take `[label, image]` arrays).
     * @return static
     */
    public function options(array $options): static
    {
        return $this->arg('options', $options);
    }
}
