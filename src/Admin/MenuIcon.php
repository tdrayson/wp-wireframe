<?php

declare(strict_types=1);

namespace Wireframe\Admin;

use Wireframe\App;

/**
 * Normalizes a page's `menu_icon` value into a form add_menu_page() can render.
 *
 * In addition to the usual dashicon slug, image URL, or `data:` URI, a page may
 * point `menu_icon` at a custom SVG — either inline markup or a path to an
 * `.svg` file. WordPress renders a base64-encoded SVG data URI as a properly
 * coloured menu icon: when the SVG uses `fill="currentColor"` (or declares no
 * fill) it inherits the admin colour scheme exactly like a dashicon does.
 *
 * This lets a consuming plugin ship a branded menu icon without depending on
 * dashicons or a raster image.
 */
final class MenuIcon
{
    /**
     * Convert a `menu_icon` value to something add_menu_page() can render.
     *
     * - Inline SVG (markup starting with `<svg`): base64-encoded into a
     *   `data:image/svg+xml;base64,...` URI.
     * - `.svg` file path (existing, readable, under an allowed root): the file
     *   is read and encoded the same way.
     * - Anything else (dashicon slug, existing `data:` URI, image URL, `none`,
     *   or an empty string): returned unchanged.
     *
     * @param string $icon Raw `menu_icon` value from the page config.
     * @return string Normalized icon value for add_menu_page().
     */
    public static function normalize(string $icon): string
    {
        $trimmed = trim($icon);

        if ($trimmed === '') {
            return $icon;
        }

        // Inline SVG markup.
        if (stripos($trimmed, '<svg') === 0) {
            return self::encode($trimmed);
        }

        // Path to a local .svg file.
        if (self::isSvgFilePath($trimmed)) {
            $markup = self::readSvgFile($trimmed);

            if ($markup !== null) {
                return self::encode($markup);
            }
        }

        // Dashicon slug, image URL, data URI, `none`, etc. — leave untouched.
        return $icon;
    }

    /**
     * Base64-encode SVG markup into a data URI WordPress can use as a menu icon.
     *
     * @param string $svg Raw SVG markup.
     * @return string `data:image/svg+xml;base64,...` URI.
     */
    private static function encode(string $svg): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Does the value look like a local path to an `.svg` file (not a URL)?
     *
     * A remote URL or data URI ending in `.svg` is explicitly excluded so it
     * passes through untouched — only on-disk files are read and encoded.
     *
     * @param string $value Trimmed `menu_icon` value.
     */
    private static function isSvgFilePath(string $value): bool
    {
        // Protocol-relative or absolute URLs and data URIs are not file paths.
        if (preg_match('#^(https?:)?//#i', $value) === 1 || stripos($value, 'data:') === 0) {
            return false;
        }

        return strtolower(pathinfo($value, PATHINFO_EXTENSION)) === 'svg';
    }

    /**
     * Read an `.svg` file's contents, guarding existence, readability, and location.
     *
     * The path is canonicalised with realpath() and must resolve to a readable
     * file under an allowed root (the WordPress content/plugin/theme trees or
     * the wp-wireframe package itself) so a stray config value can't coax the
     * loader into reading arbitrary files. Returns null when any guard fails.
     *
     * @param string $path Candidate `.svg` file path from the config.
     * @return string|null SVG markup, or null when the file can't be safely read.
     */
    private static function readSvgFile(string $path): ?string
    {
        $real = realpath($path);

        if ($real === false || !is_file($real) || !is_readable($real)) {
            return null;
        }

        if (!self::isWithinAllowedRoot($real)) {
            return null;
        }

        $contents = file_get_contents($real);

        if ($contents === false || stripos($contents, '<svg') === false) {
            return null;
        }

        return $contents;
    }

    /**
     * Is the resolved file path inside one of the locations we permit reading from?
     *
     * Allowed roots are the WordPress content, plugin, and theme directories
     * plus the wp-wireframe package directory (which covers the common
     * symlinked-into-vendor development layout). When none of the WordPress
     * path constants are defined — e.g. an isolated unit-test context — the
     * check is skipped so the helper stays testable.
     *
     * @param string $realPath A realpath()-resolved absolute file path.
     */
    private static function isWithinAllowedRoot(string $realPath): bool
    {
        $candidates = [];

        if (defined('WP_CONTENT_DIR')) {
            $candidates[] = WP_CONTENT_DIR;
        }

        if (defined('WP_PLUGIN_DIR')) {
            $candidates[] = WP_PLUGIN_DIR;
        }

        if (defined('WPMU_PLUGIN_DIR')) {
            $candidates[] = WPMU_PLUGIN_DIR;
        }

        if (function_exists('get_stylesheet_directory')) {
            $candidates[] = get_stylesheet_directory();
        }

        if (function_exists('get_template_directory')) {
            $candidates[] = get_template_directory();
        }

        $candidates[] = App::packageDir();

        $roots = [];

        foreach ($candidates as $candidate) {
            $realRoot = realpath((string) $candidate);

            if ($realRoot !== false) {
                $roots[] = rtrim($realRoot, '/\\') . DIRECTORY_SEPARATOR;
            }
        }

        // No resolvable roots (no WordPress constants, isolated test) — allow.
        if ($roots === []) {
            return true;
        }

        foreach ($roots as $root) {
            if (str_starts_with($realPath, $root)) {
                return true;
            }
        }

        return false;
    }
}
