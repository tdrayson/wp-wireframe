<?php

declare(strict_types=1);

namespace Wireframe\Builder;

use Wireframe\Builder\Concerns\HasCommonKeys;
use Wireframe\Builder\Fields\Field;

/**
 * A titled group of fields inside a tab.
 *
 *   Section::make('main', 'Main settings')
 *       ->description('Configure the basics.')
 *       ->fields(TextField::make('site_name')->label('Site name'));
 */
final class Section extends Node
{
    use HasCommonKeys;

    private function __construct(string $id)
    {
        $this->attributes['id'] = $id;
    }

    /**
     * Start a section.
     *
     * @param string $id    Unique section ID.
     * @param string $title Optional heading.
     * @return self
     */
    public static function make(string $id, string $title = ''): self
    {
        $section = new self($id);

        return $title !== '' ? $section->title($title) : $section;
    }

    /**
     * Hydrate a section from its config array, keeping every key as-is.
     *
     * @param array $data Section config.
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $section = new self((string) ($data['id'] ?? ''));
        $section->attributes = $data;

        if (is_array($data['fields'] ?? null)) {
            $section->attributes['fields'] = array_map(
                fn(mixed $field) => is_array($field) ? Field::fromArray($field) : $field,
                $data['fields']
            );
        }

        return $section;
    }

    /**
     * Append fields to the section.
     *
     * @param Field ...$fields Fields in display order.
     * @return self
     */
    public function fields(Field ...$fields): self
    {
        return $this->append('fields', $fields);
    }
}
