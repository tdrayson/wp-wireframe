<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

use Wireframe\Builder\Concerns\HasButtonLabel;

/**
 * One or more buttons that dispatch to a server-side hook (`action`).
 *
 * Without `button()`/`buttons()` the field is a single button whose hook
 * is `{prefix}/action/{pageId}/{fieldId}/run`.
 */
final class ActionField extends Field
{
    use HasButtonLabel;

    protected const TYPE = 'action';

    /**
     * Set the single button's style.
     *
     * @param string $variant e.g. `primary`, `secondary`, `tertiary`.
     * @return static
     */
    public function variant(string $variant): static
    {
        return $this->arg('variant', $variant);
    }

    /**
     * Require confirmation before the single button fires.
     *
     * @param string|array $confirm Message, or `message`/`title`/`button_label`/`cancel_label`.
     * @return static
     */
    public function confirm(string|array $confirm): static
    {
        return $this->arg('confirm', $confirm);
    }

    /**
     * Style the single button as destructive.
     *
     * @param bool $destructive Whether the button is destructive.
     * @return static
     */
    public function destructive(bool $destructive = true): static
    {
        return $this->arg('destructive', $destructive);
    }

    /**
     * Keep a blank label row so an unlabelled button aligns with labelled neighbours.
     *
     * @param bool $spacer Whether the spacer is rendered.
     * @return static
     */
    public function labelSpacer(bool $spacer = true): static
    {
        return $this->arg('label_spacer', $spacer);
    }

    /**
     * Append a button to the group. Its hook ends in `/{id}`.
     *
     * @param string $id      Button ID.
     * @param string $label   Button text.
     * @param array  $options Extra keys: `variant`, `destructive`, `confirm`.
     * @return static
     */
    public function button(string $id, string $label, array $options = []): static
    {
        return $this->buttons([...($this->attributes['args']['buttons'] ?? []), ['id' => $id, 'label' => $label] + $options]);
    }

    /**
     * Replace the button group.
     *
     * @param list<array> $buttons Button definitions (`id`, `label`, `variant`, `destructive`, `confirm`).
     * @return static
     */
    public function buttons(array $buttons): static
    {
        return $this->arg('buttons', $buttons);
    }
}
