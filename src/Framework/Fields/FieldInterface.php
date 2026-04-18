<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

/**
 * Contract for a field type handler.
 *
 * Each field type provides its own default validation rules,
 * sanitization logic, and structural validation.
 */
interface FieldInterface
{
    /**
     * The field type slug (e.g. 'text', 'email', 'select').
     */
    public static function type(): string;

    /**
     * Default Rakit validation rules for this field type.
     *
     * Return a pipe-separated string (e.g. 'email') or empty string.
     * These are merged with any developer-declared `args.validation`.
     */
    public static function defaultRules(array $args): string;

    /**
     * Sanitize the value for storage.
     */
    public static function sanitize(mixed $value, array $args): mixed;

    /**
     * Structural validation beyond Rakit rules.
     *
     * Return null if valid, or an error message string.
     */
    public static function validate(mixed $value, array $args): ?string;

    /**
     * Whether this field stores a value (false for html, export, import, table).
     */
    public static function isStateless(): bool;
}
