<?php

declare(strict_types=1);

namespace Wireframe\Builder;

use Wireframe\Builder\Concerns\HasCommonKeys;

/**
 * A top-level tab holding sections.
 *
 *   Tab::make('general', 'General')
 *       ->section('main', 'Main', fn (Section $s) => $s->fields(...));
 */
final class Tab extends Node
{
    use HasCommonKeys;

    private function __construct(string $id)
    {
        $this->attributes['id'] = $id;
    }

    /**
     * Start a tab.
     *
     * @param string $id    Unique tab ID.
     * @param string $title Optional tab label.
     * @return self
     */
    public static function make(string $id, string $title = ''): self
    {
        $tab = new self($id);

        return $title !== '' ? $tab->title($title) : $tab;
    }

    /**
     * Hydrate a tab from its config array, keeping every key as-is.
     *
     * @param array $data Tab config.
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $tab = new self((string) ($data['id'] ?? ''));
        $tab->attributes = $data;

        if (is_array($data['sections'] ?? null)) {
            $tab->attributes['sections'] = array_map(
                fn(mixed $section) => is_array($section) ? Section::fromArray($section) : $section,
                $data['sections']
            );
        }

        return $tab;
    }

    /**
     * Add a section, configured by a closure.
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
     * Append pre-built sections.
     *
     * @param Section ...$sections Sections in display order.
     * @return self
     */
    public function addSection(Section ...$sections): self
    {
        return $this->append('sections', $sections);
    }
}
