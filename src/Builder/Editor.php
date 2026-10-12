<?php

declare(strict_types=1);

namespace Wireframe\Builder;

use Wireframe\Builder\Fields\Field;
use Wireframe\Builder\Fields\MissingField;

/**
 * Mutable view of another plugin's normalized config, passed to the
 * `{prefix}/config` filter. Tabs, sections and fields are addressed by `id`;
 * the level is taken from the item passed in (a Tab, Section or Field).
 *
 *   add_filter('my-plugin/config', function (Editor $config) {
 *       $config->after('api_key', PasswordField::make('api_secret')->label('API secret'));
 *       $config->remove('legacy_mode');
 *       return $config;
 *   });
 *
 * A missing target raises `_doing_it_wrong()` under `WP_DEBUG` and is
 * otherwise a no-op.
 */
final class Editor
{
    private const LABELS = ['tabs' => 'tab', 'sections' => 'section', 'fields' => 'field'];

    private const CLASSES = ['tabs' => Tab::class, 'sections' => Section::class, 'fields' => Field::class];

    private function __construct(private Page $page)
    {
    }

    /**
     * Wrap a normalized (tabs → sections → fields) config.
     *
     * @param array $config Normalized config from `ConfigLoader`.
     * @return self
     */
    public static function fromArray(array $config): self
    {
        return new self(Page::fromArray($config));
    }

