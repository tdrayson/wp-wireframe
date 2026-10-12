<?php

/**
 * Tests the config editor and the load-time `{prefix}/config` injection hook.
 */

namespace Wireframe;

use WP_Mock\Functions;
use WP_Mock\InvokedFilterValue;
use Wireframe\Builder\Editor;
use Wireframe\Builder\Fields\PasswordField;
use Wireframe\Builder\Fields\RepeaterField;
use Wireframe\Builder\Fields\TextField;
use Wireframe\Builder\Section;
use Wireframe\Builder\Tab;
use Wireframe\Framework\ConfigLoader;

class EditorTest extends \WP_Mock\Tools\TestCase
{
    public function test_field_level_before_after_replace_remove(): void
    {
        $config = $this->editor()
            ->before('button_label', TextField::make('before_button'))
            ->after('heading', TextField::make('after_heading'), TextField::make('after_heading_2'))
            ->replace('list_id', TextField::make('audience'))
            ->remove('description');

        $this->assertSame(['heading', 'after_heading', 'after_heading_2', 'before_button', 'button_label'], $this->fieldIds($config, 'form', 'content'));
        $this->assertSame(['provider', 'api_key', 'audience'], $this->fieldIds($config, 'provider', 'connection'));
    }

    public function test_section_level_before_after_replace_remove(): void
    {
        $config = $this->editor()
            ->before('appearance', Section::make('before_appearance'))
            ->after('content', Section::make('after_content'))
            ->replace('connection', Section::make('connection_v2'))
            ->remove('appearance');

        $this->assertSame(['content', 'after_content', 'before_appearance'], $this->sectionIds($config, 'form'));
        $this->assertSame(['connection_v2'], $this->sectionIds($config, 'provider'));
    }

    public function test_tab_level_before_after_replace_remove(): void
    {
        // `provider` is both a tab and a field id; the level comes from the item passed.
        $config = $this->editor()
            ->before('provider', Tab::make('before_provider'))
            ->after('form', Tab::make('after_form'))
            ->replace('provider', Tab::make('provider_v2'))
            ->remove('form');

        $this->assertSame(['after_form', 'before_provider', 'provider_v2'], array_column($config->toArray()['tabs'], 'id'));
    }

    public function test_remove_matches_fields_before_sections_and_tabs(): void
    {
        $config = $this->editor()->remove('provider');

        $this->assertSame(['form', 'provider'], array_column($config->toArray()['tabs'], 'id'));
        $this->assertSame(['api_key', 'list_id'], $this->fieldIds($config, 'provider', 'connection'));
    }

    public function test_add_methods_append(): void
    {
        $config = $this->editor()
            ->addTab(Tab::make('pro', 'Pro'))
            ->addSection('form', Section::make('extra'))
            ->addField('appearance', TextField::make('font'));

        $this->assertSame(['form', 'provider', 'pro'], array_column($config->toArray()['tabs'], 'id'));
        $this->assertSame(['content', 'appearance', 'extra'], $this->sectionIds($config, 'form'));
        $this->assertSame(['accent_color', 'show_on_mobile', 'font'], $this->fieldIds($config, 'form', 'appearance'));
    }

    public function test_lookups_return_live_nodes(): void
    {
        $config = $this->editor();
        $config->tab('form')->title('Signup form');
        $config->section('content')->fields(TextField::make('footnote'));
        $config->field('heading')->label('Title')->columns(12);

        $tab = $config->toArray()['tabs'][0];

        $this->assertSame('Signup form', $tab['title']);
        $this->assertSame('footnote', end($tab['sections'][0]['fields'])['id']);
        $this->assertSame(['id' => 'heading', 'type' => 'text', 'label' => 'Title', 'default' => 'Join the newsletter', 'required' => true, 'columns' => 12], $tab['sections'][0]['fields'][0]);
        $this->assertTrue($config->has('appearance'));
        $this->assertFalse($config->has('nope'));
    }

    public function test_untouched_config_round_trips(): void
    {
        $normalized = $this->normalized();

        $this->assertSame($normalized, Editor::fromArray($normalized)->toArray());
    }

