<?php

declare(strict_types=1);

namespace Wireframe\Builder\Concerns;

/**
 * Keys shared by tabs, sections and fields.
 */
trait HasCommonKeys
{
    /**
     * Set the title.
     *
     * @param string $title Title text.
     * @return static
     */
    public function title(string $title): static
    {
        return $this->set('title', $title);
    }

    /**
     * Set the help text shown under the heading or field.
     *
     * @param string $description Description text; fields support `{field_id}` tokens.
     * @return static
     */
    public function description(string $description): static
    {
        return $this->set('description', $description);
    }

    /**
     * Restrict who can view or edit this element.
     *
     * @param string|list<string>|array{view?: string|list<string>, edit?: string|list<string>} $access
     *        Role slug or capability, a list of them (OR), or a `view`/`edit` split.
     * @return static
     */
    public function access(string|array $access): static
    {
        return $this->set('access', $access);
    }

    /**
     * Show this element only when the conditions pass.
     *
     * @param array $conditions Conditions DSL node, e.g. `['all' => [['field' => 'x', 'operator' => 'truthy']]]`.
     * @return static
     */
    public function conditions(array $conditions): static
    {
        return $this->set('conditions', $conditions);
    }
}
