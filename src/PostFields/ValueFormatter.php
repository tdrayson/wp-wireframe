<?php

declare(strict_types=1);

namespace Wireframe\PostFields;

use DateTimeZone;
use Wireframe\Framework\Populate;

/**
 * Formats a post field's value as plain, unescaped text, shared by admin columns and block bindings.
 */
final class ValueFormatter
{
    public const CHOICE_TYPES = ['select', 'radio', 'checkboxes', 'image_radio', 'image_checkboxes'];

    /**
     * Get a value as plain text.
     *
     * @param array  $field     Field config.
     * @param mixed  $value     Field value.
     * @param string $attribute Block attribute the text is for; `url` makes a file its bare attachment URL.
     * @return string Unescaped text; blank for an empty value.
     */
    public static function text(array $field, mixed $value, string $attribute = 'content'): string
    {
        $type = (string) ($field['type'] ?? 'text');

        return match (true) {
            $type === 'date', $type === 'time'        => self::moment($type, $value),
            $type === 'repeater'                      => self::rows($value),
            $type === 'toggle', $type === 'checkbox'  => self::yesNo($value),
            $type === 'file'                          => self::urls($value, $attribute),
            in_array($type, self::CHOICE_TYPES, true) => implode(', ', self::labels($field, $value)),
            default                                   => self::joined($value),
        };
    }

    /**
     * Format a stored date (`Y-m-d`) or time (`H:i`) with the site's format, or a given PHP date format.
     *
     * @param string      $type   `date` or `time`.
     * @param mixed       $value  Stored value.
     * @param string|null $format PHP date format; the site's format when null.
     */
    public static function moment(string $type, mixed $value, ?string $format = null): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        $timestamp = strtotime($type === 'date' ? "$value 00:00:00 UTC" : "1970-01-01 $value UTC");

        if ($timestamp === false) {
            return $value;
        }

        $format ??= (string) get_option($type === 'date' ? 'date_format' : 'time_format');

        return wp_date($format, $timestamp, new DateTimeZone('UTC')) ?: $value;
    }

    /**
     * Get the labels of a choice field's selected values, populated options included.
     *
     * @return list<string>
     */
    public static function labels(array $field, mixed $value): array
    {
        $selected = Populate::valuesOf($value);

        if ($selected === []) {
            return [];
        }

        $options = self::options($field, $selected);
        $labels  = [];

        foreach ($selected as $item) {
            $labels[] = self::labelOf($options[$item] ?? $item);
        }

        return $labels;
    }

    /**
     * Get an option's label, which image fields may nest under `label`.
     */
    public static function labelOf(mixed $option): string
    {
        if (is_array($option)) {
            $option = $option['label'] ?? '';
        }

        return is_scalar($option) ? (string) $option : '';
    }

    /**
     * Join a scalar, or a list of them, with commas.
     */
    private static function joined(mixed $value): string
    {
        $parts = array_filter((array) $value, 'is_scalar');

        return implode(', ', array_map('strval', $parts));
    }

    /**
     * Count a repeater's rows as "N rows".
     */
    private static function rows(mixed $value): string
    {
        $count = is_array($value) ? count($value) : 0;

        if ($count === 0) {
            return '';
        }

        return sprintf(_n('%d row', '%d rows', $count, 'wp-wireframe'), $count);
    }

    /**
     * Get "Yes" or "No" for a toggle's value.
     */
    private static function yesNo(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN)
            ? __('Yes', 'wp-wireframe')
            : __('No', 'wp-wireframe');
    }

    /**
     * Get the URLs of a file field's attachments: the first alone for a `url` attribute, else comma-joined.
     */
    private static function urls(mixed $value, string $attribute): string
    {
        $ids  = array_values(array_filter(array_map('intval', (array) $value)));
        $urls = array_values(array_filter(array_map(static fn (int $id): string => (string) wp_get_attachment_url($id), $ids)));

        if ($attribute === 'url') {
            return $urls[0] ?? '';
        }

        return implode(', ', $urls);
    }

    /**
     * Get a choice field's `value => label` options, resolving only the given values when populated.
     *
     * @param list<int|string> $selected
     */
    private static function options(array $field, array $selected): array
    {
        $options = Populate::resolve($field, $selected)['args']['options'] ?? [];

        return is_array($options) ? $options : [];
    }
}
