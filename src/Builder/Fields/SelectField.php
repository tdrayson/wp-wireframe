<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

use Wireframe\Builder\Concerns\HasOptions;
use Wireframe\Builder\Concerns\HasPlaceholder;

/**
 * Dropdown, or a searchable token input when `multiple()` (`select`).
 */
final class SelectField extends Field
{
    use HasOptions, HasPlaceholder;

    protected const TYPE = 'select';

    /**
     * Allow several values; the field then stores an array.
     *
     * @param bool $multiple Whether multiple values can be chosen.
     * @return static
     */
    public function multiple(bool $multiple = true): static
    {
        return $this->arg('multiple', $multiple);
    }

    /**
     * Accept values that are not in `options`.
     *
     * @param bool $allow Whether free-form values are accepted.
     * @return static
     */
    public function allowCustom(bool $allow = true): static
    {
        return $this->arg('allow_custom', $allow);
    }

    /**
     * Cap the number of selections when `multiple()`.
     *
     * @param int $max Maximum number of values.
     * @return static
     */
    public function max(int $max): static
    {
        return $this->arg('max', $max);
    }
}