    public function test_injected_fields_reach_flat_fields(): void
    {
        $this->onConfigFilter(function (Editor $config, string $pageId) {
            $this->assertSame('default', $pageId);

            return $config
                ->after('api_key', PasswordField::make('api_secret')->label('API secret'))
                ->addField('appearance', RepeaterField::make('links')->subfields(TextField::make('url')));
        });

        ConfigLoader::register('editor-inject', $this->fixture(), 'editor-test', 'default');
        $fields = ConfigLoader::flatFields('editor-inject');

        $this->assertArrayHasKey('api_secret', $fields);
        $this->assertArrayHasKey('links', $fields);
        $this->assertArrayHasKey('links.url', $fields);
        $this->assertSame(['provider', 'api_key', 'api_secret', 'list_id'], $this->fieldIds(Editor::fromArray(ConfigLoader::load('editor-inject')), 'provider', 'connection'));
    }

    public function test_callback_that_forgets_to_return_still_applies(): void
    {
        $this->onConfigFilter(function (Editor $config) {
            $config->remove('list_id');
        });

        ConfigLoader::register('editor-no-return', $this->fixture(), 'editor-test', 'default');

        $this->assertArrayNotHasKey('list_id', ConfigLoader::flatFields('editor-no-return'));
    }

    public function test_configs_without_a_prefix_skip_the_filter(): void
    {
        ConfigLoader::register('editor-no-prefix', $this->fixture());

        $this->assertSame($this->normalized(), ConfigLoader::load('editor-no-prefix'));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_missing_targets_warn_under_wp_debug_and_change_nothing(): void
    {
        define('WP_DEBUG', true);
        \WP_Mock::userFunction('_doing_it_wrong', ['times' => 10]);

        $this->assertMissingTargetsAreNoOps();
        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_missing_targets_are_silent_without_wp_debug(): void
    {
        \WP_Mock::userFunction('_doing_it_wrong', ['times' => 0]);

        $this->assertMissingTargetsAreNoOps();
        $this->assertConditionsMet();
    }

    private function assertMissingTargetsAreNoOps(): void
    {
        $config = $this->editor();

        $config->before('nope', TextField::make('a'))
            ->after('nope', Section::make('b'))
            ->replace('nope', Tab::make('c'))
            ->remove('nope')
            ->addSection('nope', Section::make('d'))
            ->addField('nope', TextField::make('e'))
            ->before('heading', TextField::make('f'), Section::make('g'));

        // Detached stand-ins: chained calls must not fatal or leak into the config.
        $config->tab('nope')->title('x');
        $config->section('nope')->fields(TextField::make('h'));
        $config->field('nope')->label('x')->placeholder('x');

        $this->assertSame($this->normalized(), $config->toArray());
    }

    private function onConfigFilter(callable $callback): void
    {
        \WP_Mock::onFilter('editor-test/config')
            ->with(Functions::type(Editor::class), 'default')
            ->reply(new InvokedFilterValue($callback));
    }

    private function editor(): Editor
    {
        return Editor::fromArray($this->normalized());
    }

    private function normalized(): array
    {
        ConfigLoader::register('editor-base', $this->fixture());

        return ConfigLoader::load('editor-base');
    }

    private function fixture(): array
    {
        return require __DIR__ . '/fixtures/newsletter-signup.php';
    }

    private function sectionIds(Editor $config, string $tabId): array
    {
        foreach ($config->toArray()['tabs'] as $tab) {
            if ($tab['id'] === $tabId) {
                return array_column($tab['sections'], 'id');
            }
        }

        return [];
    }

    private function fieldIds(Editor $config, string $tabId, string $sectionId): array
    {
        foreach ($config->toArray()['tabs'] as $tab) {
            foreach ($tab['sections'] as $section) {
                if ($tab['id'] === $tabId && $section['id'] === $sectionId) {
                    return array_column($section['fields'], 'id');
                }
            }
        }

        return [];
    }
}
