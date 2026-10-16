<?php

declare(strict_types=1);

namespace Wireframe\PostFields;

use Wireframe\Framework\Populate;

/**
 * Renders a post field's value as escaped HTML for an admin list table cell.
 */
final class ColumnRenderer
{
    private const TEXT_LIMIT = 80;

    private const THUMBNAIL = 40;

    /**
     * Get the default cell HTML for a value.
     *
     * @param array $field  Field config.
     * @param mixed $value  Value from `Meta::get()`.
     * @param int   $postId Post the cell belongs to.
     * @return string Escaped HTML; blank for an empty value.
     */
    public static function render(array $field, mixed $value, int $postId): string
    {
        $type = (string) ($field['type'] ?? 'text');

        return match (true) {
            $type === 'file'                            => self::files($value),
            $type === 'toggle', $type === 'checkbox'    => $value ? '✓' : '',
            $type === 'color'                           => self::color($value),
            $type === 'password'                        => self::masked($value),
            self::linksPosts($field, $type)             => self::postLinks($field, $value),
            default                                     => esc_html(self::text($field, $value)),
        };
    }

    /**
     * Get a value as plain, unescaped text; free text is cut to a one-line excerpt.
     */
    private static function text(array $field, mixed $value): string
    {
        $type = (string) ($field['type'] ?? 'text');

        if ($type === 'repeater' || in_array($type, ValueFormatter::CHOICE_TYPES, true)) {
            return ValueFormatter::text($field, $value);
        }

        if ($type === 'date' || $type === 'time') {
            return self::excerpt(ValueFormatter::text($field, $value));
        }

        return self::excerpt($value);
    }

    /**
     * Flatten a scalar, or a list of them, to one line of at most 80 characters.
     */
    private static function excerpt(mixed $value): string
    {
        $parts = array_filter((array) $value, 'is_scalar');
        $text  = trim((string) preg_replace('/\s+/u', ' ', implode(', ', array_map('strval', $parts))));

        if (mb_strlen($text) <= self::TEXT_LIMIT) {
            return $text;
        }

        return mb_substr($text, 0, self::TEXT_LIMIT - 1) . '…';
    }

    /**
     * Whether a choice field's values are post IDs from a `populate` query on posts.
     */
    private static function linksPosts(array $field, string $type): bool
    {
        if (!in_array($type, ValueFormatter::CHOICE_TYPES, true) || !Populate::isPopulated($field)) {
            return false;
        }

        $populate = $field['args']['populate'];

        return ($populate['type'] ?? 'query') === 'query'
            && ($populate['query'] ?? 'posts') === 'posts'
            && ($populate['return_format']['value'] ?? 'ID') === 'ID';
    }

    /**
     * Link the titles of the selected posts the current user may read.
     */
    private static function postLinks(array $field, mixed $value): string
    {
        $static = is_array($field['args']['options'] ?? null) ? $field['args']['options'] : [];
        $links  = [];

        foreach (Populate::valuesOf($value) as $item) {
            if (isset($static[$item])) {
                $links[] = esc_html(ValueFormatter::labelOf($static[$item]));

                continue;
            }

            $postId = (int) $item;

            if (!current_user_can('read_post', $postId)) {
                continue;
            }

            $url     = get_edit_post_link($postId) ?: get_permalink($postId);
            $title   = get_the_title($postId);
            $links[] = $url
                ? sprintf('<a href="%s">%s</a>', esc_url($url), esc_html($title))
                : esc_html($title);
        }

        return implode(', ', $links);
    }

    /**
     * Render a file field's first attachment as a thumbnail, with "+N" for the others.
     */
    private static function files(mixed $value): string
    {
        $ids = array_values(array_filter(array_map('intval', (array) $value)));

        if ($ids === []) {
            return '';
        }

        $size  = self::THUMBNAIL;
        $first = wp_get_attachment_image($ids[0], [$size, $size], !wp_attachment_is_image($ids[0]), [
            'style' => "width:{$size}px;height:{$size}px;object-fit:cover",
        ]);
        $more  = count($ids) - 1;

        return $more > 0 ? "$first +$more" : $first;
    }

    /**
     * Render a colour swatch with its hex value; anything but a hex colour is shown as text.
     */
    private static function color(mixed $value): string
    {
        $hex = is_string($value) ? sanitize_hex_color($value) : null;

        if ($hex === null) {
            return esc_html(self::excerpt($value));
        }

        return sprintf(
            '<span style="display:inline-block;width:12px;height:12px;margin-right:6px;vertical-align:middle;border:1px solid rgba(0,0,0,.2);background:%1$s"></span>%1$s',
            esc_attr($hex)
        );
    }

    /**
     * Show that a password is set without printing it.
     */
    private static function masked(mixed $value): string
    {
        return is_string($value) && $value !== '' ? '••••••' : '';
    }
}
