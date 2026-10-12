<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

/**
 * Colour swatch and picker (`color`).
 */
final class ColorField extends Field
{
    protected const TYPE = 'color';

    /**
     * Allow an alpha channel.
     *
     * @param bool $enable Whether transparency can be set.
     * @return static
     */
    public function enableAlpha(bool $enable = true): static
    {
        return $this->arg('enableAlpha', $enable);
    }
}
