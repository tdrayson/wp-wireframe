<?php

/**
 * Tests every typed field builder emits the expected, schema-valid config.
 */

namespace Wireframe;

use Wireframe\Builder\Fields;
use Wireframe\Builder\Fields\CustomField;
use Wireframe\Builder\Fields\Field;
use Wireframe\Builder\Fields\TableColumn;
use Wireframe\Builder\Fields\TextField;
use Wireframe\Framework\ConfigLoader;
use Wireframe\Framework\ConfigValidator;
use Wireframe\Framework\Fields\FieldRegistry;

class FieldBuildersTest extends \WP_Mock\Tools\TestCase
{
    /**
     * @dataProvider fields
     */
    public function test_field_compiles_to_expected_array(Field $field, array $expected): void
    {
        $this->assertSame($expected, $field->toArray());
    }

    /**
     * @dataProvider fields
     */
    public function test_field_passes_the_config_linter(Field $field): void
    {
        ConfigLoader::register('field-lint', ['fields' => [$field->toArray()]]);

        $this->assertSame([], ConfigValidator::validate(ConfigLoader::load('field-lint')));
    }

    /**
     * @dataProvider fields
     */
    public function test_field_uses_only_schema_keys(Field $field): void
    {
        $schema = json_decode((string) file_get_contents(__DIR__ . '/../schema/config.schema.json'), true);
        $field  = $field->toArray();

        $this->assertSame([], array_diff(array_keys($field), array_keys($schema['$defs']['field']['properties'])));
        $this->assertContains($field['type'], $schema['$defs']['field']['properties']['type']['enum']);
    }

    public function test_every_registered_type_and_builder_class_is_covered(): void
    {
        $covered = array_map(fn(array $case) => get_class($case[0]), self::fields());

        foreach (array_keys(FieldRegistry::instance()->all()) as $type) {
            $field = Field::of($type, 'x');

            $this->assertNotInstanceOf(CustomField::class, $field, $type);
            $this->assertSame($type, $field->type());
            $this->assertContains(get_class($field), $covered, $type);
        }

        foreach (glob(dirname(__DIR__) . '/src/Builder/Fields/*Field.php') as $file) {
            $class = 'Wireframe\\Builder\\Fields\\' . basename($file, '.php');

            if (!(new \ReflectionClass($class))->isAbstract() && !in_array($class, [CustomField::class, Fields\MissingField::class], true)) {
                $this->assertContains($class, $covered);
            }
        }
    }

    public function test_common_keys_and_args_escape_hatch(): void
    {
        $field = TextField::make('slug')
            ->label('Slug')
            ->title('Slug title')
            ->description('URL: {slug}')
            ->default('home')
            ->columns(6)
            ->required()
            ->readonly()
            ->validation('min:3')
            ->access(['view' => 'editor', 'edit' => 'manage_options'])
            ->conditions(['all' => [['field' => 'enabled', 'operator' => 'truthy']]])
            ->placeholder('home')
            ->args(['placeholder' => 'about', 'custom' => true]);

        $this->assertSame([
            'id'          => 'slug',
            'type'        => 'text',
            'label'       => 'Slug',
            'title'       => 'Slug title',
            'description' => 'URL: {slug}',
            'default'     => 'home',
            'columns'     => 6,
            'required'    => true,
            'readonly'    => true,
            'validation'  => 'min:3',
            'access'      => ['view' => 'editor', 'edit' => 'manage_options'],
            'conditions'  => ['all' => [['field' => 'enabled', 'operator' => 'truthy']]],
            'args'        => ['placeholder' => 'about', 'custom' => true],
        ], $field->toArray());
    }

    public function test_custom_types_round_trip(): void
    {
        $field = Field::of('my_custom', 'thing')->label('Thing')->args(['foo' => 'bar']);

        $this->assertInstanceOf(CustomField::class, $field);
        $this->assertSame(['id' => 'thing', 'type' => 'my_custom', 'label' => 'Thing', 'args' => ['foo' => 'bar']], $field->toArray());
        $this->assertSame($field->toArray(), Field::fromArray($field->toArray())->toArray());
    }

