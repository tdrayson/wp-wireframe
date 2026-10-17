<?php
/**
 * Tests saving and loading options as a single array, as individual options, and as a single array with select option individually.
 */

namespace Wireframe;

use WP_Mock\Functions;

class SaveLoadOptionsTest extends \WP_Mock\Tools\TestCase
{

	/**
	 * One tab, one section, four fields covering the storage behaviours
	 * under test: text defaults, a `required` rule, color (the field given
	 * an `option_name` in the mixed cases) and a toggle (bool encoding in
	 * individual rows). Omitting `individual_options` saves as an array.
	 */
	protected array $config = [
		'tabs' => [
			[
				'id'       => 'form',
				'sections' => [
					[
						'id'     => 'content',
						'fields' => [
							[
								'id'       => 'heading',
								'type'     => 'text',
								'default'  => 'Join the newsletter',
								'required' => true,
							],
							[
								'id'      => 'button_label',
								'type'    => 'text',
								'default' => 'Subscribe',
							],
							[
								'id'      => 'accent_color',
								'type'    => 'color',
								'default' => '#3858e9',
							],
							[
								'id'      => 'show_on_mobile',
								'type'    => 'toggle',
								'default' => true,
							],
						],
					],
				],
			],
		],
	];

	public function test_saving_as_array(): void {

		\Wireframe\App::boot([
			'prefix'     => 'newsletter-signup',
			'page_title' => 'Newsletter Signup',
			'option_key' => 'newsletter_signup_settings',
			'config'     => $this->config,
		]);

		$this->mockAdministrator();

		// Nothing saved yet.
		\WP_Mock::userFunction(
			'get_option',
			array(
				'args'   => array( 'newsletter_signup_settings', array() ),
				'return' => array(),
			)
		);

		$payload = array(
			'heading'        => 'Join our list',
			'button_label'   => 'Sign up',
			'accent_color'   => '#ff0000',
			'show_on_mobile' => false,
		);

		// Every field lands in the single array option; no per-field rows.
		\WP_Mock::userFunction(
			'update_option',
			array(
				'times'  => 1,
				'args'   => array( 'newsletter_signup_settings', $payload ),
				'return' => true,
			)
		);

		// Send a REST request purporting to be saving settings.
		$response = $this->postSettings( 'newsletter-signup', 'default', $payload );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertTrue( $response->get_data()['success'] );
	}

	/**
	 * Mock an administrator: the page capability check passes and the text
	 * sanitizer passes values through unchanged.
	 */
	protected function mockAdministrator(): void {
		\WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		\WP_Mock::passthruFunction( 'sanitize_text_field' );
	}

	/**
	 * Seed `get_option` with stored rows keyed by option name. Anything not
	 * seeded returns the caller's default, i.e. "row does not exist".
	 *
	 * @param array<string, mixed> $stored Option name → stored value.
	 */
	protected function mockStoredOptions( array $stored ): void {
		\WP_Mock::userFunction(
			'get_option',
			array(
				'return' => fn( string $name, mixed $default = false ) => array_key_exists( $name, $stored ) ? $stored[ $name ] : $default,
			)
		);
	}

	/**
	 * Register the REST routes and invoke the handler for a page, the same
	 * way WP_REST_Server would dispatch a request.
	 *
	 * @param string                    $method  One of the WP_REST_Server method constants.
	 * @param array<string, mixed>|null $payload JSON body of the request (POST only).
	 */
	protected function dispatchSettings( string $method, string $prefix, string $pageId, ?array $payload = null ): \WP_REST_Response|\WP_Error {
		$routes = array();

		\WP_Mock::userFunction(
			'register_rest_route',
			array(
				'return' => function ( string $namespace, string $route, array $args ) use ( &$routes ): bool {
					$routes[ $namespace . $route ] = $args;
					return true;
				},
			)
		);

		\Wireframe\Rest\SettingsController::register();

		$key = \Wireframe\App::restNamespace( $prefix ) . '/settings/' . $pageId;
		$this->assertArrayHasKey( $key, $routes, 'REST route was not registered.' );

		$endpoint = null;
		foreach ( $routes[ $key ] as $candidate ) {
			if ( $candidate['methods'] === $method ) {
				$endpoint = $candidate;
			}
		}
		$this->assertNotNull( $endpoint, "No {$method} handler registered for the settings route." );
		$this->assertTrue( ( $endpoint['permission_callback'] )(), 'Permission check denied the request.' );

		$request = new \WP_REST_Request( $method, $key );
		if ( null !== $payload ) {
			$request->set_body( (string) json_encode( $payload ) );
		}

		return ( $endpoint['callback'] )( $request );
	}

