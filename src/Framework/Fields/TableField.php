<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

/**
 * Table field — a paginated DataViews-powered table backed by PHP callbacks.
 *
 * Stateless: no value is ever persisted. Data is served on demand by a
 * consumer-supplied `data_callback`, and row actions dispatch to their
 * own `callback` entries.
 *
 * @see \Wireframe\Rest\TableController
 */
class TableField extends BaseField
{
    public static function type(): string
    {
        return 'table';
    }

    public static function isStateless(): bool
    {
        return true;
    }

    public static function sanitize(mixed $value, array $args): mixed
    {
        return null;
    }
}
