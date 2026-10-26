<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\RecordAutosave;

/**
 * The record autosave endpoint (#1757): the transport every record edit
 * screen shares -- nonce, capability, id, allowlist and the value's type --
 * in front of the save callback a module registers.
 *
 * @covers \FreeFormCertificate\Core\RecordAutosave
 */
class RecordAutosaveTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array{ok: bool, data: mixed, status: int|null}> */
	private array $responses = array();

	/** @var array<int, array{0: int, 1: string, 2: mixed}> */
	private array $saved = array();

	/** @var array<string, bool> */
	private array $caps = array( 'ffc_manage_things' => true );

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Shared scripts are enqueued through AssetRegistry, which registers
		// them first; false is WordPress's answer for a handle already
		// registered, so nothing is localized twice (#1931).
		Functions\when( 'wp_register_script' )->justReturn( false );

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ) );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => ! empty( $this->caps[ $cap ] ) );
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data = null ) {
				$this->responses[] = array( 'ok' => true, 'data' => $data, 'status' => null );
				throw new \RuntimeException( 'sent' );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null, $status = null ) {
				$this->responses[] = array( 'ok' => false, 'data' => $data, 'status' => $status );
				throw new \RuntimeException( 'sent' );
			}
		);

		RecordAutosave::reset();
		RecordAutosave::register(
			'thing',
			array(
				'cap'    => 'ffc_manage_things',
				'fields' => array(
					'flag'   => array( 'type' => 'bool' ),
					'status' => array(
						'type' => 'map',
						'map'  => array(
							1 => 'active',
							0 => 'inactive',
						),
					),
					'size'   => array(
						'type'    => 'enum',
						'options' => array( 'sm', 'lg' ),
					),
					'tags'   => array(
						'type'    => 'list',
						'options' => array( 'a', 'b', 'c' ),
					),
				),
				'exists' => static fn( int $id ): bool => 7 === $id,
				'save'   => function ( int $id, string $key, $value ) {
					if ( 'size' === $key && 'lg' === $value ) {
						return new \WP_Error( 'nope', 'Too large for this thing.' );
					}
					$this->saved[] = array( $id, $key, $value );
					return true;
				},
			)
		);
	}

	protected function tearDown(): void {
		RecordAutosave::reset();
		Monkey\tearDown();
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $post Posted fields.
	 */
	private function dispatch( array $post ): void {
		$_POST = $post + array( 'nonce' => 'n' );
		try {
			RecordAutosave::handle();
		} catch ( \RuntimeException $e ) {
			unset( $e ); // wp_send_json_* stubs throw to stop the handler.
		}
	}

	public function test_a_flag_is_saved_through_the_modules_callback(): void {
		$this->dispatch( array( 'entity' => 'thing', 'id' => '7', 'key' => 'flag', 'value' => '1' ) );

		$this->assertSame( array( array( 7, 'flag', 1 ) ), $this->saved );
		$this->assertSame( array( 'key' => 'flag', 'value' => 1 ), $this->responses[0]['data'] );
	}

	public function test_an_entity_with_fragments_sends_the_screen_text_back_after_the_save(): void {
		RecordAutosave::register(
			'described',
			array(
				'cap'       => 'ffc_manage_things',
				'fields'    => array( 'flag' => array( 'type' => 'bool' ) ),
				'exists'    => static fn( int $id ): bool => 7 === $id,
				'save'      => function ( int $id, string $key, $value ) {
					$this->saved[] = array( $id, $key, $value );
					return true;
				},
				'fragments' => fn( int $id ): array => array( 'summary-' . $id => 'Saved ' . count( $this->saved ) ),
			)
		);

		$this->dispatch( array( 'entity' => 'described', 'id' => '7', 'key' => 'flag', 'value' => '1' ) );

		$this->assertSame(
			array(
				'key'       => 'flag',
				'value'     => 1,
				'fragments' => array( 'summary-7' => 'Saved 1' ),
			),
			$this->responses[0]['data'],
			'The fragments are read after the save, so they describe what was stored.'
		);
	}

	public function test_a_mapped_checkbox_saves_the_stored_value_its_state_means(): void {
		$this->dispatch( array( 'entity' => 'thing', 'id' => '7', 'key' => 'status', 'value' => '0' ) );

		$this->assertSame( array( array( 7, 'status', 'inactive' ) ), $this->saved );
	}

	public function test_a_list_keeps_only_known_options(): void {
		$this->dispatch( array( 'entity' => 'thing', 'id' => '7', 'key' => 'tags', 'value' => array( 'c', 'zz', 'a' ) ) );

		$this->assertSame( array( array( 7, 'tags', array( 'a', 'c' ) ) ), $this->saved );
	}

	public function test_an_empty_list_arrives_as_an_empty_string_and_saves_as_empty(): void {
		$this->dispatch( array( 'entity' => 'thing', 'id' => '7', 'key' => 'tags', 'value' => '' ) );

		$this->assertSame( array( array( 7, 'tags', array() ) ), $this->saved );
	}

	public function test_an_enum_value_outside_its_options_is_refused(): void {
		$this->dispatch( array( 'entity' => 'thing', 'id' => '7', 'key' => 'size', 'value' => 'xl' ) );

		$this->assertSame( 400, $this->responses[0]['status'] );
		$this->assertSame( array(), $this->saved );
	}

	public function test_the_modules_validation_error_reaches_the_client(): void {
		$this->dispatch( array( 'entity' => 'thing', 'id' => '7', 'key' => 'size', 'value' => 'lg' ) );

		$this->assertSame( 400, $this->responses[0]['status'] );
		$this->assertSame( 'Too large for this thing.', $this->responses[0]['data']['message'] );
	}

	public function test_an_unknown_entity_is_refused(): void {
		$this->dispatch( array( 'entity' => 'other', 'id' => '7', 'key' => 'flag', 'value' => '1' ) );

		$this->assertSame( 400, $this->responses[0]['status'] );
		$this->assertSame( array(), $this->saved );
	}

	public function test_a_user_without_the_entitys_capability_is_refused(): void {
		$this->caps = array();

		$this->dispatch( array( 'entity' => 'thing', 'id' => '7', 'key' => 'flag', 'value' => '1' ) );

		$this->assertSame( 403, $this->responses[0]['status'] );
		$this->assertSame( array(), $this->saved );
	}

	public function test_an_administrator_passes_the_capability_check(): void {
		$this->caps = array( 'manage_options' => true );

		$this->dispatch( array( 'entity' => 'thing', 'id' => '7', 'key' => 'flag', 'value' => '0' ) );

		$this->assertSame( array( array( 7, 'flag', 0 ) ), $this->saved );
	}

	public function test_a_new_or_missing_record_is_refused(): void {
		$this->dispatch( array( 'entity' => 'thing', 'id' => '0', 'key' => 'flag', 'value' => '1' ) );
		$this->dispatch( array( 'entity' => 'thing', 'id' => '8', 'key' => 'flag', 'value' => '1' ) );

		$this->assertSame( array( 404, 404 ), array_column( $this->responses, 'status' ) );
		$this->assertSame( array(), $this->saved );
	}

	public function test_a_field_the_entity_does_not_list_is_refused(): void {
		$this->dispatch( array( 'entity' => 'thing', 'id' => '7', 'key' => 'name', 'value' => 'x' ) );

		$this->assertSame( 403, $this->responses[0]['status'] );
		$this->assertSame( array(), $this->saved );
	}

	public function test_the_nonce_is_checked_before_anything_else(): void {
		Functions\when( 'check_ajax_referer' )->alias(
			static function () {
				throw new \RuntimeException( 'bad nonce' );
			}
		);

		$this->dispatch( array( 'entity' => 'thing', 'id' => '7', 'key' => 'flag', 'value' => '1' ) );

		$this->assertSame( array(), $this->responses );
		$this->assertSame( array(), $this->saved );
	}

	public function test_attrs_are_empty_for_a_new_record(): void {
		$this->assertSame( array(), RecordAutosave::attrs( 'thing', 0, 'flag' ) );
		$this->assertSame(
			array(
				'ffc-autosave-record-key' => 'flag',
				'ffc-autosave-record'     => 'thing:7',
			),
			RecordAutosave::attrs( 'thing', 7, 'flag' )
		);
	}

	public function test_an_unknown_field_type_sanitizes_to_null(): void {
		$this->assertNull( RecordAutosave::sanitize( '1', array( 'type' => 'float' ) ) );
		$this->assertNull( RecordAutosave::sanitize( array( 'x' ), array( 'type' => 'enum', 'options' => array( 'x' ) ) ) );
		$this->assertNull( RecordAutosave::sanitize( 'x', array( 'type' => 'list', 'options' => array( 'x' ) ) ) );
	}

	public function test_an_int_field_is_a_whole_number_inside_its_bounds(): void {
		$field = array(
			'type' => 'int',
			'min'  => 1,
			'max'  => 100,
		);

		$this->assertSame( 75, RecordAutosave::sanitize( '75', $field ) );
		$this->assertSame( 1, RecordAutosave::sanitize( ' 1 ', $field ) );
		$this->assertNull( RecordAutosave::sanitize( '', $field ), 'A cleared field is not 0.' );
		$this->assertNull( RecordAutosave::sanitize( '0', $field ) );
		$this->assertNull( RecordAutosave::sanitize( '101', $field ) );
		$this->assertNull( RecordAutosave::sanitize( '7.5', $field ) );
		$this->assertNull( RecordAutosave::sanitize( array( '7' ), $field ) );
		$this->assertSame( -3, RecordAutosave::sanitize( '-3', array( 'type' => 'int' ) ), 'Without bounds any whole number is accepted.' );
	}

	public function test_entities_lists_what_was_registered(): void {
		$this->assertSame( array( 'thing' ), RecordAutosave::entities() );
	}

	public function test_init_registers_the_ajax_action(): void {
		Functions\expect( 'add_action' )->once()->with( 'wp_ajax_ffc_update_record', array( RecordAutosave::class, 'handle' ) );
		RecordAutosave::init();
	}

	public function test_enqueue_localizes_the_endpoint_for_the_widget(): void {
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/admin-ajax.php' );
		Functions\when( 'wp_create_nonce' )->alias( static fn( $a ) => 'nonce-' . $a );
		Functions\when( 'wp_enqueue_script' )->justReturn( true );
		$localized = array();
		Functions\when( 'wp_localize_script' )->alias(
			static function ( $handle, $name, $data ) use ( &$localized ) {
				$localized[ $handle . '/' . $name ] = $data;
				return true;
			}
		);

		RecordAutosave::enqueue();

		$data = $localized['ffc-admin-autosave/ffcRecordAutosave'];
		$this->assertSame( 'ffc_update_record', $data['action'] );
		$this->assertSame( 'nonce-ffc_update_record', $data['nonce'] );
		$this->assertSame( array( 'saving', 'saved', 'error', 'invalid' ), array_keys( $data['strings'] ) );
	}
}
