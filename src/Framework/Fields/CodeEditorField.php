<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

class CodeEditorField extends BaseField
{
    public static function type(): string
    {
        return 'code_editor';
    }

    public static function sanitize(mixed $value, array $args): mixed
    {
        if (!is_string($value)) {
            return '';
        }

        // Only users with unfiltered_html capability can store raw code.
        if (current_user_can('unfiltered_html')) {
            return $value;
        }

        return wp_kses_post($value);
    }
}
