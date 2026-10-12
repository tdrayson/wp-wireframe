<?php

declare(strict_types=1);

namespace Wireframe\Builder\Concerns;

/**
 * `args.rows` setter.
 */
trait HasRows
{
    /**
     * Set the visible height in text rows.
     *
     * @param int $rows Number of rows.
     * @return static
     */
    public function rows(int $rows): static
    {
        return $this->arg('rows', $rows);
    }
}
