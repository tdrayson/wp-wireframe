<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

use Wireframe\Builder\Concerns\HasRows;

/**
 * CodeMirror editor with syntax highlighting (`code_editor`).
 */
final class CodeEditorField extends Field
{
    use HasRows;

    protected const TYPE = 'code_editor';

    /**
     * Set the highlighting language.
     *
     * @param string $mode One of `css`, `js`, `html`, `php`, `json`, `xml`, `sql`.
     * @return static
     */
    public function mode(string $mode): static
    {
        return $this->arg('mode', $mode);
    }
}
