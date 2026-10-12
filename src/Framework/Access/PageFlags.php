<?php

declare(strict_types=1);

namespace Wireframe\Framework\Access;

use Wireframe\App;
use Wireframe\Framework\ConfigLoader;
use Wireframe\Framework\Fields\FieldRegistry;

/**
 * Resolves a page's `canSave` / `canReset` flags, shared by the localized
 * admin data, the REST settings response, and the save/reset endpoints.
 */
final class PageFlags
{
    /**
     * Whether the user may save the page, after the `{prefix}/can_save` filter.
     *
     * @param array           $page Page entry from App::page().
     * @param ConfigAccessMap $map  The user's access map for the page.
     */
    public static function canSave(array $page, ConfigAccessMap $map): bool
    {
        $canSave = self::hasPersistableField($page['config'], $map->editable);

        /**
         * Filter whether the Save button shows and the save endpoint accepts writes.
         *
         * @param bool            $canSave False by default when no editable field persists a value.
         * @param string          $pageId  Page identifier.
         * @param ConfigAccessMap $map     The user's access map for the page.
         */
        return (bool) apply_filters(App::hookName($page['prefix'], 'can_save'), $canSave, $page['page_id'], $map);
    }

    /**
     * Whether the user may reset the page, after the `{prefix}/can_reset` filter.
     *
     * @param array           $page Page entry from App::page().
     * @param ConfigAccessMap $map  The user's access map for the page.
     */
    public static function canReset(array $page, ConfigAccessMap $map): bool
    {
        $canReset = $map->canReset && self::hasPersistableField($page['config'], $map->editable);

        /**
         * Filter whether the Reset button shows and the reset endpoint is allowed.
         *
         * @param bool            $canReset False by default when the access map denies reset or no editable field persists a value.
         * @param string          $pageId   Page identifier.
         * @param ConfigAccessMap $map      The user's access map for the page.
         */
        return (bool) apply_filters(App::hookName($page['prefix'], 'can_reset'), $canReset, $page['page_id'], $map);
    }

    /**
     * Whether any editable field stores a value, i.e. isn't stateless like `html` or `action`.
     *
     * @param list<string> $editable Editable field IDs from the access map.
     */
    private static function hasPersistableField(string $configSlug, array $editable): bool
    {
        $fields   = ConfigLoader::flatFields($configSlug);
        $registry = FieldRegistry::instance();

        foreach ($editable as $fieldId) {
            $type = $fields[$fieldId]['type'] ?? 'text';

            if (!$registry->get($type)::isStateless()) {
                return true;
            }
        }

        return false;
    }
}
