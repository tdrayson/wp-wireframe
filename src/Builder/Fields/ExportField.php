<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

use Wireframe\Builder\Concerns\HasButtonLabel;

/**
 * Button that downloads the settings as JSON (`export`).
 */
final class ExportField extends Field
{
    use HasButtonLabel;

    protected const TYPE = 'export';

    /**
     * Set the download filename, without extension.
     *
     * @param string $filename File name.
     * @return static
     */
    public function filename(string $filename): static
    {
        return $this->arg('filename', $filename);
    }
}
