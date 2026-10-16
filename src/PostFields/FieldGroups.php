<?php

declare(strict_types=1);

namespace Wireframe\PostFields;

use Wireframe\Framework\Access\AccessResolver;
use Wireframe\Framework\ConfigLoader;
use Wireframe\Framework\ConfigValidator;

/**
 * Registry of field groups attached to post types.
 *
 * Groups are collected at `App::boot()` and validated once on `wp_loaded`.
 */
final class FieldGroups
{
    /** Field types with no stored value, or no meaning, on a post screen. */
    private const EXCLUDED_TYPES = ['action', 'table', 'import', 'export', 'upload'];

    private const KEYS = ['id', 'title', 'location', 'display', 'config'];

    private const DISPLAY_DEFAULTS = ['position' => 'meta_box', 'collapsed' => false, 'order' => 10];

    private const POSITIONS = ['content', 'meta_box', 'sidebar'];

    /** @var array<string, array> Normalised groups by owner key, in registration order. */
    private static array $groups = [];

    /** @var array<string, string> Shape problems found while normalising, by owner key. */
    private static array $problems = [];

    /** @var array<string, list<array>> Valid groups by post type. */
    private static array $attached = [];

    /**
     * Register a field group, replacing one with the same prefix and id; validates it and registers its meta at once after `wp_loaded`.
     *
     * @param string $prefix Consumer prefix from `App::boot()`.
     * @param array  $group  `id`, `title`, `location`, `display` and `config` (a ConfigLoader slug or a raw config array).
     */
    public static function register(string $prefix, array $group): void
    {
        [$normalised, $problem] = self::normalise($prefix, $group);
        $owner = $normalised['owner'];

        self::$groups[$owner] = $normalised;
        self::$problems[$owner] = $problem;
        self::lintShape($normalised, $group);

        if (did_action('wp_loaded') > 0) {
            self::bootGroup($normalised);
            self::registerMeta($normalised);
        }
    }

    /**
     * Validate every registered group and attach the valid ones to their post types.
     *
     * Safe to run again: the result is rebuilt from the registered groups.
     */
    public static function boot(): void
    {
        self::$attached = [];

        foreach (self::$groups as $group) {
            self::bootGroup($group);
        }
    }

    /**
     * Get this copy's valid groups for a post type, by `display.order` then registration order.
     *
     * @param string $postType Post type slug.
     * @return list<array> Normalised groups.
     */
    public static function groupsFor(string $postType): array
    {
        $groups   = self::$attached[$postType] ?? [];
        $position = array_flip(array_keys(self::$groups));

        usort($groups, static fn (array $a, array $b): int => [$a['display']['order'], $position[$a['owner']]]
            <=> [$b['display']['order'], $position[$b['owner']]]);

        return $groups;
    }

    /**
     * Get a post type's fields from every group, keyed by id, repeater subfields as `{id}.{sub}`.
     *
     * @param string $postType Post type slug.
     * @return array<string, array>
     */
    public static function flatFields(string $postType): array
    {
        $fields = [];

        foreach (self::groupsFor($postType) as $group) {
            $fields += ConfigLoader::flatFields($group['config']);
        }

        return $fields;
    }

