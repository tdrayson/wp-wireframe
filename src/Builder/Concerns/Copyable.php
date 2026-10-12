<?php

declare(strict_types=1);

namespace Wireframe\Builder\Concerns;

/**
 * `args.copyable` setter.
 */
trait Copyable
{
    /**
     * Show a copy-to-clipboard button beside the input.
     *
     * @param bool $copyable Whether the copy button is shown.
     * @return static
     */
    public function copyable(bool $copyable = true): static
    {
        return $this->arg('copyable', $copyable);
    }
}
