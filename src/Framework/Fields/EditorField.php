<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

/**
 * WYSIWYG editor field.
 *
 * Rendered client-side via `wp.editor.initialize()`, which consumes only the
 * native editor settings below. Configure through the field's `args`:
 *
 *   'args' => [
 *       'rows'          => 8,        // textarea height (Text tab / fallback)
 *       'media_buttons' => false,    // hide the "Add Media" button
 *       'wpautop'       => true,     // auto-paragraphs in the Visual tab
 *       'tinymce'       => [         // Visual tab; `false` disables it
 *           'toolbar1' => 'bold italic | bullist numlist | link',
 *       ],
 *       'quicktags'     => [         // Text tab; `false` disables it
 *           'buttons' => 'strong,em,link',
 *       ],
 *   ]
 *
 * `tinymce` / `quicktags` are merged over `wp.editor.getDefaultSettings()`, so
 * partial configs inherit WP's defaults. The high-level PHP `wp_editor()` args
 * (teeny, editor_height, textarea_rows, drag_drop_upload, …) are NOT honored —
 * the client initializer never reads them; use TinyMCE-native keys instead.
 */
class EditorField extends BaseField
{
    public static function type(): string
    {
        return 'editor';
    }

    public static function sanitize(mixed $value, array $args): mixed
    {
        return is_string($value) ? wp_kses_post($value) : '';
    }
}
