<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

use Wireframe\Builder\Node;

/**
 * A column in a `table` field. Compiles to a DataViews field descriptor,
 * not a settings field, so it has its own small set of keys.
 *
 *   TableColumn::make('name')->label('Name')->sortable()->searchable();
 */
final class TableColumn extends Node
{
    private function __construct(string $id)
    {
        $this->attributes['id'] = $id;
    }

    /**
     * Start a column.
     *
     * @param string $id Key of the value in each row.
     * @return self
     */
    public static function make(string $id): self
    {
        return new self($id);
    }

    /**
     * Set the column heading.
     *
     * @param string $label Heading text.
     * @return self
     */
    public function label(string $label): self
    {
        return $this->set('label', $label);
    }

    /**
     * Set the DataViews value type.
     *
     * @param string $type e.g. `text`, `integer`, `datetime`.
     * @return self
     */
    public function type(string $type): self
    {
        return $this->set('type', $type);
    }

    /**
     * Allow sorting by this column.
     *
     * @param bool $sortable Whether the column is sortable.
     * @return self
     */
    public function sortable(bool $sortable = true): self
    {
        return $this->set('sortable', $sortable);
    }

    /**
     * Include this column in the table search.
     *
     * @param bool $searchable Whether the column is searched.
     * @return self
     */
    public function searchable(bool $searchable = true): self
    {
        return $this->set('searchable', $searchable);
    }

    /**
     * Map stored values to display labels.
     *
     * @param array<string, string> $elements Value → label.
     * @return self
     */
    public function elements(array $elements): self
    {
        return $this->set('elements', $elements);
    }
}
