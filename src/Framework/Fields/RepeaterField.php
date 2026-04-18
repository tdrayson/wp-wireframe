<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

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

        $subfields = $args['subfields'] ?? [];
        $registry  = FieldRegistry::instance();
        $maxRows   = $args['max_rows'] ?? 200;
        $rows      = array_slice(
            array_values(array_filter($value, 'is_array')),
            0,
            (int) $maxRows
        );

        return array_map(function (array $row) use ($subfields, $registry): array {
            $cleanRow = [];

            foreach ($subfields as $subConfig) {
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