    /**
     * One fully-configured instance of every field class, with its expected array.
     */
    public static function fields(): array
    {
        $options = ['a' => 'A', 'b' => 'B'];
        $images  = ['a' => ['label' => 'A', 'image' => 'a.png']];

        return [
            'text' => [
                Fields\TextField::make('f')->placeholder('p')->copyable()->allowHtml(),
                ['id' => 'f', 'type' => 'text', 'args' => ['placeholder' => 'p', 'copyable' => true, 'allow_html' => true]],
            ],
            'email' => [
                Fields\EmailField::make('f')->placeholder('p')->copyable(),
                ['id' => 'f', 'type' => 'email', 'args' => ['placeholder' => 'p', 'copyable' => true]],
            ],
            'url' => [
                Fields\UrlField::make('f')->placeholder('p')->copyable(false),
                ['id' => 'f', 'type' => 'url', 'args' => ['placeholder' => 'p', 'copyable' => false]],
            ],
            'password' => [
                Fields\PasswordField::make('f')->placeholder('p'),
                ['id' => 'f', 'type' => 'password', 'args' => ['placeholder' => 'p']],
            ],
            'textarea' => [
                Fields\TextareaField::make('f')->placeholder('p')->rows(4)->copyable(),
                ['id' => 'f', 'type' => 'textarea', 'args' => ['placeholder' => 'p', 'rows' => 4, 'copyable' => true]],
            ],
            'hidden' => [
                Fields\HiddenField::make('f')->default('x'),
                ['id' => 'f', 'type' => 'hidden', 'default' => 'x'],
            ],
            'number' => [
                Fields\NumberField::make('f')->min(0)->max(10)->step(0.5)->integer()->placeholder('p')->copyable(),
                ['id' => 'f', 'type' => 'number', 'args' => ['min' => 0, 'max' => 10, 'step' => 0.5, 'integer' => true, 'placeholder' => 'p', 'copyable' => true]],
            ],
            'range' => [
                Fields\RangeField::make('f')->min(0)->max(100)->step(5)->suffix('%')->allowReset()->marks(true),
                ['id' => 'f', 'type' => 'range', 'args' => ['min' => 0, 'max' => 100, 'step' => 5, 'suffix' => '%', 'allowReset' => true, 'marks' => true]],
            ],
            'select' => [
                Fields\SelectField::make('f')->options($options)->multiple()->allowCustom()->placeholder('p')->max(2),
                ['id' => 'f', 'type' => 'select', 'args' => ['options' => $options, 'multiple' => true, 'allow_custom' => true, 'placeholder' => 'p', 'max' => 2]],
            ],
            'radio' => [
                Fields\RadioField::make('f')->options($options),
                ['id' => 'f', 'type' => 'radio', 'args' => ['options' => $options]],
            ],
            'image_radio' => [
                Fields\ImageRadioField::make('f')->options($images),
                ['id' => 'f', 'type' => 'image_radio', 'args' => ['options' => $images]],
            ],
            'checkboxes' => [
                Fields\CheckboxesField::make('f')->options($options),
                ['id' => 'f', 'type' => 'checkboxes', 'args' => ['options' => $options]],
            ],
            'image_checkboxes' => [
                Fields\ImageCheckboxesField::make('f')->options($images),
                ['id' => 'f', 'type' => 'image_checkboxes', 'args' => ['options' => $images]],
            ],
            'toggle' => [
                Fields\ToggleField::make('f')->default(true),
                ['id' => 'f', 'type' => 'toggle', 'default' => true],
            ],
            'checkbox' => [
                Fields\CheckboxField::make('f')->default(false),
                ['id' => 'f', 'type' => 'checkbox', 'default' => false],
            ],
            'color' => [
                Fields\ColorField::make('f')->enableAlpha(),
                ['id' => 'f', 'type' => 'color', 'args' => ['enableAlpha' => true]],
            ],
            'date' => [
                Fields\DateField::make('f')->default('2026-01-01'),
                ['id' => 'f', 'type' => 'date', 'default' => '2026-01-01'],
            ],
            'time' => [
                Fields\TimeField::make('f')->default('09:00'),
                ['id' => 'f', 'type' => 'time', 'default' => '09:00'],
            ],
            'editor' => [
                Fields\EditorField::make('f')->rows(8),
                ['id' => 'f', 'type' => 'editor', 'args' => ['rows' => 8]],
            ],
            'code_editor' => [
                Fields\CodeEditorField::make('f')->mode('css')->rows(6),
                ['id' => 'f', 'type' => 'code_editor', 'args' => ['mode' => 'css', 'rows' => 6]],
            ],
            'file' => [
                Fields\FileField::make('f')->multiple()->mimeTypes('image')->mediaTitle('t')->mediaButton('b'),
                ['id' => 'f', 'type' => 'file', 'args' => ['multiple' => true, 'mime_types' => 'image', 'media_title' => 't', 'media_button' => 'b']],
            ],
            'repeater' => [
                Fields\RepeaterField::make('f')
                    ->subfields(Fields\TextField::make('from'), Fields\SelectField::make('status')->options($options))
                    ->sortable()->collapsible()->collapsed()->duplicateRow()->addRow()->deleteRow(false)
                    ->minRows(1)->maxRows(5)->addLabel('Add')->emptyMessage('None')->titleTemplate('{from}'),
                ['id' => 'f', 'type' => 'repeater', 'args' => [
                    'subfields'      => [
                        ['id' => 'from', 'type' => 'text'],
                        ['id' => 'status', 'type' => 'select', 'args' => ['options' => $options]],
                    ],
                    'sortable'       => true,
                    'collapsible'    => true,
                    'collapsed'      => true,
                    'duplicate_row'  => true,
                    'add_row'        => true,
                    'delete_row'     => false,
                    'min_rows'       => 1,
                    'max_rows'       => 5,
                    'add_label'      => 'Add',
                    'empty_message'  => 'None',
                    'title_template' => '{from}',
                ]],
            ],
            'html' => [
                Fields\HtmlField::make('f')->content('<p>Hi</p>')->variant('info'),
                ['id' => 'f', 'type' => 'html', 'args' => ['content' => '<p>Hi</p>', 'variant' => 'info']],
            ],
            'export' => [
                Fields\ExportField::make('f')->buttonLabel('Download')->filename('backup'),
                ['id' => 'f', 'type' => 'export', 'args' => ['button_label' => 'Download', 'filename' => 'backup']],
            ],
            'import' => [
                Fields\ImportField::make('f')->buttonLabel('Upload'),
                ['id' => 'f', 'type' => 'import', 'args' => ['button_label' => 'Upload']],
            ],
            'table' => [
                Fields\TableField::make('f')
                    ->fields(
                        TableColumn::make('name')->label('Name')->type('text')->sortable()->searchable(),
                        ['id' => 'status', 'elements' => $options]
                    )
                    ->dataCallback('fetch_rows')->perPage(20)->search()
                    ->actions([['id' => 'delete', 'label' => 'Delete', 'supports_bulk' => true]])
                    ->detailView(['back_label' => 'Back']),
                ['id' => 'f', 'type' => 'table', 'args' => [
                    'fields'        => [
                        ['id' => 'name', 'label' => 'Name', 'type' => 'text', 'sortable' => true, 'searchable' => true],
                        ['id' => 'status', 'elements' => $options],
                    ],
                    'data_callback' => 'fetch_rows',
                    'per_page'      => 20,
                    'search'        => true,
                    'actions'       => [['id' => 'delete', 'label' => 'Delete', 'supports_bulk' => true]],
                    'detail_view'   => ['back_label' => 'Back'],
                ]],
            ],
            'action (single)' => [
                Fields\ActionField::make('f')->buttonLabel('Run')->variant('primary')->confirm('Sure?')->destructive()->labelSpacer(),
                ['id' => 'f', 'type' => 'action', 'args' => ['button_label' => 'Run', 'variant' => 'primary', 'confirm' => 'Sure?', 'destructive' => true, 'label_spacer' => true]],
            ],
            'action (group)' => [
                Fields\ActionField::make('f')->button('a', 'A', ['variant' => 'primary'])->button('b', 'B'),
                ['id' => 'f', 'type' => 'action', 'args' => ['buttons' => [['id' => 'a', 'label' => 'A', 'variant' => 'primary'], ['id' => 'b', 'label' => 'B']]]],
            ],
        ];
    }
}
