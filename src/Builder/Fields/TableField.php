<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

/**
 * Paginated DataViews table whose rows come from a hook (`table`).
 *
 *   TableField::make('entries')
 *       ->fields(TableColumn::make('name')->label('Name')->sortable())
 *       ->perPage(20)
 *       ->search();
 */
final class TableField extends Field
{
    protected const TYPE = 'table';

    /**
     * Append column definitions.
     *
     * @param TableColumn|array ...$columns Columns in display order.
     * @return static
     */
    public function fields(TableColumn|array ...$columns): static
    {
        return $this->arg('fields', [...($this->attributes['args']['fields'] ?? []), ...$columns]);
    }

    /**
     * Set the legacy row-fetch callable; prefer the `{prefix}/table/{pageId}/{fieldId}/data` hook.
     *
     * @param string $callback Callable name.
     * @return static
     */
    public function dataCallback(string $callback): static
    {
        return $this->arg('data_callback', $callback);
    }

    /**
     * Set the rows shown per page.
     *
     * @param int $perPage Page size.
     * @return static
     */
    public function perPage(int $perPage): static
    {
        return $this->arg('per_page', $perPage);
    }

    /**
     * Show the search box.
     *
     * @param bool $search Whether the table is searchable.
     * @return static
     */
    public function search(bool $search = true): static
    {
        return $this->arg('search', $search);
    }

    /**
     * Set the row and bulk actions.
     *
     * @param list<array> $actions Action definitions (`id`, `label`, `supports_bulk`, `confirm`, …).
     * @return static
     */
    public function actions(array $actions): static
    {
        return $this->arg('actions', $actions);
    }

    /**
     * Enable the single-entry detail view.
     *
     * @param array $detailView Detail settings (`back_label`, legacy `*_callback` keys, …).
     * @return static
     */
    public function detailView(array $detailView): static
    {
        return $this->arg('detail_view', $detailView);
    }
}
