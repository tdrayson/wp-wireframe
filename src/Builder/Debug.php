<?php

declare(strict_types=1);

namespace Wireframe\Builder;

/**
 * Developer warnings for builder and editor misuse.
 *
 * @internal
 */
final class Debug
{
    /**
     * Raise `_doing_it_wrong()` when `WP_DEBUG` is on; do nothing otherwise.
     *
     * @param string $method  Method the mistake was made in.
     * @param string $message What went wrong.
     */
    public static function warn(string $method, string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG && function_exists('_doing_it_wrong')) {
            _doing_it_wrong($method, $message, '');
        }
    }
}
