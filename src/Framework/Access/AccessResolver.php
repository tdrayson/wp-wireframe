<?php

declare(strict_types=1);

namespace Wireframe\Framework\Access;

use WP_User;

/**
 * Resolves role-based access for tabs, sections, and fields.
 *
 * Reads the optional `access` key on each element. Two verbs: `view` and
 * `edit`. `edit` implicitly requires `view`. Values match role slugs first
 * (via get_role()), then fall back to capabilities (current_user_can()).
 *
 * Role-based access is strictly opt-in. If a page config contains zero
 * `access` keys anywhere in its tree, hasAccessKeys() returns false and
 * callers fall through to the legacy `manage_options` capability flow.
 *
 * The final yes/no for every (verb, level, id, user) check passes through
 * the `wp-wireframe/access/resolve` filter so developers can override
 * decisions on the fly.
 */
final class AccessResolver
{
    public function __construct(private readonly string $pageCapability = 'manage_options')
    {
    }

    /**
     * Does any tab/section/field in this config declare an `access` key?
     *
     * Used by the menu and REST layers to detect "RBAC mode" — without this
     * check, every page would silently switch to the new resolution pipeline
     * even when authors haven't opted in.
     */
    public static function hasAccessKeys(array $config): bool
    {
        foreach ($config['tabs'] ?? [] as $tab) {
            if (isset($tab['access'])) {
                return true;
            }

            foreach ($tab['sections'] ?? [] as $section) {
                if (isset($section['access'])) {
                    return true;
                }

                foreach ($section['fields'] ?? [] as $field) {
                    if (isset($field['access'])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Page mode: 'legacy' (no access keys) or 'rbac' (at least one).
     */
    public static function pageMode(array $config): string
    {
        return self::hasAccessKeys($config) ? 'rbac' : 'legacy';
    }

    /**
     * Check whether a user satisfies a verb on a single access spec.
     *
     * `$accessSpec` is the resolved spec for this element after inheritance
     * (e.g. `['view' => 'editor', 'edit' => 'manage_options']`). A null
     * spec means the element falls back to the page capability.
     *
     * The result passes through `wp-wireframe/access/resolve` so callers
     * can override on a per-(verb, level, id) basis.
     *
     * @param string                                                $verb    'view' or 'edit'.
     * @param array{view?: mixed, edit?: mixed}|null                $spec    Resolved access spec.
     * @param array{level: string, id: string, user?: WP_User|null} $context Extra context for the filter.
     */
    public function userCan(string $verb, ?array $spec, ?WP_User $user, array $context = []): bool
    {
        $user ??= self::currentUser();

        // Fall back to the page capability when nothing was declared.
        $requirement = $spec[$verb] ?? null;

        if ($requirement === null) {
            // `edit` falls back through `view` first so an author can grant
            // view to "editor" without also having to repeat the capability.
            if ($verb === 'edit' && isset($spec['view'])) {
                $requirement = $spec['view'];
            } else {
                $requirement = $this->pageCapability;
            }
        }

        $allowed = self::satisfies($requirement, $user);

        // Edit always requires view: if a user can't see it, they can't write to it.
        if ($verb === 'edit' && $allowed) {
            $allowed = $this->userCan('view', $spec, $user, $context);
        }

        $context = array_merge(
            ['verb' => $verb, 'spec' => $spec ?? [], 'user' => $user],
            $context,
        );

        if (function_exists('apply_filters')) {
            $allowed = (bool) apply_filters('wp-wireframe/access/resolve', $allowed, $context);
        }

        return $allowed;
    }

    /**
     * Build a per-user access map for a normalized config.
     *
     * Walks tabs → sections → fields once, resolving inherited specs and
     * collecting what the user can view and edit. Tabs and sections drop
     * out if every child below them is hidden (auto-hide behavior).
     *
     * @param array $config Normalized config from ConfigLoader::load().
     */
    public function resolveForConfig(array $config, ?WP_User $user = null): ConfigAccessMap
    {
        $mode = self::pageMode($config);
        $user ??= self::currentUser();

        if ($mode === 'legacy') {
            // No access keys — admin layer keeps using `manage_options`.
            // Editable list is computed from the flat config so the REST
            // save path can still scope writes uniformly.
            return new ConfigAccessMap(
                mode: 'legacy',
                viewable: self::flatViewable($config),
                editable: self::flatEditable($config),
                canReset: true,
            );
        }

        $viewable = [];
        $editable = [];

        foreach ($config['tabs'] ?? [] as $tab) {
            $tabId = (string) ($tab['id'] ?? '');
            if ($tabId === '') {
                continue;
            }

            $tabSpec = self::normalizeSpec($tab['access'] ?? null);

            if (!$this->userCan('view', $tabSpec, $user, ['level' => 'tab', 'id' => $tabId])) {
                continue;
            }

            $tabSections = [];

            foreach ($tab['sections'] ?? [] as $section) {
                $sectionId = (string) ($section['id'] ?? '');
                if ($sectionId === '') {
                    continue;
                }

                // Sections inherit unspecified verbs from the tab.
                $sectionSpec = self::inheritSpec(
                    self::normalizeSpec($section['access'] ?? null),
                    $tabSpec,
                );

                if (!$this->userCan('view', $sectionSpec, $user, ['level' => 'section', 'id' => $sectionId])) {
                    continue;
                }

                $sectionFields = [];

                foreach ($section['fields'] ?? [] as $field) {
                    $fieldId = (string) ($field['id'] ?? '');
                    if ($fieldId === '') {
                        continue;
                    }

                    $fieldSpec = self::inheritSpec(
                        self::normalizeSpec($field['access'] ?? null),
                        $sectionSpec,
                    );

                    if (!$this->userCan('view', $fieldSpec, $user, ['level' => 'field', 'id' => $fieldId])) {
                        continue;
                    }

                    $sectionFields[] = $fieldId;

                    if ($this->userCan('edit', $fieldSpec, $user, ['level' => 'field', 'id' => $fieldId])) {
                        $editable[] = $fieldId;

                        // Repeater subfields share the parent's editability — they don't
                        // get their own access keys (would be confusing UX) but they need
                        // to appear in the editable list so REST sanitization touches them.
                        if (($field['type'] ?? '') === 'repeater') {
                            foreach ($field['args']['subfields'] ?? [] as $subfield) {
                                $subId = $subfield['id'] ?? '';
                                if ($subId !== '') {
                                    $editable[] = $fieldId . '.' . $subId;
                                }
                            }
                        }
                    }
                }

                if (!empty($sectionFields)) {
                    $tabSections[$sectionId] = $sectionFields;
                }
            }

            if (!empty($tabSections)) {
                $viewable[$tabId] = $tabSections;
            }
        }

        $canReset = !empty($editable);

        if (function_exists('apply_filters')) {
            $canReset = (bool) apply_filters(
                'wp-wireframe/access/can_reset',
                $canReset,
                $user,
                $viewable,
                $editable,
            );
        }

        return new ConfigAccessMap(
            mode: 'rbac',
            viewable: $viewable,
            editable: $editable,
            canReset: $canReset,
        );
    }

    /**
     * Strip non-viewable elements and mark non-editable fields as readonly.
     *
     * Operates on a normalized config and returns a config of the same shape
     * with everything the user shouldn't see removed. Tabs and sections that
     * end up empty are dropped. Visible-but-not-editable fields gain a
     * `readonly: true` top-level flag so the frontend can disable them.
     *
     * In legacy mode (no access keys anywhere), the config is returned as-is
     * since there's nothing to filter — RBAC behavior is strictly opt-in.
     */
    public function filterConfig(array $config, ConfigAccessMap $map): array
    {
        if ($map->isLegacy()) {
            return $config;
        }

        $filteredTabs = [];

        foreach ($config['tabs'] ?? [] as $tab) {
            $tabId = $tab['id'] ?? '';

            if (!isset($map->viewable[$tabId])) {
                continue;
            }

            $tabSectionMap = $map->viewable[$tabId];
            $filteredSections = [];

            foreach ($tab['sections'] ?? [] as $section) {
                $sectionId = $section['id'] ?? '';

                if (!isset($tabSectionMap[$sectionId])) {
                    continue;
                }

                $allowedFieldIds = $tabSectionMap[$sectionId];
                $filteredFields = [];

                foreach ($section['fields'] ?? [] as $field) {
                    $fieldId = $field['id'] ?? '';

                    if (!in_array($fieldId, $allowedFieldIds, true)) {
                        continue;
                    }

                    // Strip the access key from the output — the frontend
                    // doesn't need it (and shouldn't see role/cap names).
                    unset($field['access']);

                    if (!$map->canEdit($fieldId)) {
                        $field['readonly'] = true;
                    }

                    $filteredFields[] = $field;
                }

                if (!empty($filteredFields)) {
                    $section['fields'] = $filteredFields;
                    unset($section['access']);
                    $filteredSections[] = $section;
                }
            }

            if (!empty($filteredSections)) {
                $tab['sections'] = $filteredSections;
                unset($tab['access']);
                $filteredTabs[] = $tab;
            }
        }

        $config['tabs'] = $filteredTabs;

        return $config;
    }

    /**
     * Test a single requirement against a user.
     *
     * Strings: role slug first (via get_role), then capability.
     * Arrays:  OR — passes if any value matches.
     */
    private static function satisfies(mixed $requirement, ?WP_User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (is_array($requirement)) {
            foreach ($requirement as $candidate) {
                if (self::satisfies($candidate, $user)) {
                    return true;
                }
            }
            return false;
        }

        if (!is_string($requirement) || $requirement === '') {
            return false;
        }

        // Role slug match — strings like 'editor' or 'subscriber' that map
        // to a registered role take precedence over capability lookups.
        if (function_exists('get_role') && get_role($requirement) !== null) {
            return in_array($requirement, (array) $user->roles, true);
        }

        if (function_exists('user_can')) {
            return (bool) user_can($user, $requirement);
        }

        return false;
    }

    /**
     * Normalize the various accepted `access` shorthand forms into a spec
     * with explicit `view`/`edit` keys.
     */
    private static function normalizeSpec(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        // String shorthand → same value for view and edit.
        if (is_string($raw) || (is_array($raw) && array_is_list($raw))) {
            return ['view' => $raw, 'edit' => $raw];
        }

        if (is_array($raw)) {
            return [
                'view' => $raw['view'] ?? null,
                'edit' => $raw['edit'] ?? ($raw['view'] ?? null),
            ];
        }

        return null;
    }

    /**
     * Merge a child spec onto a parent so unspecified verbs inherit.
     *
     * A field that only declares `view` keeps the section's `edit` rule,
     * and vice versa. Returns null if both child and parent are null so
     * downstream code can fall through to the page capability cleanly.
     */
    private static function inheritSpec(?array $child, ?array $parent): ?array
    {
        if ($child === null) {
            return $parent;
        }

        if ($parent === null) {
            return $child;
        }

        return [
            'view' => $child['view'] ?? $parent['view'] ?? null,
            'edit' => $child['edit'] ?? $parent['edit'] ?? null,
        ];
    }

    private static function currentUser(): ?WP_User
    {
        if (!function_exists('wp_get_current_user')) {
            return null;
        }

        $user = wp_get_current_user();

        return ($user && $user->ID) ? $user : null;
    }

    /**
     * Legacy-mode viewable map — every tab/section/field is included.
     *
     * Same shape as the RBAC viewable map so downstream consumers (config
     * filtering, menu suppression checks) don't need a separate code path.
     *
     * @return array<string, array<string, list<string>>>
     */
    private static function flatViewable(array $config): array
    {
        $map = [];

        foreach ($config['tabs'] ?? [] as $tab) {
            $tabId = $tab['id'] ?? '';
            if ($tabId === '') {
                continue;
            }

            $sections = [];

            foreach ($tab['sections'] ?? [] as $section) {
                $sectionId = $section['id'] ?? '';
                if ($sectionId === '') {
                    continue;
                }

                $fieldIds = [];

                foreach ($section['fields'] ?? [] as $field) {
                    $fieldId = $field['id'] ?? '';
                    if ($fieldId !== '') {
                        $fieldIds[] = $fieldId;
                    }
                }

                if (!empty($fieldIds)) {
                    $sections[$sectionId] = $fieldIds;
                }
            }

            if (!empty($sections)) {
                $map[$tabId] = $sections;
            }
        }

        return $map;
    }

    /**
     * Legacy-mode editable list — every field plus repeater subfields.
     *
     * @return list<string>
     */
    private static function flatEditable(array $config): array
    {
        $ids = [];

        foreach ($config['tabs'] ?? [] as $tab) {
            foreach ($tab['sections'] ?? [] as $section) {
                foreach ($section['fields'] ?? [] as $field) {
                    $fieldId = $field['id'] ?? '';
                    if ($fieldId === '') {
                        continue;
                    }

                    $ids[] = $fieldId;

                    if (($field['type'] ?? '') === 'repeater') {
                        foreach ($field['args']['subfields'] ?? [] as $subfield) {
                            $subId = $subfield['id'] ?? '';
                            if ($subId !== '') {
                                $ids[] = $fieldId . '.' . $subId;
                            }
                        }
                    }
                }
            }
        }

        return $ids;
    }
}
