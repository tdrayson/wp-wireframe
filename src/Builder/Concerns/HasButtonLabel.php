<?php

declare(strict_types=1);

namespace Wireframe\Builder\Concerns;

/**
 * `args.button_label` setter.
 */
trait HasButtonLabel
{
    /**
     * Set the button text.
     *
     * @param string $label Button label.
     * @return static
     */
    public function buttonLabel(string $label): static
    {
        return $this->arg('button_label', $label);
    }
}
