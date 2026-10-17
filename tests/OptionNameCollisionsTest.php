<?php
/**
 * Tests detection of wp_options rows claimed by more than one field or page.
 */

namespace Wireframe;

class OptionNameCollisionsTest extends \WP_Mock\Tools\TestCase
{

	public function setUp(): void {
		parent::setUp();

		// App keeps booted pages in static state; start each test empty.
		foreach ( array( 'pages', 'optionKeyToConfig' ) as $property ) {
			$reflection = new \ReflectionProperty( App::class, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( null, array() );
		}
	}

	/**
	 * Boot a single-page plugin whose config holds the given fields.
	 *
	 * @param array<int, array<string, mixed>> $fields
	 * @param array<string, mixed>             $root   Extra root config keys.
	 */
	protected function bootPlugin( string $prefix, array $fields, array $root = array() ): void {
		App::boot([
			'prefix' => $prefix,
			'config' => $root + array( 'fields' => $fields ),
		]);
	}

	public function test_no_collisions(): void {

		$this->bootPlugin( 'plugin-a', array( array( 'id' => 'enabled', 'type' => 'toggle' ) ), array( 'individual_options' => true ) );
		$this->bootPlugin( 'plugin-b', array( array( 'id' => 'enabled', 'type' => 'toggle', 'option_name' => 'plugin_b_enabled' ) ) );

		$this->assertSame( array(), Settings::optionNameCollisions() );
	}

	/**
	 * Array-stored fields share their page's array option, not a row
	 * namespace, so the same field id on two pages is fine.
	 */
	public function test_same_field_id_in_array_storage_is_not_a_collision(): void {

		$this->bootPlugin( 'plugin-a', array( array( 'id' => 'enabled', 'type' => 'toggle' ) ) );
		$this->bootPlugin( 'plugin-b', array( array( 'id' => 'enabled', 'type' => 'toggle' ) ) );

		$this->assertSame( array(), Settings::optionNameCollisions() );
	}

	public function test_two_plugins_with_the_same_individual_field_id(): void {

		$this->bootPlugin( 'plugin-a', array( array( 'id' => 'enabled', 'type' => 'toggle' ) ), array( 'individual_options' => true ) );
		$this->bootPlugin( 'plugin-b', array( array( 'id' => 'enabled', 'type' => 'toggle' ) ), array( 'individual_options' => true ) );

		$this->assertSame(
			array( 'wp_options row "enabled" is used by the field "enabled" on page "plugin-a", and the field "enabled" on page "plugin-b".' ),
			Settings::optionNameCollisions()
		);
	}

	public function test_explicit_option_name_matching_another_fields_row(): void {

		$this->bootPlugin( 'plugin-a', array( array( 'id' => 'enabled', 'type' => 'toggle' ) ), array( 'individual_options' => true ) );
		$this->bootPlugin( 'plugin-b', array( array( 'id' => 'active', 'type' => 'toggle', 'option_name' => 'enabled' ) ) );

		$this->assertSame(
			array( 'wp_options row "enabled" is used by the field "enabled" on page "plugin-a", and the field "active" on page "plugin-b".' ),
			Settings::optionNameCollisions()
		);
	}

	public function test_two_fields_on_one_page_with_the_same_option_name(): void {

		$this->bootPlugin(
			'plugin-a',
			array(
				array( 'id' => 'first', 'type' => 'text', 'option_name' => 'shared_row' ),
				array( 'id' => 'second', 'type' => 'text', 'option_name' => 'shared_row' ),
			)
		);

		$this->assertSame(
			array( 'wp_options row "shared_row" is used by the field "first" on page "plugin-a", and the field "second" on page "plugin-a".' ),
			Settings::optionNameCollisions()
		);
	}

	public function test_field_resolving_to_another_pages_option_key(): void {

		$this->bootPlugin( 'plugin-a', array( array( 'id' => 'title', 'type' => 'text' ) ) );
		$this->bootPlugin( 'plugin-b', array( array( 'id' => 'plugin_a_settings', 'type' => 'text' ) ), array( 'individual_options' => true ) );

		$this->assertSame(
			array( 'wp_options row "plugin_a_settings" is used by the settings array of page "plugin-a", and the field "plugin_a_settings" on page "plugin-b".' ),
			Settings::optionNameCollisions()
		);
	}

	public function test_collisions_are_reported_with_doing_it_wrong(): void {

		$this->bootPlugin( 'plugin-a', array( array( 'id' => 'enabled', 'type' => 'toggle' ) ), array( 'individual_options' => true ) );
		$this->bootPlugin( 'plugin-b', array( array( 'id' => 'enabled', 'type' => 'toggle' ) ), array( 'individual_options' => true ) );

		\WP_Mock::passthruFunction( 'esc_html' );
		\WP_Mock::userFunction(
			'_doing_it_wrong',
			array(
				'times' => 1,
				'args'  => array(
					'Wireframe\App::boot',
					'wp_options row "enabled" is used by the field "enabled" on page "plugin-a", and the field "enabled" on page "plugin-b".',
					'1.2.0',
				),
			)
		);

		Settings::reportOptionNameCollisions();

		$this->assertConditionsMet();
	}

	public function test_option_prefix_keeps_the_same_field_id_apart(): void {

		$this->bootPlugin( 'plugin-a', array( array( 'id' => 'enabled', 'type' => 'toggle' ) ), array( 'individual_options' => true, 'option_prefix' => 'plugin_a_' ) );
		$this->bootPlugin( 'plugin-b', array( array( 'id' => 'enabled', 'type' => 'toggle' ) ), array( 'individual_options' => true, 'option_prefix' => 'plugin_b_' ) );

		$this->assertSame( array(), Settings::optionNameCollisions() );
	}
}
