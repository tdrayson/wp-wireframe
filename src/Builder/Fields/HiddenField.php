<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

/**
 * Unrendered field that round-trips a value (`hidden`).
 */
final class HiddenField extends Field
{
    protected const TYPE = 'hidden';
}
