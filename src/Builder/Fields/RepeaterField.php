<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

/**
 * Rows of subfields that can be added, removed and reordered (`repeater`).
 *
 *   RepeaterField::make('redirects')
 *       ->subfields(TextField::make('from'), TextField::make('to'))
 *       ->sortable()
 *       ->titleTemplate('{from} → {to}');
 */
final class RepeaterField extends Field
{
    protected const TYPE = 'repeater';

    /**
     * Append the fields that make up each row.
     *
     * @param Field ...$fields Subfields in display order.
     * @return static
     */
    public function subfields(Field ...$fields): static
    {
        return $this->arg('subfields', [...($this->attributes['args']['subfields'] ?? []), ...$fields]);
    }

    /**
     * Allow drag-and-drop reordering.
     *
     * @param bool $sortable Whether rows can be reordered.
     * @return static
     */
    public function sortable(bool $sortable = true): static
    {
        return $this->arg('sortable', $sortable);
    }

    /**
     * Let rows collapse to their title.
     *
     * @param bool $collapsible Whether rows can collapse.
     * @return static
     */
    public function collapsible(bool $collapsible = true): static
    {
        return $this->arg('collapsible', $collapsible);
    }

    /**
     * Start rows collapsed.
     *
     * @param bool $collapsed Whether rows render collapsed.
     * @return static
     */
    public function collapsed(bool $collapsed = true): static
    {
        return $this->arg('collapsed', $collapsed);
    }

    /**
     * Show a duplicate-row button.
     *
     * @param bool $duplicate Whether rows can be duplicated.
     * @return static
     */
    public function duplicateRow(bool $duplicate = true): static
    {
        return $this->arg('duplicate_row', $duplicate);
    }

    /**
     * Show the add-row button.
     *
     * @param bool $add Whether rows can be added.
     * @return static
     */
    public function addRow(bool $add = true): static
    {
        return $this->arg('add_row', $add);
    }

    /**
     * Show the delete-row button.
     *
     * @param bool $delete Whether rows can be deleted.
     * @return static
     */
    public function deleteRow(bool $delete = true): static
    {
        return $this->arg('delete_row', $delete);
    }

    /**
     * Set the fewest rows allowed.
     *
     * @param int $min Minimum row count.
     * @return static
     */
    public function minRows(int $min): static
    {
        return $this->arg('min_rows', $min);
    }

    /**
     * Set the most rows allowed.
     *
     * @param int $max Maximum row count.
     * @return static
     */
    public function maxRows(int $max): static
    {
        return $this->arg('max_rows', $max);
    }

    /**
     * Set the add-row button text.
     *
     * @param string $label Button label.
     * @return static
     */
    public function addLabel(string $label): static
    {
        return $this->arg('add_label', $label);
    }

    /**
     * Set the text shown when there are no rows.
     *
     * @param string $message Empty-state text.
     * @return static
     */
    public function emptyMessage(string $message): static
    {
        return $this->arg('empty_message', $message);
    }

    /**
     * Set the row title, with `{subfield_id}` tokens for live values.
     *
     * @param string $template Title template, e.g. `{from} → {status}`.
     * @return static
     */
    public function titleTemplate(string $template): static
    {
        return $this->arg('title_template', $template);
    }
}
