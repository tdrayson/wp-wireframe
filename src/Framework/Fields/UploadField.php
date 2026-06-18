<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

/**
 * Local (temporary) file upload field.
 *
 * Holds a file entirely client-side as a base64 data URL and ships it inside
 * the action payload (POST `values`). The file is NEVER uploaded to the media
 * library and NEVER persisted — the field is stateless, so it is excluded from
 * Save but still travels with action requests (see Sanitizer's `includeStateless`).
 *
 * Pair it with an `action` field whose handler decodes and processes the file,
 * e.g. a CSV import:
 *
 *   $values['my_upload']  // ['name' => 'data.csv', 'type' => 'text/csv',
 *                         //  'size' => 1234, 'content' => 'data:text/csv;base64,…']
 *
 *   [ $meta, $base64 ] = explode( ',', $values['my_upload']['content'], 2 );
 *   $raw = base64_decode( $base64 );
 *
 * Config:
 *   'args' => [
 *       'accept'   => '.csv,text/csv',  // <input> accept filter
 *       'max_size' => 5 * 1024 * 1024,  // bytes; larger files are rejected (default 5 MB)
 *   ]
 */
class UploadField extends BaseField
{
    /** Default maximum decoded file size: 5 MB. */
    private const DEFAULT_MAX_SIZE = 5242880;

    public static function type(): string
    {
        return 'upload';
    }

    /**
     * Transient input: passed to actions, never written to options or media.
     */
    public static function isStateless(): bool
    {
        return true;
    }

    public static function sanitize(mixed $value, array $args): mixed
    {
        if (!is_array($value)) {
            return null;
        }

        $content = is_string($value['content'] ?? null) ? $value['content'] : '';

        // Only accept base64 data URLs; reject anything else.
        if ($content === '' || !preg_match('#^data:[^,]*;base64,#i', $content)) {
            return null;
        }

        // Enforce the size cap against the *actual* decoded length, not the
        // client-reported size (which can't be trusted).
        $base64     = substr($content, strpos($content, ',') + 1);
        $actualSize = (int) (strlen($base64) * 3 / 4);
        $maxSize    = isset($args['max_size']) ? (int) $args['max_size'] : self::DEFAULT_MAX_SIZE;

        if ($maxSize > 0 && $actualSize > $maxSize) {
            return null;
        }

        return [
            'name'    => isset($value['name']) ? sanitize_file_name((string) $value['name']) : '',
            'type'    => isset($value['type']) ? sanitize_mime_type((string) $value['type']) : '',
            'size'    => $actualSize,
            'content' => $content,
        ];
    }
}
