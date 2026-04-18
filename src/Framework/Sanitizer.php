<?php

declare(strict_types=1);

namespace Wireframe\Framework;

use Wireframe\Framework\Fields\FieldRegistry;

/**
 * Sanitizes a settings payload by delegating to per-type field handlers.
 */
final class Sanitizer
{
    public static function sanitize(array $payload, array $fields, array $visibilityMap = []): array
    {
        $registry = FieldRegistry::instance();
        $clean    = [];

        foreach ($fields as $fieldId => $fieldConfig) {
            if (str_contains($fieldId, '.')) {
                continue;
            }

            if (!empty($visibilityMap) && !($visibilityMap[$fieldId] ?? true)) {
                continue;
            }

            $type    = $fieldConfig['type'] ?? 'text';
            $handler = $registry->get($type);
            $args    = $fieldConfig['args'] ?? [];
            $default = $fieldConfig['default'] ?? null;
            $value   = $payload[$fieldId] ?? $default;

            if ($handler::isStateless()) {
                continue;
            }

            $clean[$fieldId] = $handler::sanitize($value, $args);
        }

        return $clean;
    }
}
