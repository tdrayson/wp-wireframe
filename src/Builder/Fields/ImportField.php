<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

use Wireframe\Builder\Concerns\HasButtonLabel;

/**
 * Button that restores settings from uploaded JSON (`import`).
 */
final class ImportField extends Field
{
    use HasButtonLabel;

    protected const TYPE = 'import';
}
