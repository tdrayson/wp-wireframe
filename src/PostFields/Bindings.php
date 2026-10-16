<?php

declare(strict_types=1);

namespace Wireframe\PostFields;

use WP_Block;
use Wireframe\Meta;

/**
 * The `wireframe/field` block bindings source: shows a public post field in a core block.
 */
final class Bindings
{
    public const SOURCE = 'wireframe/field';

    /**
     * Register the source, when core has block bindings (WP 6.5+).
     */
    public static function register(): void
    {
        if (!function_exists('register_block_bindings_source') || get_block_bindings_source(self::SOURCE) !== null) {
            return;
        }

        register_block_bindings_source(self::SOURCE, [
            'label'              => __('Wireframe field', 'wp-wireframe'),
            'get_value_callback' => [self::class, 'value'],
            'uses_context'       => ['postId', 'postType'],
        ]);
    }

    /**
     * Get a public field's value as text for a bound block attribute.
     *
     * @param array    $args      Binding args: `key` (field id or dot path), optional `format` (PHP date format, dates only).
     * @param WP_Block $block     Block being rendered.
     * @param string   $attribute Bound attribute.
     * @return string Blank when the key is missing, private or unknown.
     */
    public static function value(array $args, WP_Block $block, string $attribute): ?string
    {
        $key    = $args['key'] ?? null;
        $postId = (int) ($block->context['postId'] ?? get_the_ID());

        if ($postId <= 0) {
            return '';
        }

        if (!is_string($key) || $key === '') {
            return self::refuse('The "key" argument is required.');
        }

        $postType = (string) ($block->context['postType'] ?? get_post_type($postId));
        $field    = self::resolve($postType, $key);

        if ($field === null) {
            return self::refuse(sprintf('"%s" is not a public field of "%s".', $key, $postType));
        }

        $value = str_contains($key, '.') ? get_post_meta($postId, $key, true) : Meta::get($postId, $key);
        $text  = self::format($field, $value, $attribute, $args['format'] ?? null);

        return self::isMarkup($block, $attribute) ? esc_html($text) : $text;
    }

    /**
     * Get the config to format a key with, or null unless its top-level field is public and the key names a real field.
     *
     * @return array|null Field config; the subfield's for a dot path.
     */
    private static function resolve(string $postType, string $key): ?array
    {
        $fields = FieldGroups::flatFields($postType);
        $parts  = explode('.', $key);
        $top    = $fields[$parts[0]] ?? null;

        if ($top === null || empty($top['public'])) {
            return null;
        }

        if (count($parts) === 1) {
            return $top;
        }

        if (count($parts) > 3) {
            return null;
        }

        return $fields[$parts[0] . '.' . end($parts)] ?? null;
    }

    /**
     * Format a value, honouring a custom PHP date format on date fields.
     */
    private static function format(array $field, mixed $value, string $attribute, mixed $format): string
    {
        if (($field['type'] ?? '') === 'date' && is_string($format) && $format !== '') {
            return ValueFormatter::moment('date', $value, $format);
        }

        return ValueFormatter::text($field, $value, $attribute);
    }

    /**
     * Whether the attribute is HTML in the block's markup, so plain text must be escaped for it.
     */
    private static function isMarkup(WP_Block $block, string $attribute): bool
    {
        $source = $block->block_type->attributes[$attribute]['source'] ?? '';

        return in_array($source, ['html', 'rich-text'], true);
    }

    /**
     * Render empty for an unusable binding, flagging it under WP_DEBUG.
     */
    private static function refuse(string $message): string
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            _doing_it_wrong(self::class . '::value', esc_html($message), '1.2.0');
        }

        return '';
    }
}
