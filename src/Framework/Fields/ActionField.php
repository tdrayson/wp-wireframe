<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

/**
 * Action field — one or more buttons that dispatch to a server-side filter.
 *
 * Stateless: no value is persisted. Configuration is pure data (no PHP
 * callables); each button POSTs the in-flight form values to its action
 * route, which fires the named filter:
 *
 *   apply_filters(
 *       "{$prefix}/action/{$pageId}/{$fieldId}/{$actionId}",
 *       Unhandled::get(),
 *       $values,
 *       $request
 *   )
 *
 * @see \Wireframe\Rest\ActionController
 */
class ActionField extends BaseField
{
    public static function type(): string
    {
        return 'action';
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
