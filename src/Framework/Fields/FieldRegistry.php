<?php

declare(strict_types=1);

namespace Wireframe\Framework\Fields;

/**
 * Registry mapping field type slugs to their handler classes.
 *
 * Shared across every consuming plugin. Extensible via the
 * `wp-wireframe/field_types` filter.
 */
final class FieldRegistry
{
    private static ?self $instance = null;

    /** @var array<string, class-string<FieldInterface>> */
    private array $types = [];

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->registerDefaults();

        /**
         * Allow plugins to register custom field types.
         *
         * @param array<string, class-string<FieldInterface>> $types
         */
        $filtered = apply_filters('wp-wireframe/field_types', $this->types);

        // Validate that filter result is an array and all handlers implement FieldInterface.
        if (is_array($filtered)) {
            foreach ($filtered as $slug => $class) {
                if (is_string($class) && is_subclass_of($class, FieldInterface::class)) {
                    $this->types[$slug] = $class;
                }
            }
        }
    }

    /**
     * Get the handler class for a field type.
     *
     * Falls back to TextField for unknown types.
     *
     * @return class-string<FieldInterface>
     */
    public function get(string $type): string
    {
        return $this->types[$type] ?? TextField::class;
    }

    /**
     * Register a custom field type.
     *
     * @param class-string<FieldInterface> $class
     */
    public function register(string $type, string $class): void
    {
        $this->types[$type] = $class;
    }

    /**
     * Get all registered types.
     *
     * @return array<string, class-string<FieldInterface>>
     */
    public function all(): array
    {
        return $this->types;
    }

    private function registerDefaults(): void
    {
        $this->types = [
            'text'              => TextField::class,
            'textarea'          => TextareaField::class,
            'email'             => EmailField::class,
            'url'               => UrlField::class,
            'password'          => PasswordField::class,
            'hidden'            => HiddenField::class,
            'number'            => NumberField::class,
            'range'             => RangeField::class,
            'select'            => SelectField::class,
            'radio'             => RadioField::class,
            'image_radio'       => ImageRadioField::class,
            'checkboxes'        => CheckboxesField::class,
            'image_checkboxes'  => ImageCheckboxesField::class,
            'toggle'            => ToggleField::class,
            'checkbox'          => CheckboxField::class,
            'color'             => ColorField::class,
            'date'              => DateField::class,
            'time'              => TimeField::class,
            'editor'            => EditorField::class,
            'code_editor'       => CodeEditorField::class,
            'file'              => FileField::class,
            'repeater'          => RepeaterField::class,
            'html'              => HtmlField::class,
            'export'            => ExportField::class,
            'import'            => ImportField::class,
            'table'             => TableField::class,
        ];
    }
}
