<?php

declare(strict_types=1);

namespace Wireframe\Builder;

use Wireframe\Builder\Fields\Field;

/**
 * Fluent builder for a page config. Pass it to `App::boot()` (or return it
 * from a config file) anywhere a config array is accepted.
 *
 * Use one of the three config formats: `tab()` for tabs, `section()` for
 * sections only, or `fields()` for a single card of fields.
 *
 *   Page::make()
 *       ->title('My Plugin')
 *       ->tab('general', 'General', fn (Tab $tab) => $tab
 *           ->section('main', 'Main', fn (Section $s) => $s
 *               ->fields(TextField::make('site_name')->label('Site name'))));
 */
final class Page extends Node
{
    private function __construct()
    {
    }

    /**
     * Start an empty page config.
     *
     * @return self
     */
    public static function make(): self
    {
        return new self();
    }

    /**
     * Hydrate a page from a config array in any of the three formats.
     *
     * @param array $data Page config.
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $page = new self();
        $page->attributes = $data;

        $hydrators = [
            'tabs'     => fn(array $item) => Tab::fromArray($item),
            'sections' => fn(array $item) => Section::fromArray($item),
            'fields'   => fn(array $item) => Field::fromArray($item),
        ];

        foreach ($hydrators as $key => $hydrate) {
            if (is_array($data[$key] ?? null)) {
                $page->attributes[$key] = array_map(
                    fn(mixed $item) => is_array($item) ? $hydrate($item) : $item,
                    $data[$key]
                );
            }
        }

        return $page;
    }

    /**
     * Set the title rendered in the page header.
     *
     * @param string $title Page title.
     * @return self
     */
    public function title(string $title): self
    {
        return $this->set('title', $title);
    }

    /**
     * Set the subtitle rendered under the page title.
     *
     * @param string $subtitle Page subtitle.
     * @return self
     */
    public function subtitle(string $subtitle): self
    {
        return $this->set('subtitle', $subtitle);
    }

    /**
     * Add a tab, configured by a closure.
     *
     * @param string              $id    Unique tab ID.
     * @param string              $title Tab label.
     * @param callable(Tab): mixed $build Receives the new tab.
     * @return self
     */
    public function tab(string $id, string $title, callable $build): self
    {
        $tab = Tab::make($id, $title);
        $build($tab);

        return $this->addTab($tab);
    }

    /**
     * Append pre-built tabs.
     *
     * @param Tab ...$tabs Tabs in display order.
     * @return self
     */
    public function addTab(Tab ...$tabs): self
    {
        return $this->append('tabs', $tabs);
    }

    /**
     * Add a section without tabs (sections-only format), configured by a closure.
     *
     * @param string                  $id    Unique section ID.
     * @param string                  $title Section heading.
     * @param callable(Section): mixed $build Receives the new section.
     * @return self
     */
    public function section(string $id, string $title, callable $build): self
    {
        $section = Section::make($id, $title);
        $build($section);

        return $this->addSection($section);
    }

    /**
     * Append pre-built sections (sections-only format).
     *
     * @param Section ...$sections Sections in display order.
     * @return self
     */
    public function addSection(Section ...$sections): self
    {
        return $this->append('sections', $sections);
    }

    /**
     * Append fields without tabs or sections (fields-only format).
     *
     * @param Field ...$fields Fields in display order.
     * @return self
     */
    public function fields(Field ...$fields): self
    {
        return $this->append('fields', $fields);
    }

    /**
     * Compile to a config array. Warns under `WP_DEBUG` when formats are mixed.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $formats = array_intersect(['tabs', 'sections', 'fields'], array_keys($this->attributes));

        if (count($formats) > 1) {
            Debug::warn(
                __METHOD__,
                sprintf('A page config uses more than one format (%s); only the first will render.', implode(', ', $formats))
            );
        }

        return parent::toArray();
    }
}