    /**
     * Compile the edited config back to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->page->toArray();
    }

    /**
     * Whether a tab, section or field with this ID exists.
     *
     * @param string $id Element ID.
     * @return bool
     */
    public function has(string $id): bool
    {
        foreach (array_keys(self::LABELS) as $key) {
            if ($this->locate($key, $id) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get a tab to modify in place. A missing tab returns a detached one.
     *
     * @param string $id Tab ID.
     * @return Tab
     */
    public function tab(string $id): Tab
    {
        return $this->find(__METHOD__, 'tabs', $id) ?? Tab::make($id);
    }

    /**
     * Get a section to modify in place. A missing section returns a detached one.
     *
     * @param string $id Section ID.
     * @return Section
     */
    public function section(string $id): Section
    {
        return $this->find(__METHOD__, 'sections', $id) ?? Section::make($id);
    }

    /**
     * Get a field to modify in place. A missing field returns a detached no-op stand-in.
     *
     * @param string $id Field ID.
     * @return Field
     */
    public function field(string $id): Field
    {
        return $this->find(__METHOD__, 'fields', $id) ?? MissingField::make($id);
    }

    /**
     * Append tabs after the last tab.
     *
     * @param Tab ...$tabs Tabs to add.
     * @return self
     */
    public function addTab(Tab ...$tabs): self
    {
        $this->page->addTab(...$tabs);

        return $this;
    }

    /**
     * Append sections to the end of a tab.
     *
     * @param string  $tabId       Target tab ID.
     * @param Section ...$sections Sections to add.
     * @return self
     */
    public function addSection(string $tabId, Section ...$sections): self
    {
        $this->find(__METHOD__, 'tabs', $tabId)?->addSection(...$sections);

        return $this;
    }

    /**
     * Append fields to the end of a section.
     *
     * @param string $sectionId Target section ID.
     * @param Field  ...$fields Fields to add.
     * @return self
     */
    public function addField(string $sectionId, Field ...$fields): self
    {
        $this->find(__METHOD__, 'sections', $sectionId)?->fields(...$fields);

        return $this;
    }

    /**
     * Insert items before the sibling with this ID.
     *
     * @param string            $id       ID of a tab, section or field matching the items' level.
     * @param Tab|Section|Field ...$items Items to insert, all of one level.
     * @return self
     */
    public function before(string $id, Tab|Section|Field ...$items): self
    {
        return $this->splice(__METHOD__, $id, $items, 0, 0);
    }

    /**
     * Insert items after the sibling with this ID.
     *
     * @param string            $id       ID of a tab, section or field matching the items' level.
     * @param Tab|Section|Field ...$items Items to insert, all of one level.
     * @return self
     */
    public function after(string $id, Tab|Section|Field ...$items): self
    {
        return $this->splice(__METHOD__, $id, $items, 1, 0);
    }

    /**
     * Swap the element with this ID for another of the same level.
     *
     * @param string            $id   ID of the element to replace.
     * @param Tab|Section|Field $item Replacement.
     * @return self
     */
    public function replace(string $id, Tab|Section|Field $item): self
    {
        return $this->splice(__METHOD__, $id, [$item], 0, 1);
    }

    /**
     * Remove the element with this ID, matching fields first, then sections, then tabs.
     *
     * @param string $id Element ID.
     * @return self
     */
    public function remove(string $id): self
    {
        foreach (['fields', 'sections', 'tabs'] as $key) {
            $found = $this->locate($key, $id);

            if ($found !== null) {
                [$parent, $index] = $found;
                $this->replaceChildren($parent, $key, $index, 1, []);

                return $this;
            }
        }

        Debug::warn(__METHOD__, sprintf('No tab, section or field with id "%s" was found; nothing was removed.', $id));

        return $this;
    }

    /**
     * Insert or replace items next to a sibling, at the level of the items given.
     *
     * @param string                  $method Public method name, for the warning.
     * @param string                  $id     Sibling ID.
     * @param list<Tab|Section|Field> $items  Items of one level.
     * @param int                     $shift  0 to land on the sibling's position, 1 to land after it.
     * @param int                     $length Siblings to overwrite (0 inserts, 1 replaces).
     * @return self
     */
    private function splice(string $method, string $id, array $items, int $shift, int $length): self
    {
        if ($items === []) {
            return $this;
        }

        $key = self::levelOf($items[0]);

        foreach ($items as $item) {
            if (self::levelOf($item) !== $key) {
                Debug::warn($method, 'Items passed in one call must all be tabs, all sections, or all fields.');

                return $this;
            }
        }

        $found = $this->locate($key, $id);

        if ($found === null) {
            $this->missing($method, $key, $id);

            return $this;
        }

        [$parent, $index] = $found;
        $this->replaceChildren($parent, $key, $index + $shift, $length, $items);

        return $this;
    }

    /**
     * Find a node by level and ID, warning when it is missing.
     *
     * @param string $method Public method name, for the warning.
     * @param string $key    `tabs`, `sections` or `fields`.
     * @param string $id     Element ID.
     * @return Tab|Section|Field|null
     */
    private function find(string $method, string $key, string $id): ?Node
    {
        $found = $this->locate($key, $id);

        if ($found === null) {
            $this->missing($method, $key, $id);

            return null;
        }

        [$parent, $index] = $found;

        return self::children($parent, $key)[$index];
    }

    /**
     * Locate the first node with this ID at a level.
     *
     * @param string $key `tabs`, `sections` or `fields`.
     * @param string $id  Element ID.
     * @return array{0: Node, 1: int}|null Parent node and the child's position.
     */
    private function locate(string $key, string $id): ?array
    {
        $class = self::CLASSES[$key];

        foreach ($this->parentsOf($key) as $parent) {
            foreach (self::children($parent, $key) as $index => $child) {
                if ($child instanceof $class && $child->id() === $id) {
                    return [$parent, $index];
                }
            }
        }

        return null;
    }

    /**
     * Every node that holds children at a level.
     *
     * @param string $key `tabs`, `sections` or `fields`.
     * @return list<Node>
     */
    private function parentsOf(string $key): array
    {
        if ($key === 'tabs') {
            return [$this->page];
        }

        $tabs = array_filter(self::children($this->page, 'tabs'), fn(mixed $tab) => $tab instanceof Tab);

        if ($key === 'sections') {
            return array_values($tabs);
        }

        $sections = [];

        foreach ($tabs as $tab) {
            foreach (self::children($tab, 'sections') as $section) {
                if ($section instanceof Section) {
                    $sections[] = $section;
                }
            }
        }

        return $sections;
    }

    /**
     * Splice a parent's child list and store it back.
     *
     * @param Node   $parent Parent node.
     * @param string $key    Child list key.
     * @param int    $offset Position to splice at.
     * @param int    $length Children to remove.
     * @param array  $items  Children to insert.
     */
    private function replaceChildren(Node $parent, string $key, int $offset, int $length, array $items): void
    {
        $children = self::children($parent, $key);
        array_splice($children, $offset, $length, $items);
        $parent->set($key, $children);
    }

    /**
     * A parent's child list, re-indexed.
     *
     * @param Node   $parent Parent node.
     * @param string $key    Child list key.
     * @return list<mixed>
     */
    private static function children(Node $parent, string $key): array
    {
        $children = $parent->get($key, []);

        return is_array($children) ? array_values($children) : [];
    }

    /**
     * The child list key an item belongs in.
     *
     * @param Tab|Section|Field $item Item.
     * @return string `tabs`, `sections` or `fields`.
     */
    private static function levelOf(Tab|Section|Field $item): string
    {
        return match (true) {
            $item instanceof Tab     => 'tabs',
            $item instanceof Section => 'sections',
            default                  => 'fields',
        };
    }

    /**
     * Warn that a target was not found.
     *
     * @param string $method Public method name.
     * @param string $key    `tabs`, `sections` or `fields`.
     * @param string $id     Missing ID.
     */
    private function missing(string $method, string $key, string $id): void
    {
        Debug::warn($method, sprintf('No %s with id "%s" was found; nothing was changed.', self::LABELS[$key], $id));
    }
}
