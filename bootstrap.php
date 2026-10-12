<?php

/**
 * WP Wireframe bootstrap.
 *
 * Every bundled copy of the framework includes this file. Each copy registers
 * itself, and framework classes load from the newest version registered.
 */

declare(strict_types=1);

namespace Wireframe;

use Closure;

// Declared here rather than in src/ so Strauss prefixes it along with the namespace.
if (! class_exists(Versions::class, false)) {
    /**
     * Registry of bundled WP Wireframe copies, plus the one autoloader they share.
     *
     * Whichever copy loads first defines this class for every other copy, so its
     * public API must never change.
     */
    final class Versions
    {
        /** @var list<array{version: string, dir: string}> Registered copies, in registration order. */
        private static array $copies = [];

        /** @var array{version: string, dir: string}|null Copy chosen at `plugins_loaded`. */
        private static ?array $locked = null;

        private static ?Closure $loader = null;

        private static bool $hooked = false;

        private static bool $locking = false;

        private static bool $warned = false;

        /**
         * Register a bundled copy of the framework.
         *
         * @param string $version Version of the copy, e.g. `1.1.0`.
         * @param string $dir     Absolute path to the copy's root, the directory holding bootstrap.php.
         */
        public static function register(string $version, string $dir): void
        {
            $dir = rtrim($dir, '/\\');
            foreach (self::$copies as $copy) {
                if ($copy['dir'] === $dir) {
                    return;
                }
            }
            self::$copies[] = ['version' => $version, 'dir' => $dir];

            // Re-prepend so pre-registry copies, which ship their own Composer
            // autoloader, can't serve framework classes ahead of this one.
            if (! self::$locking) {
                self::prependLoader();
            }

            if (! self::$hooked && function_exists('add_action')) {
                add_action('plugins_loaded', static function (): void {
                    self::lock();
                    self::prependLoader();
                }, PHP_INT_MIN);
                self::$hooked = true;
            }
        }

        /**
         * Every registered copy, in registration order.
         *
         * @return list<array{version: string, dir: string}>
         */
        public static function registered(): array
        {
            return self::$copies;
        }

        /**
         * The copy framework classes load from: the one locked at `plugins_loaded`,
         * or the newest registered so far.
         *
         * @return array{version: string, dir: string}|null
         */
        public static function active(): ?array
        {
            return self::$locked ?? self::newest();
        }

        private static function lock(): void
        {
            if (self::$locked !== null || self::$locking) {
                return;
            }

            self::$locking = true;
            self::discoverComposerCopies();
            self::$locked = self::newest();
            self::$locking = false;
        }

        private static function autoload(string $class): void
        {
            if (str_starts_with($class, __NAMESPACE__ . '\\')) {
                $relative = substr($class, strlen(__NAMESPACE__) + 1);
                $roots = ['/src/'];
            } elseif (str_starts_with($class, 'Rakit\\Validation\\')) {
                $relative = substr($class, strlen('Rakit\\Validation\\'));
                // Nested vendor/ in the release zip; sibling package in a Composer install.
                $roots = ['/vendor/magewirephp/validation/src/', '/../../magewirephp/validation/src/'];
            } else {
                return;
            }

            if (self::$locked === null && self::pluginsLoaded()) {
                self::lock();
            }

            $copy = self::active();
            if ($copy === null) {
                return;
            }

            if (self::$locked === null) {
                self::warnEarly($class);
            }

            foreach ($roots as $root) {
                $file = $copy['dir'] . $root . str_replace('\\', '/', $relative) . '.php';
                if (is_file($file)) {
                    require $file;
                    return;
                }
            }
        }

        /**
         * Composer runs a package's `autoload.files` once per request, keyed by package
         * name, so only the first Composer-installed copy registers itself. Find the rest.
         */
        private static function discoverComposerCopies(): void
        {
            $composer = 'Composer\\Autoload\\ClassLoader';
            if (! class_exists($composer, false) || ! method_exists($composer, 'getRegisteredLoaders')) {
                return;
            }

            foreach (array_keys($composer::getRegisteredLoaders()) as $vendorDir) {
                $bootstrap = $vendorDir . '/tdrayson/wp-wireframe/bootstrap.php';
                if (is_file($bootstrap)) {
                    require_once $bootstrap;
                }
            }
        }

        /**
         * @return array{version: string, dir: string}|null
         */
        private static function newest(): ?array
        {
            $newest = null;
            foreach (self::$copies as $copy) {
                if ($newest === null || version_compare($copy['version'], $newest['version'], '>')) {
                    $newest = $copy;
                }
            }

            return $newest;
        }

        private static function prependLoader(): void
        {
            self::$loader ??= static fn (string $class) => self::autoload($class);

            spl_autoload_unregister(self::$loader);
            spl_autoload_register(self::$loader, true, true);
        }

        private static function pluginsLoaded(): bool
        {
            return function_exists('did_action') && did_action('plugins_loaded') > 0;
        }

        private static function warnEarly(string $class): void
        {
            if (self::$warned || ! function_exists('_doing_it_wrong') || ! defined('WP_DEBUG') || ! WP_DEBUG) {
                return;
            }

            self::$warned = true;
            _doing_it_wrong(
                $class,
                'WP Wireframe classes must not be used before the plugins_loaded action. A newer bundled copy may still register, so the version loaded is not final.',
                ''
            );
        }
    }
}

// The release version. Bump it with every release; release.yml checks it against the tag.
Versions::register('1.0.6', __DIR__);
