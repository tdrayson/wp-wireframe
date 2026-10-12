<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

use Wireframe\Builder\Concerns\HasRows;

/**
 * TinyMCE WYSIWYG editor (`editor`).
 */
final class EditorField extends Field
{
    use HasRows;

    protected const TYPE = 'editor';

    /**
     * Show the Add Media button.
     *
     * @param bool $show Whether the button is shown.
     * @return static
     */
    public function mediaButtons(bool $show = true): static
    {
        return $this->arg('media_buttons', $show);
    }

    /**
     * Auto-wrap paragraphs in the Visual tab.
     *
     * @param bool $wpautop Whether paragraphs are added automatically.
     * @return static
     */
    public function wpautop(bool $wpautop = true): static
    {
        return $this->arg('wpautop', $wpautop);
    }

    /**
     * Configure the Visual tab, merged over WordPress's defaults.
     *
     * @param array|bool $settings TinyMCE settings (e.g. `toolbar1`), or `false` to disable the tab.
     * @return static
     */
    public function tinymce(array|bool $settings): static
    {
        return $this->arg('tinymce', $settings);
    }

    /**
     * Configure the Text tab, merged over WordPress's defaults.
     *
     * @param array|bool $settings Quicktags settings (e.g. `buttons`), or `false` to disable the tab.
     * @return static
     */
    public function quicktags(array|bool $settings): static
    {
        return $this->arg('quicktags', $settings);
    }
}
