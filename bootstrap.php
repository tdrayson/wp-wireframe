<?php

/**
 * WP Wireframe bootstrap.
 *
 * Every bundled copy of the framework includes this file. Each copy registers
 * itself, and classes load from the newest version registered, using that
 * copy's autoload-map.php.
 */

declare(strict_types=1);

namespace Wireframe;

use Closure;

// Declared here rather than in src/ so Strauss prefixes it along with the namespace.
if (! class_exists(Versions::class, false)) {
    /**
     * Registry of bundled WP Wireframe copies, plus the one autoloader they share.
     *
     * Whichever copy loads first defines this class for every other copy, so it
     * must stay generic and its public API must never change.
     */
    final class Versions
    {
        /** @var list<array{version: string, dir: string}> Registered copies, in registration order. */
        private static array $copies = [];

        /** @var array{version: string, dir: string}|null Copy chosen at `plugins_loaded`. */
        private static ?array $locked = null;

        /** @var array<string, array<string, list<string>>> Namespace maps, keyed by copy root. */
        private static array $maps = [];

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
            $copy = ['version' => $version, 'dir' => $dir];
            self::$copies[] = $copy;

            if (self::$locked !== null) {
                self::warnMajorMismatch([$copy]);
            }

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
         * Every registered copy, in registration order. `source` is the owning plugin's
         * directory name, `theme:{slug}` for a theme, or the copy's path otherwise.
         *
         * @return list<array{version: string, dir: string, source: string}>
         */
        public static function registered(): array
        {
            return array_map(
                static fn (array $copy): array => $copy + ['source' => self::source($copy['dir'])],
                self::$copies
            );
        }

        /**
         * The copy classes load from: the one locked at `plugins_loaded`, or the
         * newest registered so far.
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

            self::warnMajorMismatch(self::$copies);
        }

        private static function autoload(string $class): void
        {
            if (self::$locked === null && self::pluginsLoaded()) {
                self::lock();
            }

            $copy = self::active();
            if ($copy === null) {
                return;
            }

            foreach (self::map($copy['dir']) as $prefix => $paths) {
                if (! str_starts_with($class, $prefix)) {
                    continue;
                }

                if (self::$locked === null) {
                    self::warnEarly($class);
                }

                $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                foreach ($paths as $path) {
                    $file = $copy['dir'] . '/' . $path . $relative;
                    if (is_file($file)) {
                        require $file;
                        return;
                    }
                }
            }
        }

        /**
         * Namespace prefixes a copy autoloads, mapped to directories relative to its root.
         *
         * @param string $dir The copy's root.
         * @return array<string, list<string>>
         */
        private static function map(string $dir): array
        {
            if (! isset(self::$maps[$dir])) {
                $map = is_file($dir . '/autoload-map.php') ? require $dir . '/autoload-map.php' : [];
                self::$maps[$dir] = is_array($map) ? array_map(static fn ($paths): array => (array) $paths, $map) : [];
            }

            return self::$maps[$dir];
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

        /**
         * Plugin directory name, `theme:{slug}`, or the path itself.
         *
         * @param string $dir A copy's root.
         * @return string
         */
        private static function source(string $dir): string
        {
            $path = trim(function_exists('wp_normalize_path') ? wp_normalize_path($dir) : str_replace('\\', '/', $dir), '/');

            if (function_exists('plugin_basename')) {
                $relative = plugin_basename($dir);
                if ($relative !== $path) {
                    return explode('/', $relative)[0];
                }
            }

            if (function_exists('get_theme_root')) {
                $themes = trim(str_replace('\\', '/', get_theme_root()), '/') . '/';
                if (str_starts_with($path, $themes)) {
                    return 'theme:' . explode('/', substr($path, strlen($themes)))[0];
                }
            }

            return $dir;
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

        private static function debugging(): bool
        {
            return function_exists('_doing_it_wrong') && defined('WP_DEBUG') && WP_DEBUG;
        }

        private static function warnEarly(string $class): void
        {
            if (self::$warned || ! self::debugging()) {
                return;
            }

            self::$warned = true;
            _doing_it_wrong(
                $class,
                'WP Wireframe classes must not be used before the plugins_loaded action. A newer bundled copy may still register, so the version loaded is not final.',
                ''
            );
        }

        /**
         * Warn when any of the given copies is a different major version from the locked one.
         *
         * @param list<array{version: string, dir: string}> $copies Copies to compare.
         */
        private static function warnMajorMismatch(array $copies): void
        {
            if (self::$locked === null || ! self::debugging()) {
                return;
            }

            $running = self::$locked['version'];
            $lines = [];
            foreach ($copies as $copy) {
                if (explode('.', $copy['version'])[0] !== explode('.', $running)[0]) {
                    $lines[] = sprintf('%s bundles wp-wireframe %s but %s is running.', self::source($copy['dir']), $copy['version'], $running);
                }
            }

            if ($lines !== []) {
                $lines[] = sprintf('The running copy comes from %s.', self::source(self::$locked['dir']));
                _doing_it_wrong(self::class, implode(' ', $lines), '');
            }
        }
    }
}

// The release version. Bump it with every release; release.yml checks it against the tag.
Versions::register('1.1.0', __DIR__);
