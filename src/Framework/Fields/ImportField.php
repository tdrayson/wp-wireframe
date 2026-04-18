<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

class ImportField extends HtmlField
{
    public static function type(): string
    {
        return 'import';
    }
}
