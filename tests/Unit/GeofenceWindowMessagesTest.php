<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Security\GeofenceWindowMessages;

/**
 * The three out-of-window messages and the one-shot copy of the single
 * message into them (#2241).
 *
 * @covers \FreeFormCertificate\Security\GeofenceWindowMessages
 */
class GeofenceWindowMessagesTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Post meta written during the test, keyed by form ID.
	 *
	 * @var array<int, mixed>
	 */
	private array $meta = array();

	/**
	 * Options written during the test.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();

		$this->meta    = array();
		$this->options = array();
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => '_ffc_geofence_config' === $key ? ( $this->meta[ $id ] ?? '' ) : '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ $id ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_each_moment_reads_its_own_key(): void {
		$config = array(
			'msg_before_open'   => 'Opens on 1 June.',
			'msg_after_close'   => 'Registration has closed.',
			'msg_outside_hours' => 'Open daily from 8 to 17.',
			'msg_datetime'      => 'Leftover.',
		);

		$this->assertSame(
			array(
				'before' => 'Opens on 1 June.',
				'after'  => 'Registration has closed.',
				'during' => 'Open daily from 8 to 17.',
			),
			GeofenceWindowMessages::all( $config )
		);
	}

	public function test_an_empty_key_shows_the_moment_default_and_not_the_single_message(): void {
		$config = array(
			'msg_before_open' => '   ',
			'msg_datetime'    => 'Leftover.',
		);

		$this->assertSame( 'This form is not yet available.', GeofenceWindowMessages::message( $config, 'before' ) );
	}

	public function test_a_config_without_the_keys_shows_the_single_message_everywhere(): void {
		// A form not yet copied, or the content shortcode's one message.
		$config = array( 'msg_datetime' => 'Not available now.' );

		$this->assertSame(
			array(
				'before' => 'Not available now.',
				'after'  => 'Not available now.',
				'during' => 'Not available now.',
			),
			GeofenceWindowMessages::all( $config )
		);
	}

	public function test_no_message_at_all_shows_each_default(): void {
		$this->assertSame(
			array(
				'before' => 'This form is not yet available.',
				'after'  => 'This form is no longer available.',
				'during' => 'This form is only available during specific hours.',
			),
			GeofenceWindowMessages::all( array() )
		);
	}

	public function test_split_copies_the_single_message_into_the_missing_moments_only(): void {
		$split = GeofenceWindowMessages::split(
			array(
				'msg_datetime'    => 'Not available now.',
				'msg_after_close' => 'Already edited.',
				'time_mode'       => 'daily',
			)
		);

		$this->assertSame(
			array(
				'msg_after_close'   => 'Already edited.',
				'time_mode'         => 'daily',
				'msg_before_open'   => 'Not available now.',
				'msg_outside_hours' => 'Not available now.',
			),
			$split
		);
	}

	public function test_split_leaves_a_config_without_the_single_message_alone(): void {
		$this->assertNull( GeofenceWindowMessages::split( array( 'msg_before_open' => 'x' ) ) );
	}

	public function test_the_migration_copies_every_form_once_and_keeps_the_text(): void {
		Functions\when( 'get_posts' )->justReturn( array( 11, 12, 13 ) );
		$this->meta = array(
			11 => array( 'msg_datetime' => 'Closed outside the window.' ),
			12 => array( 'datetime_enabled' => '0' ),
			13 => 'not an array',
		);

		$this->assertSame( 1, GeofenceWindowMessages::migrate() );
		$this->assertSame( 'Closed outside the window.', GeofenceWindowMessages::message( $this->meta[11], 'after' ) );
		$this->assertArrayNotHasKey( 'msg_datetime', $this->meta[11] );
		$this->assertSame( array( 'datetime_enabled' => '0' ), $this->meta[12] );
		$this->assertSame( '1', $this->options[ GeofenceWindowMessages::MIGRATION_FLAG ] );

		// Flagged: a second run touches nothing.
		$this->meta[11] = array( 'msg_datetime' => 'Written after the run.' );
		$this->assertSame( 0, GeofenceWindowMessages::migrate() );
		$this->assertSame( array( 'msg_datetime' => 'Written after the run.' ), $this->meta[11] );
	}
}
