<?php

declare(strict_types=1);

namespace Wireframe\Framework;

/**
 * Prepares a config array for transmission to the client.
 *
 * Server-only keys — PHP callables registered against table data sources,
 * action buttons, detail views, etc. — are stripped before the config is
 * handed to `wp_localize_script`. Closures already JSON-encode to `{}`, but
 * string callables (`'my_callback'`) and array callables (`['MyClass',
 * 'method']`) would otherwise serialize as-is and disclose internal class /
 * function names to anyone with access to the admin page source.
 *
 * The client never references these keys directly — actions, data fetches,
 * and detail-view renders are dispatched server-side by REST controllers
 * that re-read the same config from PHP — so stripping them is purely
 * defensive (info-disclosure hardening + smaller localized payload).
 */
final class ClientConfig
{
    /**
     * Field-arg keys that hold PHP callables and must never reach the client.
     */
    private const SERVER_ONLY_KEYS = [
        'callback',
        'data_callback',
        'fetch_callback',
        'render_callback',
    ];

    /**
     * Return a deep copy of the config with server-only keys removed.
     */
    public static function forClient(array $config): array
    {
        return self::walk($config);
    }

    /**
     * Recursively drop server-only keys from any nested array.
     */
    private static function walk(array $node): array
    {
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array($key, self::SERVER_ONLY_KEYS, true)) {
                unset($node[$key]);
                continue;
            }

            if (is_array($value)) {
                $node[$key] = self::walk($value);
            }
        }

        return $node;
    }
}
