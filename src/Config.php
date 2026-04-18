<?php

declare(strict_types=1);

namespace Wireframe;

/**
 * Static helpers for programmatic config manipulation.
 *
 * All methods accept and return the full config array, making them
 * ideal for use inside filter callbacks:
 *
 *   add_filter('my-plugin/config', function (array $config) {
 *       return Config::addFieldAfter('site_name', [
 *           'id' => 'subtitle', 'type' => 'text', 'label' => 'Subtitle',
 *       ], $config);
 *   });
 */
final class Config
{
    /**
     * Add a field after a specific field ID.
     *
     * Searches all tabs/sections for the target field.
     *
     * @param string $afterId  The field ID to insert after.
     * @param array  $newField The new field definition (must include `id`).
     * @param array  $config   The full config array.
     * @return array Modified config.
     */
    public static function addFieldAfter(string $afterId, array $newField, array $config): array
    {
        return self::insertField($afterId, $newField, $config, 'after');
    }

    /**
     * Add a field before a specific field ID.
     *
     * @param string $beforeId The field ID to insert before.
     * @param array  $newField The new field definition (must include `id`).
     * @param array  $config   The full config array.
     * @return array Modified config.
     */
    public static function addFieldBefore(string $beforeId, array $newField, array $config): array
    {
        return self::insertField($beforeId, $newField, $config, 'before');
    }

    /**
     * Modify an existing field by merging new values.
     *
     * @param string $fieldId The field ID to modify.
     * @param array  $merges  Key-value pairs to merge into the field.
     * @param array  $config  The full config array.
     * @return array Modified config.
     */
    public static function modifyField(string $fieldId, array $merges, array $config): array
    {
        return self::walkFields($config, function (array $field) use ($fieldId, $merges) {
            if (($field['id'] ?? '') === $fieldId) {
                return array_merge($field, $merges);
            }
            return $field;
        });
    }

    /**
     * Remove a field by ID.
     *
     * @param string $fieldId The field ID to remove.
     * @param array  $config  The full config array.
     * @return array Modified config.
     */
    public static function removeField(string $fieldId, array $config): array
    {
        return self::walkFields($config, function (array $field) use ($fieldId) {
            if (($field['id'] ?? '') === $fieldId) {
                return null; // Signal removal.
            }
            return $field;
        });
    }

    /**
     * Add a section to a specific tab.
     *
     * @param string $tabId      The tab ID to add the section to.
     * @param array  $newSection The new section definition (must include `id`).
     * @param array  $config     The full config array.
     * @return array Modified config.
     */
    public static function addSection(string $tabId, array $newSection, array $config): array
    {
        foreach ($config['tabs'] ?? [] as $tabIndex => $tab) {
            if (($tab['id'] ?? '') === $tabId) {
                $config['tabs'][$tabIndex]['sections'][] = $newSection;
                break;
            }
        }

        return $config;
    }

    /**
     * Add a tab to the config.
     *
     * @param array $newTab The new tab definition (must include `id`).
     * @param array $config The full config array.
     * @return array Modified config.
     */
    public static function addTab(array $newTab, array $config): array
    {
        $config['tabs'][] = $newTab;

        return $config;
    }

    /**
     * Remove a tab by ID.
     *
     * @param string $tabId  The tab ID to remove.
     * @param array  $config The full config array.
     * @return array Modified config.
     */
    public static function removeTab(string $tabId, array $config): array
    {
        $config['tabs'] = array_values(
            array_filter($config['tabs'] ?? [], fn(array $tab) => ($tab['id'] ?? '') !== $tabId)
        );

        return $config;
    }

    /**
     * Remove a section by ID from any tab.
     *
     * @param string $sectionId The section ID to remove.
     * @param array  $config    The full config array.
     * @return array Modified config.
     */
    public static function removeSection(string $sectionId, array $config): array
    {
        foreach ($config['tabs'] ?? [] as $tabIndex => $tab) {
            $config['tabs'][$tabIndex]['sections'] = array_values(
                array_filter(
                    $tab['sections'] ?? [],
                    fn(array $section) => ($section['id'] ?? '') !== $sectionId
                )
            );
        }

        return $config;
    }

    // ─── Internals ────────────────────────────────────

    /**
     * Insert a field before or after a target field ID.
     */
    private static function insertField(string $targetId, array $newField, array $config, string $position): array
    {
        foreach ($config['tabs'] ?? [] as $tabIndex => $tab) {
            foreach ($tab['sections'] ?? [] as $sectionIndex => $section) {
                $fields = $section['fields'] ?? [];

                foreach ($fields as $fieldIndex => $field) {
                    if (($field['id'] ?? '') !== $targetId) {
                        continue;
                    }

                    $insertAt = $position === 'after' ? $fieldIndex + 1 : $fieldIndex;
                    array_splice($fields, $insertAt, 0, [$newField]);
                    $config['tabs'][$tabIndex]['sections'][$sectionIndex]['fields'] = $fields;

                    return $config;
                }
            }
        }

        return $config;
    }

    /**
     * Walk all fields in the config, applying a callback to each.
     *
     * If the callback returns null, the field is removed.
     *
     * @param array    $config   The full config array.
     * @param callable $callback Receives a field array, returns modified field or null.
     * @return array Modified config.
     */
    private static function walkFields(array $config, callable $callback): array
    {
        foreach ($config['tabs'] ?? [] as $tabIndex => $tab) {
            foreach ($tab['sections'] ?? [] as $sectionIndex => $section) {
                $fields = [];

                foreach ($section['fields'] ?? [] as $field) {
                    $result = $callback($field);

                    if ($result !== null) {
                        $fields[] = $result;
                    }
                }

                $config['tabs'][$tabIndex]['sections'][$sectionIndex]['fields'] = $fields;
            }
        }

        return $config;
    }
}
