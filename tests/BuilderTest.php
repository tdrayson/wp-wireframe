<?php

/**
 * Tests the Page/Tab/Section builder compiles to the canonical config array.
 */

namespace Wireframe;

use Wireframe\Builder\Fields\TextField;
use Wireframe\Builder\Fields\ToggleField;
use Wireframe\Builder\Page;
use Wireframe\Builder\Section;
use Wireframe\Builder\Tab;
use Wireframe\Framework\ConfigLoader;
use Wireframe\Framework\ConfigValidator;

class BuilderTest extends \WP_Mock\Tools\TestCase
{
    private const EXAMPLE = __DIR__ . '/../examples/newsletter-signup/config/settings.php';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        defined('ABSPATH') || define('ABSPATH', dirname(__DIR__) . '/');
    }

    public function test_converted_example_compiles_to_the_hand_written_array(): void
    {
        $page = require self::EXAMPLE;

        $this->assertInstanceOf(Page::class, $page);
        $this->assertSame(require __DIR__ . '/fixtures/newsletter-signup.php', $page->toArray());
    }

    public function test_converted_example_passes_the_config_linter(): void
    {
        ConfigLoader::register('builder-lint', (require self::EXAMPLE)->toArray());

        $this->assertSame([], ConfigValidator::validate(ConfigLoader::load('builder-lint')));
    }

    /**
     * @dataProvider arrayConfigs
     */
    public function test_hydrating_an_array_is_lossless(array $config): void
    {
        $this->assertSame($config, Page::fromArray($config)->toArray());
    }

    public static function arrayConfigs(): array
    {
        defined('ABSPATH') || define('ABSPATH', dirname(__DIR__) . '/');

        return [
            'newsletter-signup' => [require __DIR__ . '/fixtures/newsletter-signup.php'],
            'field-reference'   => [require __DIR__ . '/../examples/field-reference/config/settings.php'],
        ];
    }

    public function test_sections_only_and_fields_only_formats(): void
    {
        $field = fn() => TextField::make('name')->label('Name');

        $this->assertSame(
            ['sections' => [['id' => 'main', 'title' => 'Main', 'fields' => [['id' => 'name', 'type' => 'text', 'label' => 'Name']]]]],
            Page::make()->section('main', 'Main', fn(Section $s) => $s->fields($field()))->toArray()
        );

        $this->assertSame(
            ['fields' => [['id' => 'name', 'type' => 'text', 'label' => 'Name']]],
            Page::make()->fields($field())->toArray()
        );
    }

    public function test_closure_and_prebuilt_containers_compile_the_same(): void
    {
        $viaClosure = Page::make()->tab('general', 'General', fn(Tab $tab) => $tab
            ->section('main', 'Main', fn(Section $s) => $s->fields(ToggleField::make('on'))));

        $prebuilt = Page::make()->addTab(
            Tab::make('general', 'General')->addSection(Section::make('main', 'Main')->fields(ToggleField::make('on')))
        );

        $this->assertSame($viaClosure->toArray(), $prebuilt->toArray());
    }

    public function test_boot_accepts_a_page_inline_from_a_file_and_per_page(): void
    {
        $expected = $this->normalized(require __DIR__ . '/fixtures/newsletter-signup.php');

        App::boot(['prefix' => 'builder-inline', 'config' => require self::EXAMPLE]);
        App::boot(['prefix' => 'builder-file', 'config' => self::EXAMPLE]);
        App::boot([
            'prefix' => 'builder-multi',
            'pages'  => [['id' => 'signup', 'page_title' => 'Signup', 'config' => require self::EXAMPLE]],
        ]);

        $this->assertSame($expected, ConfigLoader::load('builder-inline'));
        $this->assertSame($expected, ConfigLoader::load('builder-file'));
        $this->assertSame($expected, ConfigLoader::load('builder-multi-signup'));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_mixing_formats_warns_under_wp_debug(): void
    {
        define('WP_DEBUG', true);
        \WP_Mock::userFunction('_doing_it_wrong', ['times' => 1]);

        Page::make()->fields(TextField::make('a'))->addTab(Tab::make('t'))->toArray();

        $this->assertConditionsMet();
    }

    private function normalized(array $config): array
    {
        ConfigLoader::register('builder-expected', $config);

        return ConfigLoader::load('builder-expected');
    }
}
