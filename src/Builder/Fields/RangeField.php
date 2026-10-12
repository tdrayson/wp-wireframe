<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

use Wireframe\Builder\Concerns\HasNumericBounds;

/**
 * Slider (`range`).
 */
final class RangeField extends Field
{
    use HasNumericBounds;

    protected const TYPE = 'range';

    /**
     * Set a unit shown after the value, e.g. `px`.
     *
     * @param string $suffix Suffix text.
     * @return static
     */
    public function suffix(string $suffix): static
    {
        return $this->arg('suffix', $suffix);
    }

    /**
     * Show a reset button that restores the default.
     *
     * @param bool $allow Whether the reset button is shown.
     * @return static
     */
    public function allowReset(bool $allow = true): static
    {
        return $this->arg('allowReset', $allow);
    }

    /**
     * Show tick marks along the track.
     *
     * @param array|bool $marks `true` for a mark per step, or explicit marks.
     * @return static
     */
    public function marks(array|bool $marks): static
    {
        return $this->arg('marks', $marks);
    }
}
