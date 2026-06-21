<?php

declare(strict_types=1);

namespace Wireframe\Framework;

use Wireframe\App;
use Wireframe\Framework\Fields\FieldRegistry;

/**
 * Lightweight, dependency-free linter for page configs.
 *
 * Runs only under `WP_DEBUG` (wired from Plugin) and surfaces an admin notice
 * listing structural mistakes that would otherwise fail silently downstream —
 * unknown field types, choice fields missing their `options`, duplicate IDs,
 * out-of-range `columns`, and so on.
 *
 * This deliberately checks a high-value subset rather than the full config
 * grammar. `schema/config.schema.json` is the authoritative, editor-facing
 * contract (autocomplete + validation while authoring); there is no PHP JSON
 * Schema validator bundled with the package, so this runtime pass is a focused
 * sanity check resolved against the live field registry (so custom types
 * registered via `wp-wireframe/field_types` are recognised too).
 */
final class ConfigValidator
{
    /**
     * Lint a normalized config and return human-readable issue messages.
     *
     * @param array  $config Normalized config (tabs → sections → fields).
     * @param string $slug   Config slug, used only for message context.
     * @return list<string> Issue messages; empty when the config is clean.
     */
    public static function validate(array $config, string $slug = ''): array
    {
        $issues     = [];
        $validTypes = array_keys(FieldRegistry::instance()->all());
        $seenIds    = [];
        $prefix     = $slug !== '' ? $slug . ' · ' : '';

        foreach ($config['tabs'] ?? [] as $tab) {
            $tabId = (string) ($tab['id'] ?? '(no id)');

            foreach ($tab['sections'] ?? [] as $section) {
                $sectionId = (string) ($section['id'] ?? '(no id)');
                $where     = $prefix . $tabId . ' › ' . $sectionId;

                foreach ($section['fields'] ?? [] as $field) {
                    if (is_array($field)) {
                        self::validateField($field, $validTypes, $seenIds, $issues, $where);
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * Validate a single field (recursing into repeater subfields).
     *
     * @param array        $field      Raw field config.
     * @param list<string> $validTypes Registered field type slugs.
     * @param array<string, true> $seenIds Field IDs already seen (by reference).
     * @param list<string> $issues     Collected issue messages (by reference).
     * @param string       $where      Location label for messages (tab › section).
     * @param string       $idPrefix   Prefix for nested (repeater subfield) IDs.
     */
    private static function validateField(
        array $field,
        array $validTypes,
        array &$seenIds,
        array &$issues,
        string $where,
        string $idPrefix = ''
    ): void {
        $id     = (string) ($field['id'] ?? '');
        $type   = (string) ($field['type'] ?? '');
        $label  = $id !== '' ? $id : '(missing id)';
        $fullId = $idPrefix . $id;

        if ($id === '') {
            $issues[] = sprintf('%s: a field is missing its required "id".', $where);
        } else {
            if (isset($seenIds[$fullId])) {
                $issues[] = sprintf('%s: duplicate field id "%s".', $where, $fullId);
            }

            $seenIds[$fullId] = true;
        }

        if ($type === '') {
            $issues[] = sprintf('%s: field "%s" is missing its required "type".', $where, $label);

            return;
        }

        if (!in_array($type, $validTypes, true)) {
            $issues[] = sprintf('%s: field "%s" has unknown type "%s".', $where, $label, $type);

            return;
        }

        $columns = $field['columns'] ?? null;

        if ($columns !== null && (!is_int($columns) || $columns < 1 || $columns > 12)) {
            $shown = is_scalar($columns) ? (string) $columns : gettype($columns);
            $issues[] = sprintf('%s: field "%s" has "columns" = %s, outside the 1–12 range.', $where, $label, $shown);
        }

        $args = is_array($field['args'] ?? null) ? $field['args'] : [];

        if (in_array($type, ['select', 'radio', 'image_radio', 'checkboxes', 'image_checkboxes'], true)
            && empty($args['options'])
        ) {
            $issues[] = sprintf('%s: field "%s" (%s) requires "args.options".', $where, $label, $type);
        }

        if ($type === 'html' && empty($args['content'])) {
            $issues[] = sprintf('%s: field "%s" (html) requires "args.content".', $where, $label);
        }

        if ($type === 'table' && empty($args['fields'])) {
            $issues[] = sprintf('%s: field "%s" (table) requires "args.fields".', $where, $label);
        }

        if ($type === 'repeater') {
            if (empty($args['subfields']) || !is_array($args['subfields'])) {
                $issues[] = sprintf('%s: field "%s" (repeater) requires "args.subfields".', $where, $label);

                return;
            }

            foreach ($args['subfields'] as $subfield) {
                if (is_array($subfield)) {
                    self::validateField($subfield, $validTypes, $seenIds, $issues, $where, $fullId . '.');
                }
            }
        }
    }

    /**
     * Print an admin notice listing config issues across every booted page.
     *
     * Wired on `admin_notices` from Plugin only when `WP_DEBUG` is enabled, so
     * it never shows in production. Issues are also written to the PHP error
     * log so they're captured even on Wireframe screens (where the framework
     * suppresses foreign `admin_notices` output).
     */
    public static function adminNotice(): void
    {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) {
            return;
        }

        $issues = [];

        foreach (App::pages() as $page) {
            $slug   = (string) ($page['config'] ?? '');
            $config = ConfigLoader::load($slug);

            foreach (self::validate($config, $slug) as $issue) {
                $issues[$issue] = true;
            }
        }

        if ($issues === []) {
            return;
        }

        $messages = array_keys($issues);

        foreach ($messages as $message) {
            error_log('WP Wireframe config issue — ' . $message);
        }

        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong></p><ul style="list-style:disc;margin-left:20px;">',
            esc_html__('WP Wireframe found config issues (shown because WP_DEBUG is on):', 'wp-wireframe')
        );

        foreach ($messages as $message) {
            printf('<li>%s</li>', esc_html($message));
        }

        echo '</ul></div>';
    }
}
