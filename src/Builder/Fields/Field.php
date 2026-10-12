<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

use Wireframe\Builder\Concerns\HasCommonKeys;
use Wireframe\Builder\Node;

/**
 * Base for every field builder. Holds the keys common to all field types;
 * subclasses add fluent setters for their type's `args`.
 *
 *   TextField::make('api_key')->label('API key')->placeholder('sk-...')->columns(6);
 */
abstract class Field extends Node
{
    use HasCommonKeys;

    /** Field type slug; overridden by each subclass. */
    protected const TYPE = '';

    /** @var array<string, class-string<Field>> Built-in type slug → builder class. */
    private const CLASSES = [
        'text'             => TextField::class,
        'textarea'         => TextareaField::class,
        'email'            => EmailField::class,
        'url'              => UrlField::class,
        'password'         => PasswordField::class,
        'hidden'           => HiddenField::class,
        'number'           => NumberField::class,
        'range'            => RangeField::class,
        'select'           => SelectField::class,
        'radio'            => RadioField::class,
        'image_radio'      => ImageRadioField::class,
        'checkboxes'       => CheckboxesField::class,
        'image_checkboxes' => ImageCheckboxesField::class,
        'toggle'           => ToggleField::class,
        'checkbox'         => CheckboxField::class,
        'color'            => ColorField::class,
        'date'             => DateField::class,
        'time'             => TimeField::class,
        'editor'           => EditorField::class,
        'code_editor'      => CodeEditorField::class,
        'file'             => FileField::class,
        'upload'           => UploadField::class,
        'repeater'         => RepeaterField::class,
        'html'             => HtmlField::class,
        'export'           => ExportField::class,
        'import'           => ImportField::class,
        'table'            => TableField::class,
        'action'           => ActionField::class,
    ];

    final protected function __construct(string $id, string $type)
    {
        $this->attributes = ['id' => $id, 'type' => $type];
    }

    /**
     * Start a field of this type.
     *
     * @param string $id Unique field ID; the key its value is stored under.
     * @return static
     */
    public static function make(string $id): static
    {
        return new static($id, static::TYPE);
    }

    /**
     * Start a field by type slug. Unknown (custom) types get a `CustomField`.
     *
     * @param string $type Field type slug, e.g. `text` or a type registered via `wp-wireframe/field_types`.
     * @param string $id   Unique field ID.
     * @return Field
     */
    public static function of(string $type, string $id): Field
    {
        $class = self::CLASSES[$type] ?? CustomField::class;

        return new $class($id, $type);
    }

    /**
     * Hydrate a field from its config array, keeping every key as-is.
     *
     * @param array $data Field config.
     * @return Field
     */
    public static function fromArray(array $data): Field
    {
        $field = self::of((string) ($data['type'] ?? 'text'), (string) ($data['id'] ?? ''));
        $field->attributes = $data;

        return $field;
    }

    /**
     * The field type slug.
     *
     * @return string
     */
    public function type(): string
    {
        return (string) ($this->attributes['type'] ?? '');
    }

    /**
     * Set the visible label.
     *
     * @param string $label Label text; an empty string hides it.
     * @return static
     */
    public function label(string $label): static
    {
        return $this->set('label', $label);
    }

    /**
     * Set the value used until the user saves one.
     *
     * @param mixed $default Default value; shape depends on the field type.
     * @return static
     */
    public function default(mixed $default): static
    {
        return $this->set('default', $default);
    }

    /**
     * Set the grid width.
     *
     * @param int $columns Columns out of 12.
     * @return static
     */
    public function columns(int $columns): static
    {
        return $this->set('columns', $columns);
    }

    /**
     * Mark the field as requiring a value.
     *
     * @param bool $required Whether a value is required.
     * @return static
     */
    public function required(bool $required = true): static
    {
        return $this->set('required', $required);
    }

    /**
     * Render the input disabled.
     *
     * @param bool $readonly Whether the field is read-only.
     * @return static
     */
    public function readonly(bool $readonly = true): static
    {
        return $this->set('readonly', $readonly);
    }

    /**
     * Add rakit/validation rules on top of the type's built-in ones.
     *
     * @param string $rules Pipe-separated rules, e.g. `min:3|max:100`.
     * @return static
     */
    public function validation(string $rules): static
    {
        return $this->set('validation', $rules);
    }

    /**
     * Merge raw type-specific settings into `args`. Escape hatch for anything without a setter.
     *
     * @param array<string, mixed> $args Keys to merge; later calls win.
     * @return static
     */
    public function args(array $args): static
    {
        return $this->set('args', array_replace($this->attributes['args'] ?? [], $args));
    }

    /**
     * Set a single `args` key.
     *
     * @param string $key   Arg key.
     * @param mixed  $value Arg value.
     * @return static
     */
    protected function arg(string $key, mixed $value): static
    {
        return $this->args([$key => $value]);
    }
}
