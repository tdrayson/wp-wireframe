<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

class ExportField extends HtmlField
{
    public static function type(): string
    {
        return 'export';
    }
}
