<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

use Wireframe\Builder\Concerns\HasRows;

/**
 * TinyMCE WYSIWYG editor (`editor`).
 */
final class EditorField extends Field
{
    use HasRows;

    protected const TYPE = 'editor';
}
