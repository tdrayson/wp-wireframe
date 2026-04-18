<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

class NumberField extends BaseField
{
    public static function type(): string
    {
        return 'number';
    }

    public static function defaultRules(array $args): string
    {
        $rules = ['numeric'];

        if (!empty($args['integer'])) {
            $rules[] = 'integer';
        }

        if (isset($args['min'], $args['max'])) {
            $rules[] = "between:{$args['min']},{$args['max']}";
        } elseif (isset($args['min'])) {
            $rules[] = "min:{$args['min']}";
        } elseif (isset($args['max'])) {
            $rules[] = "max:{$args['max']}";
        }

        return implode('|', $rules);
    }

    public static function sanitize(mixed $value, array $args): mixed
    {
        $integer = $args['integer'] ?? false;
        $value   = $integer ? (int) $value : (float) $value;

        if (isset($args['min'])) {
            $value = max($args['min'], $value);
        }
        if (isset($args['max'])) {
            $value = min($args['max'], $value);
        }

        return $value;
    }
}