    /**
     * Get the group that owns a top-level field on a post type.
     *
     * @param string $postType Post type slug.
     * @param string $fieldId  Top-level field id (meta key).
     * @return array|null Normalised group.
     */
    public static function groupOfField(string $postType, string $fieldId): ?array
    {
        if (str_contains($fieldId, '.')) {
            return null;
        }

        foreach (self::$attached[$postType] ?? [] as $group) {
            if (isset(ConfigLoader::flatFields($group['config'])[$fieldId])) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Get every post type with groups, mapped to its sorted groups.
     *
     * @return array<string, list<array>>
     */
    public static function all(): array
    {
        $all = [];

        foreach (array_keys(self::$attached) as $postType) {
            $all[$postType] = self::groupsFor($postType);
        }

        return $all;
    }

    /**
     * Forget every group and release this registry's site-wide claims.
     */
    public static function reset(): void
    {
        foreach (self::$groups as $group) {
            self::release($group);
        }

        self::$groups   = [];
        self::$problems = [];
        self::$attached = [];
    }

    /**
     * Fill in defaults and expand the location; returns the group and its first shape problem, if any.
     *
     * @return array{0: array, 1: string|null}
     */
    private static function normalise(string $prefix, array $group): array
    {
        $id       = is_string($group['id'] ?? null) ? $group['id'] : '';
        $display  = $group['display'] ?? [];
        $location = Location::normalize($group['location'] ?? null);
        $merged   = is_array($display) ? array_intersect_key($display, self::DISPLAY_DEFAULTS) + self::DISPLAY_DEFAULTS : self::DISPLAY_DEFAULTS;
        $title    = is_string($group['title'] ?? null) ? $group['title'] : '';

        if ($title === '' && $merged['position'] !== 'content') {
            $title = ucfirst($id);
        }

        $config = self::configSlug($prefix, $id, $group['config'] ?? null);

        if ($merged['position'] === 'sidebar') {
            $config = self::flattenTabs($prefix, $id, $config);
        }

        $normalised = [
            'id'         => $id,
            'prefix'     => $prefix,
            'owner'      => "{$prefix}/{$id}",
            'title'      => $title,
            'location'   => $location,
            'post_types' => $location === null ? [] : (Location::postTypes($location) ?? []),
            'display'    => $merged,
            'config'     => $config,
        ];

        return [$normalised, self::shapeProblem($group, $normalised)];
    }

    /**
     * Register a raw config array under the group's slug, or pass a slug through.
     */
    private static function configSlug(string $prefix, string $id, mixed $config): string
    {
        $slug = self::groupSlug($prefix, $id);

        if (is_array($config)) {
            ConfigLoader::register($slug, $config);

            return $slug;
        }

        return is_string($config) && $config !== '' ? $config : $slug;
    }

    /**
     * Get the config slug a group's own config is registered under.
     */
    private static function groupSlug(string $prefix, string $id): string
    {
        return preg_replace('/[^a-z0-9_-]/i', '-', "{$prefix}-group-{$id}") ?: 'settings';
    }

    /**
     * Merge a sidebar group's tabs into one untitled tab, since a panel has no tab bar; returns the config slug to use.
     *
     * Sections keep their order, titles and effective access, plus their tab's id as `origin_tab`; one whose access can't be kept is dropped.
     */
    private static function flattenTabs(string $prefix, string $id, string $slug): string
    {
        $config = ConfigLoader::load($slug);
        $tabs   = $config['tabs'] ?? [];

        if (!self::hasTabChrome($tabs)) {
            return $slug;
        }

        $group    = ['id' => $id, 'prefix' => $prefix];
        $sections = [];
        $seen     = [];

        foreach ($tabs as $tab) {
            foreach ($tab['sections'] ?? [] as $section) {
                $merged = self::withTabAccess($section, $tab);

                if ($merged === null) {
                    self::debugReport($group, sprintf('config issue: section "%s" was dropped from the "sidebar" group: its access and its tab\'s can\'t be combined in one panel.', $section['id'] ?? ''));

                    continue;
                }

                // Access maps are keyed by section id, so a repeated id would hide the earlier section's fields.
                if (isset($seen[$merged['id'] ?? ''])) {
                    $merged['id'] = ($tab['id'] ?? '') . '-' . ($merged['id'] ?? '');
                }

                $seen[$merged['id'] ?? ''] = true;
                $merged['origin_tab']      = (string) ($tab['id'] ?? '');
                $sections[]                = $merged;
            }
        }

        $flatSlug = self::groupSlug($prefix, $id);
        ConfigLoader::register($flatSlug, ['sections' => $sections] + array_intersect_key($config, array_flip(['title', 'subtitle', 'header_actions'])));
        self::debugReport($group, 'config issue: a "sidebar" group shows one panel, so its tabs were merged; tab titles and conditions are dropped.');

        return $flatSlug;
    }

    /**
     * Whether a config's tabs carry anything a single panel can't show: several tabs, or a tab title or conditions.
     */
    private static function hasTabChrome(array $tabs): bool
    {
        if (count($tabs) > 1) {
            return true;
        }

        $tab = $tabs[0] ?? [];

        return ($tab['title'] ?? '') !== '' || isset($tab['conditions']);
    }

    /**
     * Fold a tab's `access` into one of its sections so AccessResolver gives every user the same result without the tab.
     *
     * The tab gates the section and fills the verbs it leaves unset. When the two view rules differ, the tab's rule
     * gates the section and the section's moves onto its fields, which is exact only while no field sets its own view.
     *
     * @param array $section Section from the tab.
     * @param array $tab     Tab the section came from.
     * @return array|null The section, or null when its access can't be kept.
     */
    private static function withTabAccess(array $section, array $tab): ?array
    {
        if (!isset($tab['access'])) {
            return $section;
        }

        $tabSpec = AccessResolver::normalizeSpec($tab['access']) ?? ['view' => null, 'edit' => null];

        if (!isset($section['access'])) {
            return ['access' => $tabSpec] + $section;
        }

        $sectionSpec = AccessResolver::inheritSpec(AccessResolver::normalizeSpec($section['access']), $tabSpec);

        if ($sectionSpec['view'] === $tabSpec['view']) {
            return ['access' => $sectionSpec] + $section;
        }

        $fields = [];

        foreach ($section['fields'] ?? [] as $field) {
            $fieldSpec = AccessResolver::inheritSpec(AccessResolver::normalizeSpec($field['access'] ?? null), $sectionSpec);

            if ($fieldSpec['view'] !== $sectionSpec['view']) {
                return null;
            }

            $fields[] = ['access' => $fieldSpec] + $field;
        }

        return ['access' => $tabSpec, 'fields' => $fields] + $section;
    }

    /**
     * Describe why a group's own keys are unusable, or null when they are fine.
     */
    private static function shapeProblem(array $raw, array $group): ?string
    {
        $display = $group['display'];

        return match (true) {
            $group['id'] === ''                                    => 'a field group needs a non-empty "id".',
            $group['location'] === null                            => '"location" must be a post type, a list of post types, or a rule tree.',
            $group['post_types'] === []                            => 'every branch of "location" must pin "post_type" with "equals" or "in".',
            !is_array($raw['display'] ?? [])                       => '"display" must be an array.',
            !in_array($display['position'], self::POSITIONS, true) => '"display.position" must be one of: ' . implode(', ', self::POSITIONS) . '.',
            !is_bool($display['collapsed'])                        => '"display.collapsed" must be a boolean.',
            !is_int($display['order'])                             => '"display.order" must be an integer.',
            default                                                => null,
        };
    }

    /**
     * Report unknown keys and a missing title under WP_DEBUG; they never skip the group.
     */
    private static function lintShape(array $group, array $raw): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $issues  = [];
        $display = is_array($raw['display'] ?? null) ? $raw['display'] : [];

        foreach (array_diff(array_keys($raw), self::KEYS) as $key) {
            $issues[] = sprintf('unknown key "%s".', $key);
        }

        foreach (array_diff(array_keys($display), array_keys(self::DISPLAY_DEFAULTS)) as $key) {
            $issues[] = sprintf('unknown key "display.%s".', $key);
        }

        $untitled = !is_string($raw['title'] ?? null) || $raw['title'] === '';

        if ($untitled && in_array($group['display']['position'], ['meta_box', 'sidebar'], true)) {
            $issues[] = sprintf('a "%s" group needs a "title"; using "%s".', $group['display']['position'], $group['title']);
        }

        foreach ($issues as $issue) {
            self::report($group, 'config issue: ' . $issue);
        }
    }

    /**
     * Register post meta for the post types a late group attached to; `wp_loaded` has already registered the rest.
     */
    private static function registerMeta(array $group): void
    {
        foreach ($group['post_types'] as $postType) {
            if (in_array($group['owner'], array_column(self::$attached[$postType] ?? [], 'owner'), true)) {
                MetaRegistrar::registerFor($postType);
            }
        }
    }

    /**
     * Validate one group and attach it to its post types, releasing what it held before.
     */
    private static function bootGroup(array $group): void
    {
        self::release($group);
        self::lint($group);

        $problem = self::$problems[$group['owner']] ?? self::configProblem($group) ?? self::postTypeProblem($group);

        if ($problem !== null) {
            self::report($group, 'was skipped: ' . $problem);

            return;
        }

        foreach ($group['post_types'] as $postType) {
            self::attach($group, $postType);
        }
    }

    /**
     * Detach a group from every post type and drop its site-wide claims.
     */
    private static function release(array $group): void
    {
        $owner = $group['owner'];

        foreach (self::$attached as $postType => $groups) {
            self::$attached[$postType] = array_values(array_filter($groups, static fn (array $g): bool => $g['owner'] !== $owner));

            if (self::$attached[$postType] === []) {
                unset(self::$attached[$postType]);
            }

            if (($GLOBALS['wireframe_field_group_owners'][$postType] ?? null) === $owner) {
                unset($GLOBALS['wireframe_field_group_owners'][$postType]);
            }
        }
    }

    /**
     * Describe why a group's fields are unusable, or null when they are fine.
     */
    private static function configProblem(array $group): ?string
    {
        $fields = ConfigLoader::flatFields($group['config']);

        if ($fields === []) {
            return 'its config has no fields.';
        }

        foreach ($fields as $path => $field) {
            $type = (string) ($field['type'] ?? '');

            if (in_array($type, self::EXCLUDED_TYPES, true)) {
                return sprintf('field "%s" has type "%s", which is not supported on post screens.', $path, $type);
            }
        }

        return null;
    }

    /**
     * Describe why any of a group's post types cannot take it, or null when all can.
     */
    private static function postTypeProblem(array $group): ?string
    {
        foreach ($group['post_types'] as $postType) {
            $type = get_post_type_object($postType);

            if ($type === null) {
                return sprintf('post type "%s" is not registered.', $postType);
            }

            if (empty($type->show_in_rest)) {
                return sprintf('post type "%s" must be registered with show_in_rest => true.', $postType);
            }

            $problem = self::conflict($group, $postType);

            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    /**
     * Describe a clash with a group already on the post type: a second `content` group, or a shared field id.
     */
    private static function conflict(array $group, string $postType): ?string
    {
        $ids = self::topLevelIds($group);

        foreach (self::$attached[$postType] ?? [] as $other) {
            if ($group['display']['position'] === 'content' && $other['display']['position'] === 'content') {
                return sprintf('post type "%s" already has the content group "%s".', $postType, $other['id']);
            }

            $shared = array_values(array_intersect($ids, self::topLevelIds($other)));

            if ($shared !== []) {
                return sprintf('field "%s" is already used by the group "%s" on post type "%s".', $shared[0], $other['id'], $postType);
            }
        }

        return null;
    }

    /**
     * Get a group's top-level field ids, which are its meta keys.
     *
     * @return list<string>
     */
    private static function topLevelIds(array $group): array
    {
        $keys = array_map('strval', array_keys(ConfigLoader::flatFields($group['config'])));

        return array_values(array_filter($keys, static fn (string $key): bool => !str_contains($key, '.')));
    }

    /**
     * Attach a group to a post type; a `content` group first claims the post type site-wide.
     */
    private static function attach(array $group, string $postType): void
    {
        if ($group['display']['position'] === 'content') {
            $owner = $group['owner'];

            // Two bundled copies can't both replace the canvas; the first copy to boot keeps it.
            if (($GLOBALS['wireframe_field_group_owners'][$postType] ?? $owner) !== $owner) {
                return;
            }

            $GLOBALS['wireframe_field_group_owners'][$postType] = $owner;
            EditorIntegration::lockTemplate($postType);
            self::ensureTitle($group, $postType);
        }

        self::$attached[$postType][] = $group;
        add_post_type_support($postType, 'custom-fields');
    }

    /**
     * Add `title` support to a `content` post type that lacks it, since the block editor won't save a post without one.
     */
    private static function ensureTitle(array $group, string $postType): void
    {
        if (post_type_supports($postType, 'title')) {
            return;
        }

        add_post_type_support($postType, 'title');
        self::debugReport($group, sprintf('added "title" support to post type "%s": the block editor can\'t save a post with no title, excerpt or content.', $postType));
    }

    /**
     * Report config linter findings under WP_DEBUG; they never skip the group.
     */
    private static function lint(array $group): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $config = ConfigLoader::load($group['config']);

        foreach (ConfigValidator::validate($config, $group['config']) as $issue) {
            self::report($group, 'config issue: ' . $issue);
        }
    }

    /**
     * Report a lint finding under WP_DEBUG only.
     */
    private static function debugReport(array $group, string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            self::report($group, $message);
        }
    }

    /**
     * Report a problem with a group through `_doing_it_wrong`.
     */
    private static function report(array $group, string $message): void
    {
        _doing_it_wrong(__CLASS__ . '::boot', sprintf('Field group "%s" (%s) %s', $group['id'], $group['prefix'], $message), '1.2.0');
    }
}
