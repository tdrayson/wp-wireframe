<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

class TextField extends BaseField
{
    public static function type(): string
    {
        return 'text';
    }

    public static function sanitize(mixed $value, array $args): mixed
    {
        if (!is_string($value)) {
            return '';
        }

        if (!empty($args['allow_html']) && current_user_can('unfiltered_html')) {
            return wp_kses_post($value);
        }

        return sanitize_text_field($value);
    }
}
