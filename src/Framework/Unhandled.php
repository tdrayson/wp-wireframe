<?php

declare(strict_types=1);

namespace Wireframe\Framework;

/**
 * Sentinel value used by dispatch filters to detect "no listener attached."
 *
 * Filters can't natively tell the difference between "nobody hooked in" and
 * "a listener handled this and returned null / false / []." Passing a private
 * sentinel as the default and identity-checking the result removes the
 * ambiguity, so handlers can return any value (including null) without being
 * mistaken for an unhandled filter.
 *
 * Usage:
 *   $result = apply_filters($hook, Unhandled::get(), $arg1, $arg2);
 *   if ($result instanceof Unhandled) {
 *       // nobody listened
 *   }
 */
final class Unhandled
{
    private static ?self $instance = null;

    public static function get(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }
}
