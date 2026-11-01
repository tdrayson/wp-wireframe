<?php

declare(strict_types=1);

namespace Wireframe\PostFields;

use Wireframe\Framework\Fields\FieldRegistry;

/**
 * Classifies a field by how its value is stored in post meta.
 */
final class StorageKind
{
    /** One meta row holding one value. */
    public const SCALAR = 'scalar';

    /** One meta row per selected value, same key. */
    public const MULTI = 'multi';

    /** One meta row holding an ordered array of IDs. */
    public const LIST = 'list';

    /** One meta row holding an array of row arrays. */
    public const REPEATER = 'repeater';

    /** Not stored. */
    public const NONE = 'none';

    /**
     * Get the storage kind of a field.
     *
     * @param array $field Field config.
     * @return string One of the class constants.
     */
    public static function for(array $field): string
    {
        $type     = (string) ($field['type'] ?? 'text');
        $multiple = !empty($field['args']['multiple']);

        if (FieldRegistry::instance()->get($type)::isStateless()) {
            return self::NONE;
        }

        if ($type === 'repeater') {
            return self::REPEATER;
        }

        if ($type === 'file') {
            return $multiple ? self::LIST : self::SCALAR;
        }

        if (in_array($type, ['checkboxes', 'image_checkboxes'], true) || ($type === 'select' && $multiple)) {
            return self::MULTI;
        }

        return self::SCALAR;
    }
}
