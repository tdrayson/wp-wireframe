<?php

declare(strict_types=1);

namespace Wireframe\Builder\Concerns;

/**
 * `args.placeholder` setter.
 */
trait HasPlaceholder
{
    /**
     * Set the placeholder shown while the input is empty.
     *
     * @param string $placeholder Placeholder text.
     * @return static
     */
    public function placeholder(string $placeholder): static
    {
        return $this->arg('placeholder', $placeholder);
    }
}
