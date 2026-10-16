<?php

declare(strict_types=1);

namespace Wireframe\PostFields;

use WP_Block;
use WP_Post;
use Wireframe\Meta;

/**
 * The `wireframe/field` block bindings source: shows a public post field in a core block.
 */
final class Bindings
{
    public const SOURCE = 'wireframe/field';

    /** Filter each bundled copy answers for the fields it owns; the copy that registered the source applies it. */
    public const VALUE_FILTER = 'wireframe/field_binding_value';

    private const URL_ATTRIBUTES = ['url', 'href', 'src'];

    /**
     * Answer for this copy's fields and register the source if no copy has, when core has block bindings (WP 6.5+).
     */
    public static function register(): void
    {
        if (!function_exists('register_block_bindings_source')) {
            return;
        }

        add_filter(self::VALUE_FILTER, [self::class, 'answer'], 10, 4);

        if (get_block_bindings_source(self::SOURCE) !== null) {
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
     * @return string|null Null when the post isn't visible to the viewer, so the block keeps its content; blank for a missing, private or unknown key.
     */
    public static function value(array $args, WP_Block $block, string $attribute): ?string
    {
        $postId = (int) ($block->context['postId'] ?? 0);
        $post   = $postId > 0 ? get_post($postId) : null;

        if (!self::visible($post)) {
            return null;
        }

        $key = $args['key'] ?? null;

        if (!is_string($key) || $key === '') {
            return self::refuse('The "key" argument is required.');
        }

        $text = apply_filters(self::VALUE_FILTER, null, $args, $block, $attribute);

        if (is_string($text)) {
            return $text;
        }

        return self::refuse(sprintf('"%s" is not a public field of "%s".', $key, $post->post_type));
    }

    /**
     * Filter `wireframe/field_binding_value`: format a bound key when it is a public field of this copy, viewable here.
     *
     * @param mixed    $text      Text another copy already gave, or null.
     * @param array    $args      Binding args, with a non-empty `key`.
     * @param WP_Block $block     Block being rendered; its post is visible to the viewer.
     * @param string   $attribute Bound attribute.
     * @return mixed The text, or null to leave the key to other copies.
     */
    public static function answer(mixed $text, array $args, WP_Block $block, string $attribute): mixed
    {
        $post = get_post((int) ($block->context['postId'] ?? 0));
        $key  = (string) ($args['key'] ?? '');

        if ($text !== null || !$post instanceof WP_Post || $key === '') {
            return $text;
        }

        $field = self::resolve($post, $key);

        if ($field === null) {
            return null;
        }

        $value = str_contains($key, '.') ? self::cell($post->ID, $key, $field) : Meta::get($post->ID, $key);
        $text  = self::format($field, $value, $attribute, $args['format'] ?? null);

        if (in_array($attribute, self::URL_ATTRIBUTES, true)) {
            return esc_url($text);
        }

        return self::isMarkup($block, $attribute) ? esc_html($text) : $text;
    }

    /**
     * Whether the viewer may see the post's content, as core's `core/post-meta` source decides.
     */
    private static function visible(?WP_Post $post): bool
    {
        if ($post === null) {
            return false;
        }

        if (!is_post_publicly_viewable($post) && !current_user_can('read_post', $post->ID)) {
            return false;
        }

        return !post_password_required($post);
    }

    /**
     * Get the config to format a key with, or null unless the field is public, applies to the post and is viewable by the user.
     *
     * @return array|null Field config; the subfield's for a dot path.
     */
    private static function resolve(WP_Post $post, string $key): ?array
    {
        $fields = FieldGroups::flatFields($post->post_type);
        $parts  = explode('.', $key);
        $top    = $fields[$parts[0]] ?? null;

        if ($top === null || empty($top['public']) || !self::readable($post, $parts[0])) {
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
     * Whether the current user may view a top-level field on a post: its group applies and its access allows it.
     */
    private static function readable(WP_Post $post, string $id): bool
    {
        $group = FieldGroups::groupOfField($post->post_type, $id);
        $user  = wp_get_current_user();

        if ($group === null || !Location::applies($group, $post->ID, $user->exists() ? $user : null)) {
            return false;
        }

        return in_array($id, MetaRegistrar::accessMap($post->post_type, $group, $user)->viewableFieldIds(), true);
    }

    /**
     * Read a dotted key's cell, falling back to the subfield's default when it is empty.
     */
    private static function cell(int $postId, string $key, array $field): mixed
    {
        $value = get_post_meta($postId, $key, true);

        return $value === '' || $value === null ? ($field['default'] ?? '') : $value;
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
