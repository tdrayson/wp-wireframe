<?php

/**
 * Tests the bound settings instance returned by `Settings::for()`.
 */

namespace Wireframe;

use ReflectionClass;
use ReflectionMethod;
use Wireframe\Framework\ConfigLoader;

class SettingsRepositoryTest extends \WP_Mock\Tools\TestCase
{
    private const KEY   = 'bound_settings';
    private const OTHER = 'other_settings';

    private const SEED = [
        'site_name'     => 'Acme',
        'notifications' => '1',
        'count'         => 3,
        'ratio'         => '0.5',
        'tags'          => ['a'],
        'config'        => '{"k":1}',
        'empty'         => '',
        'nested'        => ['rows' => [['from' => '/a']]],
    ];

    /** @var array<string, mixed> In-memory wp_options table. */
    private array $options = [];

    public function setUp(): void
    {
        parent::setUp();

        // Unmapped option keys resolve to the `settings` config slug.
        ConfigLoader::register('settings', ['fields' => [
            ['id' => 'site_name', 'type' => 'text'],
            ['id' => 'theme', 'type' => 'text', 'default' => 'light'],
        ]]);

        \WP_Mock::passthruFunction('sanitize_text_field');
        \WP_Mock::userFunction('get_option', [
            'return' => fn (string $key, mixed $default = false) => $this->options[$key] ?? $default,
        ]);
        \WP_Mock::userFunction('update_option', [
            'return' => function (string $key, mixed $value): bool {
                $this->options[$key] = $value;

                return true;
            },
        ]);
        \WP_Mock::userFunction('delete_option', [
            'return' => function (string $key): bool {
                unset($this->options[$key]);

                return true;
            },
        ]);
    }

    /**
     * @dataProvider forwardedCalls
     */
    public function test_bound_method_matches_the_static_call_on_its_option_key(string $method, array $args): void
    {
        $this->seed();
        $expected      = Settings::$method(self::KEY, ...$args);
        $expectedStore = $this->options;

        $this->seed();
        $actual = Settings::for(self::KEY)->$method(...$args);

        $this->assertSame($expected, $actual);
        $this->assertSame($expectedStore, $this->options);
        $this->assertSame(self::SEED, $this->options[self::OTHER]);
    }

    public static function forwardedCalls(): array
    {
        return [
            'get'               => ['get', ['site_name']],
            'get default'       => ['get', ['missing', 'fallback']],
            'get dot'           => ['get', ['nested.rows.0.from']],
            'get field default' => ['get', ['theme']],
            'set'               => ['set', ['theme', 'dark']],
            'set dot'           => ['set', ['nested.rows.0.from', '/b']],
            'has'               => ['has', ['site_name']],
            'has missing'       => ['has', ['missing']],
            'forget'            => ['forget', ['site_name']],
            'forget dot'        => ['forget', ['nested.rows.0.from']],
            'all'               => ['all', []],
            'resolved'          => ['resolved', []],
            'update'            => ['update', [['site_name' => 'New']]],
            'update sanitized'  => ['update', [['site_name' => 'New'], true]],
            'reset'             => ['reset', []],
            'exists'            => ['exists', []],
            'only'              => ['only', ['site_name', 'theme']],
            'except'            => ['except', ['theme']],
            'bool'              => ['bool', ['notifications']],
            'bool default'      => ['bool', ['missing', true]],
            'int'               => ['int', ['count']],
            'int default'       => ['int', ['missing', 7]],
            'float'             => ['float', ['ratio']],
            'float default'     => ['float', ['missing', 1.5]],
            'string'            => ['string', ['site_name']],
            'string default'    => ['string', ['missing', 'fallback']],
            'array'             => ['array', ['tags']],
            'array default'     => ['array', ['missing', ['d']]],
            'json'              => ['json', ['config']],
            'json default'      => ['json', ['missing', 'fallback']],
            'filled'            => ['filled', ['empty', 'fallback']],
            'transform'         => ['transform', ['site_name', 'strtoupper']],
            'transform default' => ['transform', ['missing', 'strtoupper', 'fallback']],
            'when'              => ['when', ['site_name', 'strrev']],
            'when default'      => ['when', ['missing', 'strrev', 'fallback']],
            'increment'         => ['increment', ['count']],
            'increment amount'  => ['increment', ['count', 2.5]],
            'decrement'         => ['decrement', ['count']],
            'decrement amount'  => ['decrement', ['count', 2]],
            'toggle'            => ['toggle', ['notifications']],
            'getOrSet existing' => ['getOrSet', ['site_name', 'new']],
            'getOrSet missing'  => ['getOrSet', ['missing', 'new']],
            'push'              => ['push', ['tags', 'b']],
            'pull'              => ['pull', ['site_name']],
            'pull default'      => ['pull', ['missing', 'fallback']],
        ];
    }

    public function test_exposes_every_public_facade_method_without_the_option_key(): void
    {
        $facade = [];
        foreach ((new ReflectionClass(Settings::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if ($name !== 'for' && !str_ends_with($name, 'For')) {
                $facade[$name] = array_map([$this, 'describe'], array_slice($method->getParameters(), 1));
            }
        }

        $bound = [];
        foreach ((new ReflectionClass(SettingsRepository::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!in_array($method->getName(), ['__construct', 'optionKey'], true)) {
                $bound[$method->getName()] = array_map([$this, 'describe'], $method->getParameters());
            }
        }

        ksort($facade);
        ksort($bound);

        $this->assertSame($facade, $bound);
        $this->assertEqualsCanonicalizing(array_keys($bound), array_unique(array_column(self::forwardedCalls(), 0)));
    }

    public function test_internal_for_methods_are_not_exposed(): void
    {
        foreach (['resolvedFor', 'existsFor', 'allFor', 'updateFor', 'resetFor'] as $method) {
            $this->assertFalse(method_exists(SettingsRepository::class, $method), $method);
        }
    }

    public function test_is_immutable(): void
    {
        $class = new ReflectionClass(SettingsRepository::class);

        $this->assertTrue($class->isFinal());
        foreach ($class->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly(), $property->getName());
        }

        $settings = Settings::for(self::KEY);
        $settings->set('theme', 'dark');

        $this->assertSame(self::KEY, $settings->optionKey());

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('readonly');
        (function (): void {
            $this->optionKey = 'other_settings';
        })->call($settings);
    }

    public function test_instances_for_different_keys_do_not_interfere(): void
    {
        $a = Settings::for(self::KEY);
        $b = Settings::for(self::OTHER);

        $a->set('theme', 'dark');
        $b->set('theme', 'light');
        $b->increment('count');

        $this->assertSame('dark', $a->get('theme'));
        $this->assertSame('light', $b->get('theme'));
        $this->assertFalse($a->has('count'));
        $this->assertSame(['theme' => 'dark'], $this->options[self::KEY]);
        $this->assertSame(['theme' => 'light', 'count' => 1], $this->options[self::OTHER]);

        $a->reset();

        $this->assertFalse($a->exists());
        $this->assertTrue($b->exists());
    }

    private function seed(): void
    {
        $this->options = [self::KEY => self::SEED, self::OTHER => self::SEED];
    }

    private function describe(\ReflectionParameter $parameter): array
    {
        return [
            $parameter->getName(),
            (string) $parameter->getType(),
            $parameter->isVariadic(),
            $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : '(required)',
        ];
    }
}
