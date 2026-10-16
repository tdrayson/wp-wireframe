<?php

declare(strict_types=1);

namespace Wireframe\Framework;

use Wireframe\Framework\Fields\FieldRegistry;

/**
 * Sanitizes a settings payload by delegating to per-type field handlers.
 */
final class Sanitizer
{
    /**
     * @param bool $includeStateless When true, stateless fields are sanitized
     *                               and included too. Save leaves this false so
     *                               stateless fields are never persisted; the
     *                               action path passes true so transient inputs
     *                               (e.g. `upload`) still reach the handler.
     * @param array<string, mixed> $stored Stored values by field id; a populated value stays valid while stored.
     */
    public static function sanitize(array $payload, array $fields, array $visibilityMap = [], bool $includeStateless = false, array $stored = []): array
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
            $default = $fieldConfig['default'] ?? null;
            $value   = $payload[$fieldId] ?? $default;

            if (!$includeStateless && $handler::isStateless()) {
                continue;
            }

            $clean[$fieldId] = $handler::sanitize($value, Populate::argsFor($fieldConfig, $value, $stored[$fieldId] ?? null));
        }

        return $clean;
    }
}
