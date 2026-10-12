<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

use Wireframe\Builder\Concerns\HasPlaceholder;
use Wireframe\Builder\Concerns\Copyable;

/**
 * Single-line text input (`text`).
 */
final class TextField extends Field
{
    use HasPlaceholder, Copyable;

    protected const TYPE = 'text';

    /**
     * Keep safe HTML (via `wp_kses_post`) for users with `unfiltered_html`.
     *
     * @param bool $allow Whether HTML is allowed.
     * @return static
     */
    public function allowHtml(bool $allow = true): static
    {
        return $this->arg('allow_html', $allow);
    }
}
