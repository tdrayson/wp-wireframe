<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

/**
 * Detached stand-in returned when the editor can't find a field, so chained
 * calls on it are harmless no-ops instead of fatals.
 *
 * @internal
 */
final class MissingField extends Field
{
    /**
     * Swallow any type-specific setter.
     *
     * @param string $name      Method name.
     * @param array  $arguments Method arguments.
     * @return static
     */
    public function __call(string $name, array $arguments): static
    {
        return $this;
    }
}