	/**
	 * Simulate the settings UI saving a page.
	 *
	 * @param array<string, mixed> $payload JSON body of the request.
	 */
	protected function postSettings( string $prefix, string $pageId, array $payload ): \WP_REST_Response|\WP_Error {
		return $this->dispatchSettings( \WP_REST_Server::CREATABLE, $prefix, $pageId, $payload );
	}

	/**
	 * Simulate the settings UI loading a page; returns the resolved values.
	 *
	 * @return array<string, mixed>
	 */
	protected function getSettingsValues( string $prefix, string $pageId ): array {
		$response = $this->dispatchSettings( \WP_REST_Server::READABLE, $prefix, $pageId );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );

		return $response->get_data()['values'];
	}

	public function test_saving_as_all_individual_options(): void {

		$config = $this->config;
		$config['individual_options'] = true;

		\Wireframe\App::boot([
			'prefix'     => 'newsletter-signup',
			'page_title' => 'Newsletter Signup',
			'option_key' => 'newsletter_signup_settings',
			'config'     => $config,
		]);

		$this->mockAdministrator();

		// Nothing saved yet: the array option is empty and no individual
		// row exists (get_option returns the caller's default).
		\WP_Mock::userFunction(
			'get_option',
			array(
				'return' => fn( string $name, mixed $default = false ) => $default,
			)
		);

		$payload = array(
			'heading'        => 'Join our list',
			'button_label'   => 'Sign up',
			'accent_color'   => '#ff0000',
			'show_on_mobile' => false,
		);

		// Each field is written to its own wp_options row named after the
		// field id. Booleans are stored as '1'/'0'.
		$expected_rows = $payload;
		$expected_rows['show_on_mobile'] = '0';

		foreach ( $expected_rows as $option_name => $value ) {
			\WP_Mock::userFunction(
				'update_option',
				array(
					'times'  => 1,
					'args'   => array( $option_name, $value ),
					'return' => true,
				)
			);
		}

		// No (empty) array option is stored: it is never written, and is
		// deleted in case one was left over from array storage.
		\WP_Mock::userFunction(
			'update_option',
			array(
				'times' => 0,
				'args'  => array( 'newsletter_signup_settings', Functions::type( 'array' ) ),
			)
		);
		\WP_Mock::userFunction(
			'delete_option',
			array(
				'times'  => 1,
				'args'   => array( 'newsletter_signup_settings' ),
				'return' => false,
			)
		);

		$response = $this->postSettings( 'newsletter-signup', 'default', $payload );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertTrue( $response->get_data()['success'] );
	}

	/**
	 * With `individual_options` on, a field's explicit `option_name` is
	 * used for its row instead of the field `id`.
	 */
	public function test_saving_all_individual_options_respects_option_name(): void {

		$config = $this->config;
		$config['individual_options'] = true;
		$config['tabs'][0]['sections'][0]['fields'][2]['option_name'] = 'newsletter_accent_color';

		\Wireframe\App::boot([
			'prefix'     => 'newsletter-signup',
			'page_title' => 'Newsletter Signup',
			'option_key' => 'newsletter_signup_settings',
			'config'     => $config,
		]);

		$this->mockAdministrator();
		$this->mockStoredOptions( array() );

		$payload = array(
			'heading'        => 'Join our list',
			'button_label'   => 'Sign up',
			'accent_color'   => '#ff0000',
			'show_on_mobile' => false,
		);

		$expected_rows = array(
			'heading'                 => 'Join our list',
			'button_label'            => 'Sign up',
			'newsletter_accent_color' => '#ff0000',
			'show_on_mobile'          => '0',
		);

		foreach ( $expected_rows as $option_name => $value ) {
			\WP_Mock::userFunction(
				'update_option',
				array(
					'times'  => 1,
					'args'   => array( $option_name, $value ),
					'return' => true,
				)
			);
		}

		// Never written under the field id.
		\WP_Mock::userFunction(
			'update_option',
			array(
				'times' => 0,
				'args'  => array( 'accent_color', Functions::type( 'string' ) ),
			)
		);

		// No (empty) array option is stored: it is never written, and is
		// deleted in case one was left over from array storage.
		\WP_Mock::userFunction(
			'update_option',
			array(
				'times' => 0,
				'args'  => array( 'newsletter_signup_settings', Functions::type( 'array' ) ),
			)
		);
		\WP_Mock::userFunction(
			'delete_option',
			array(
				'times'  => 1,
				'args'   => array( 'newsletter_signup_settings' ),
				'return' => false,
			)
		);

		$response = $this->postSettings( 'newsletter-signup', 'default', $payload );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertTrue( $response->get_data()['success'] );
	}

	public function test_saving_as_array_and_some_individual_options(): void {

		// Only the accent color opts into its own row via `option_name`.
		$config = $this->config;
		$config['tabs'][0]['sections'][0]['fields'][2]['option_name'] = 'newsletter_accent_color';

		\Wireframe\App::boot([
			'prefix'     => 'newsletter-signup',
			'page_title' => 'Newsletter Signup',
			'option_key' => 'newsletter_signup_settings',
			'config'     => $config,
		]);

		$this->mockAdministrator();

		\WP_Mock::userFunction(
			'get_option',
			array(
				'return' => fn( string $name, mixed $default = false ) => $default,
			)
		);

		$payload = array(
			'heading'        => 'Join our list',
			'button_label'   => 'Sign up',
			'accent_color'   => '#ff0000',
			'show_on_mobile' => false,
		);

		// The individually configured field gets its own row.
		\WP_Mock::userFunction(
			'update_option',
			array(
				'times'  => 1,
				'args'   => array( 'newsletter_accent_color', '#ff0000' ),
				'return' => true,
			)
		);

		// Everything else lands in the array option. Capture what was
		// written so we can assert on its shape explicitly.
		$saved_array = null;
		\WP_Mock::userFunction(
			'update_option',
			array(
				'times'  => 1,
				'args'   => array( 'newsletter_signup_settings', Functions::type( 'array' ) ),
				'return' => function ( string $name, array $value ) use ( &$saved_array ): bool {
					$saved_array = $value;
					return true;
				},
			)
		);

		$response = $this->postSettings( 'newsletter-signup', 'default', $payload );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertTrue( $response->get_data()['success'] );

		$this->assertIsArray( $saved_array );
		$this->assertArrayNotHasKey( 'accent_color', $saved_array );

		$expected_array = $payload;
		unset( $expected_array['accent_color'] );
		$this->assertSame( $expected_array, $saved_array );
	}
	public function test_loading_array(): void {

		\Wireframe\App::boot([
			'prefix'     => 'newsletter-signup',
			'page_title' => 'Newsletter Signup',
			'option_key' => 'newsletter_signup_settings',
			'config'     => $this->config,
		]);

		$this->mockAdministrator();

		// `heading` is deliberately absent so it falls back to its default.
		$this->mockStoredOptions(
			array(
				'newsletter_signup_settings' => array(
					'button_label'   => 'Sign up',
					'accent_color'   => '#ff0000',
					'show_on_mobile' => false,
				),
			)
		);

		$values = $this->getSettingsValues( 'newsletter-signup', 'default' );

		$this->assertSame(
			array(
				'heading'        => 'Join the newsletter',
				'button_label'   => 'Sign up',
				'accent_color'   => '#ff0000',
				'show_on_mobile' => false,
			),
			$values
		);
	}

	public function test_loading_all_individual_options(): void {

		$config = $this->config;
		$config['individual_options'] = true;

		\Wireframe\App::boot([
			'prefix'     => 'newsletter-signup',
			'page_title' => 'Newsletter Signup',
			'option_key' => 'newsletter_signup_settings',
			'config'     => $config,
		]);

		$this->mockAdministrator();

		// Each field lives in its own row named after the field id. The
		// array option is empty, `button_label` has no row (→ default) and
		// the toggle row is the encoded string '0' (→ false).
		$this->mockStoredOptions(
			array(
				'newsletter_signup_settings' => array(),
				'heading'                    => 'Join our list',
				'accent_color'               => '#ff0000',
				'show_on_mobile'             => '0',
			)
		);

		$values = $this->getSettingsValues( 'newsletter-signup', 'default' );

		$this->assertSame(
			array(
				'heading'        => 'Join our list',
				'button_label'   => 'Subscribe',
				'accent_color'   => '#ff0000',
				'show_on_mobile' => false,
			),
			$values
		);
	}

	public function test_loading_all_individual_options_respects_option_name(): void {

		$config = $this->config;
		$config['individual_options'] = true;
		$config['tabs'][0]['sections'][0]['fields'][2]['option_name'] = 'newsletter_accent_color';

		\Wireframe\App::boot([
			'prefix'     => 'newsletter-signup',
			'page_title' => 'Newsletter Signup',
			'option_key' => 'newsletter_signup_settings',
			'config'     => $config,
		]);

		$this->mockAdministrator();

		// A row under the bare field id must be ignored; only the
		// `option_name` row is read.
		$this->mockStoredOptions(
			array(
				'newsletter_signup_settings' => array(),
				'heading'                    => 'Join our list',
				'accent_color'               => '#000000',
				'newsletter_accent_color'    => '#ff0000',
				'show_on_mobile'             => '0',
			)
		);

		$values = $this->getSettingsValues( 'newsletter-signup', 'default' );

		$this->assertSame(
			array(
				'heading'        => 'Join our list',
				'button_label'   => 'Subscribe',
				'accent_color'   => '#ff0000',
				'show_on_mobile' => false,
			),
			$values
		);
	}

	public function test_loading_array_and_some_individual_options(): void {

		$config = $this->config;
		$config['tabs'][0]['sections'][0]['fields'][2]['option_name'] = 'newsletter_accent_color';

		\Wireframe\App::boot([
			'prefix'     => 'newsletter-signup',
			'page_title' => 'Newsletter Signup',
			'option_key' => 'newsletter_signup_settings',
			'config'     => $config,
		]);

		$this->mockAdministrator();

		// The array option still carries a stale `accent_color` from before
		// the field was moved to its own row; the row must win.
		$this->mockStoredOptions(
			array(
				'newsletter_signup_settings' => array(
					'heading'        => 'Join our list',
					'button_label'   => 'Sign up',
					'accent_color'   => '#000000',
					'show_on_mobile' => true,
				),
				'newsletter_accent_color'    => '#ff0000',
			)
		);

		$values = $this->getSettingsValues( 'newsletter-signup', 'default' );

		$this->assertSame( '#ff0000', $values['accent_color'] );
		$this->assertSame(
			array(
				'heading'        => 'Join our list',
				'button_label'   => 'Sign up',
				'accent_color'   => '#ff0000',
				'show_on_mobile' => true,
			),
			$values
		);
	}

	/**
	 * A field stored in its own row is still written by the Settings API
	 * while its conditions hide it, matching array storage.
	 */
	public function test_setting_condition_hidden_individual_option(): void {

		$config = $this->config;
		$config['individual_options'] = true;
		$config['tabs'][0]['sections'][0]['fields'][1]['conditions'] = array(
			'all' => array( array( 'field' => 'show_on_mobile', 'operator' => 'truthy' ) ),
		);

		\Wireframe\App::boot([
			'prefix'     => 'newsletter-signup',
			'page_title' => 'Newsletter Signup',
			'option_key' => 'newsletter_signup_settings',
			'config'     => $config,
		]);

		$this->mockAdministrator();

		// `show_on_mobile` is off, so `button_label` is hidden.
		$this->mockStoredOptions(
			array(
				'button_label'   => 'Sign up',
				'show_on_mobile' => '0',
			)
		);

		$written = array();
		\WP_Mock::userFunction(
			'update_option',
			array(
				'return' => function ( string $name, mixed $value ) use ( &$written ): bool {
					$written[ $name ] = $value;
					return true;
				},
			)
		);
		\WP_Mock::userFunction( 'delete_option', array( 'return' => false ) );

		$this->assertTrue( \Wireframe\Settings::set( 'newsletter_signup_settings', 'button_label', 'Subscribe now' ) );

		$this->assertArrayHasKey( 'button_label', $written );
		$this->assertSame( 'Subscribe now', $written['button_label'] );
	}

	/**
	 * `option_prefix` is prepended to rows named after a field id; an
	 * explicit `option_name` is used exactly as written.
	 */
	public function test_saving_individual_options_with_option_prefix(): void {

		$config = $this->config;
		$config['individual_options'] = true;
		$config['option_prefix'] = 'newsletter_';
		$config['tabs'][0]['sections'][0]['fields'][2]['option_name'] = 'brand_accent_color';

		\Wireframe\App::boot([
			'prefix'     => 'newsletter-signup',
			'page_title' => 'Newsletter Signup',
			'option_key' => 'newsletter_signup_settings',
			'config'     => $config,
		]);

		$this->mockAdministrator();
		$this->mockStoredOptions( array() );

		$written = array();
		\WP_Mock::userFunction(
			'update_option',
			array(
				'return' => function ( string $name, mixed $value ) use ( &$written ): bool {
					$written[ $name ] = $value;
					return true;
				},
			)
		);
		\WP_Mock::userFunction( 'delete_option', array( 'return' => false ) );

		$response = $this->postSettings(
			'newsletter-signup',
			'default',
			array(
				'heading'        => 'Join our list',
				'button_label'   => 'Sign up',
				'accent_color'   => '#ff0000',
				'show_on_mobile' => false,
			)
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertTrue( $response->get_data()['success'] );

		$this->assertSame(
			array(
				'newsletter_heading'        => 'Join our list',
				'newsletter_button_label'   => 'Sign up',
				'brand_accent_color'        => '#ff0000',
				'newsletter_show_on_mobile' => '0',
			),
			$written
		);
	}

	public function test_loading_individual_options_with_option_prefix(): void {

		$config = $this->config;
		$config['individual_options'] = true;
		$config['option_prefix'] = 'newsletter_';

		\Wireframe\App::boot([
			'prefix'     => 'newsletter-signup',
			'page_title' => 'Newsletter Signup',
			'option_key' => 'newsletter_signup_settings',
			'config'     => $config,
		]);

		$this->mockAdministrator();

		// Unprefixed rows must be ignored.
		$this->mockStoredOptions(
			array(
				'heading'                   => 'Ignored',
				'newsletter_heading'        => 'Join our list',
				'newsletter_show_on_mobile' => '0',
			)
		);

		$values = $this->getSettingsValues( 'newsletter-signup', 'default' );

		$this->assertSame(
			array(
				'heading'        => 'Join our list',
				'button_label'   => 'Subscribe',
				'accent_color'   => '#3858e9',
				'show_on_mobile' => false,
			),
			$values
		);
	}

	/**
	 * Without `individual_options`, `option_prefix` changes nothing: fields
	 * stay in the array and an explicit `option_name` is not prefixed.
	 */
	public function test_option_prefix_without_individual_options(): void {

		$config = $this->config;
		$config['option_prefix'] = 'newsletter_';
		$config['tabs'][0]['sections'][0]['fields'][2]['option_name'] = 'brand_accent_color';

		\Wireframe\App::boot([
			'prefix'     => 'newsletter-signup',
			'page_title' => 'Newsletter Signup',
			'option_key' => 'newsletter_signup_settings',
			'config'     => $config,
		]);

		$this->mockAdministrator();
		$this->mockStoredOptions( array() );

		$written = array();
		\WP_Mock::userFunction(
			'update_option',
			array(
				'return' => function ( string $name, mixed $value ) use ( &$written ): bool {
					$written[ $name ] = $value;
					return true;
				},
			)
		);

		$this->postSettings(
			'newsletter-signup',
			'default',
			array(
				'heading'        => 'Join our list',
				'button_label'   => 'Sign up',
				'accent_color'   => '#ff0000',
				'show_on_mobile' => false,
			)
		);

		$this->assertSame(
			array(
				'brand_accent_color'         => '#ff0000',
				'newsletter_signup_settings' => array(
					'heading'        => 'Join our list',
					'button_label'   => 'Sign up',
					'show_on_mobile' => false,
				),
			),
			$written
		);
	}
}
