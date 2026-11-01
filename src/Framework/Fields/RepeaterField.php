<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

use Wireframe\Framework\Conditions;
use Wireframe\Framework\Populate;

class RepeaterField extends BaseField
{
    public static function type(): string
    {
        return 'repeater';
    }

    public static function sanitize(mixed $value, array $args): mixed
    {
        if (!is_array($value)) {
            return [];
        }

        $registry  = FieldRegistry::instance();
        $maxRows   = $args['max_rows'] ?? 200;
        $rows      = array_slice(
            array_values(array_filter($value, 'is_array')),
            0,
            (int) $maxRows
        );
        $subfields = Populate::resolveSubfields($args['subfields'] ?? [], $rows);

        return array_map(function (array $row) use ($subfields, $registry): array {
            $cleanRow = [];

            foreach ($subfields as $subConfig) {
                // Hidden subfields aren't persisted — matches top-level Sanitizer
                // semantics where condition-hidden fields drop out of the payload.
                if (!Conditions::evaluate($subConfig['conditions'] ?? null, $row)) {
                    continue;
                }

                $subId      = $subConfig['id'] ?? '';
                $subType    = $subConfig['type'] ?? 'text';
                $subArgs    = $subConfig['args'] ?? [];
                $subDefault = $subConfig['default'] ?? null;
                $handler    = $registry->get($subType);

                $cleanRow[$subId] = $handler::sanitize($row[$subId] ?? $subDefault, $subArgs);
            }

            return $cleanRow;
        }, $rows);
    }
}
