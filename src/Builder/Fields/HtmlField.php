<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

/**
 * Read-only HTML display block (`html`).
 */
final class HtmlField extends Field
{
    protected const TYPE = 'html';

    /**
     * Set the HTML to render. Rendered raw: escape anything user-derived.
     *
     * @param string $content HTML markup.
     * @return static
     */
    public function content(string $content): static
    {
        return $this->arg('content', $content);
    }

    /**
     * Set the notice style.
     *
     * @param string $variant One of `plain`, `info`, `success`, `warning`, `error`.
     * @return static
     */
    public function variant(string $variant): static
    {
        return $this->arg('variant', $variant);
    }
}
