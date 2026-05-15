<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

/**
 * Action field — a button that runs a PHP callback and renders the result.
 *
 * Stateless: no value is ever persisted. The button POSTs the in-flight
 * (sanitized) form values to the action REST route, which dispatches to
 * `args.callback($values, $request)`.
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
