<?php

declare(strict_types=1);

namespace Wireframe\Builder\Concerns;

/**
 * `args.min` / `args.max` / `args.step` setters.
 */
trait HasNumericBounds
{
    /**
     * Set the lowest allowed value.
     *
     * @param int|float $min Minimum.
     * @return static
     */
    public function min(int|float $min): static
    {
        return $this->arg('min', $min);
    }

    /**
     * Set the highest allowed value.
     *
     * @param int|float $max Maximum.
     * @return static
     */
    public function max(int|float $max): static
    {
        return $this->arg('max', $max);
    }

    /**
     * Set the increment between values.
     *
     * @param int|float $step Step size.
     * @return static
     */
    public function step(int|float $step): static
    {
        return $this->arg('step', $step);
    }
}
