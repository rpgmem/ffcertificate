<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceAttendanceTarget;
use FreeFormCertificate\Audience\AudienceAttendanceWriter;
use FreeFormCertificate\Core\Attendance;
use FreeFormCertificate\Core\AttendanceTargetInterface;
use FreeFormCertificate\Core\AttendanceTargetRegistry;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The one attendance write path (#2740): a target named by type and id,
 * routed through the registry to the module that stores it.
 *
 * @covers \FreeFormCertificate\Core\Attendance
 * @covers \FreeFormCertificate\Core\AttendanceTargetRegistry
 * @covers \FreeFormCertificate\Audience\AudienceAttendanceTarget
 */
class AttendanceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const NOW = 1800000000;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		\FreeFormCertificate\Core\ActivityLog::disable_logging();
		AttendanceTargetRegistry::reset();
	}

	protected function tearDown(): void {
		AttendanceTargetRegistry::reset();
		\FreeFormCertificate\Core\ActivityLog::enable_logging();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A target that remembers what it was asked to record.
	 *
	 * @param string $type   Type it reports.
	 * @param string $answer What record() returns.
	 */
	private function spy( string $type, string $answer = Attendance::RECORDED ): object {
		return new class( $type, $answer ) implements AttendanceTargetInterface {
			/** @var list<array<int, mixed>> */
			public array $calls = array();

			public function __construct( private string $kind, private string $answer ) {
			}

			public function type(): string {
				return $this->kind;
			}

			public function record( int $target_id, int $user_id, int $checked_at, string $method, ?int $marked_by ): string {
				$this->calls[] = array( $target_id, $user_id, $checked_at, $method, $marked_by );
				return $this->answer;
			}
		};
	}

	public function test_a_reading_reaches_the_target_registered_for_its_type(): void {
		$target = $this->spy( 'event' );
		AttendanceTargetRegistry::register( 'event', static fn() => $target );

		$this->assertSame( Attendance::RECORDED, Attendance::record( 'event', 7, 42, self::NOW, Attendance::METHOD_TERMINAL ) );
		$this->assertSame( array( array( 7, 42, self::NOW, 'terminal', null ) ), $target->calls );
		$this->assertSame( array( 'event' ), AttendanceTargetRegistry::types() );
	}

	public function test_an_unknown_type_or_a_mis_built_target_records_nothing(): void {
		$wrong = $this->spy( 'other' );
		AttendanceTargetRegistry::register( 'event', static fn() => $wrong );
		AttendanceTargetRegistry::register( 'broken', static fn() => new \stdClass() );

		$this->assertSame( Attendance::FAILED, Attendance::record( 'missing', 7, 42, self::NOW, Attendance::METHOD_QR ) );
		$this->assertSame( Attendance::FAILED, Attendance::record( 'event', 7, 42, self::NOW, Attendance::METHOD_QR ) );
		$this->assertSame( Attendance::FAILED, Attendance::record( 'broken', 7, 42, self::NOW, Attendance::METHOD_QR ) );
		$this->assertNull( AttendanceTargetRegistry::get( 'missing' ) );
		$this->assertSame( array(), $wrong->calls );
	}

	/**
	 * @dataProvider bad_arguments
	 */
	public function test_bad_arguments_never_reach_the_target( int $id, int $user, int $at, string $method ): void {
		$target = $this->spy( 'event' );
		AttendanceTargetRegistry::register( 'event', static fn() => $target );

		$this->assertSame( Attendance::FAILED, Attendance::record( 'event', $id, $user, $at, $method ) );
		$this->assertSame( array(), $target->calls );
	}

	/**
	 * @return array<string, array{0: int, 1: int, 2: int, 3: string}>
	 */
	public static function bad_arguments(): array {
		return array(
			'no target'      => array( 0, 42, self::NOW, 'qr' ),
			'no person'      => array( 7, 0, self::NOW, 'qr' ),
			'no time'        => array( 7, 42, 0, 'qr' ),
			'unknown method' => array( 7, 42, self::NOW, 'wristband' ),
		);
	}

	public function test_the_writer_speaks_the_core_vocabulary(): void {
		$this->assertSame( array( 'qr', 'manual', 'terminal' ), Attendance::METHODS );
		$this->assertSame( Attendance::METHOD_TERMINAL, AudienceAttendanceWriter::METHOD_TERMINAL );
		$this->assertSame( Attendance::ALREADY, AudienceAttendanceWriter::ALREADY );
		$this->assertSame( 'terminal', Attendance::short_label( 'terminal' ) );
		$this->assertSame( 'by hand', Attendance::short_label( 'manual' ) );
		$this->assertSame( 'QR', Attendance::short_label( 'qr' ) );
		$this->assertSame( 'Self-service terminal', Attendance::label( 'terminal' ) );
		$this->assertSame( 'Marked by staff', Attendance::label( 'manual' ) );
		$this->assertSame( 'QR check-in', Attendance::label( 'qr' ) );
	}

	/**
	 * The booking target records on the site-local day of the reading, with
	 * the reading's own time — not the day or time of the sync.
	 */
	public function test_the_booking_target_records_on_the_day_of_the_reading(): void {
		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$args         = array();
		$wpdb->shouldReceive( 'prepare' )->once()->andReturnUsing(
			static function ( $sql, ...$values ) use ( &$args ) {
				$args = $values;
				return 'SQL';
			}
		);
		$wpdb->shouldReceive( 'query' )->once()->with( 'SQL' )->andReturn( 1 );
		Functions\when( 'wp_date' )->alias(
			static fn( string $format, int $ts ): string => ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( new \DateTimeZone( 'America/Sao_Paulo' ) )->format( $format )
		);
		AttendanceTargetRegistry::register( AudienceAttendanceTarget::TYPE, static fn() => new AudienceAttendanceTarget() );

		// 01:30 UTC on the 16th is still the 15th in São Paulo.
		$reading = ( new \DateTimeImmutable( '2027-01-16 01:30:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$result  = Attendance::record( 'audience_booking', 123, 42, $reading, Attendance::METHOD_TERMINAL );

		$this->assertSame( Attendance::RECORDED, $result );
		$this->assertSame( array( 'wp_ffc_audience_attendance', 123, 42, '2027-01-15', $reading, 'terminal' ), $args );
	}

	/**
	 * One record per person, booking and day whatever the path: the unique
	 * key answers the second write, and every pair of paths reads it the same
	 * way (#2740).
	 *
	 * @dataProvider path_pairs
	 */
	public function test_a_second_path_on_the_same_day_reads_as_already( string $first, string $second ): void {
		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$written      = array();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$values ) {
				return implode( '|', array( $values[1], $values[2], $values[3] ) );
			}
		);
		// The table's unique key on booking, person and day, as the server applies it.
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function ( string $key ) use ( &$written ) {
				if ( isset( $written[ $key ] ) ) {
					return 0;
				}
				$written[ $key ] = true;
				return 1;
			}
		);
		Functions\when( 'wp_date' )->justReturn( '2027-01-15' );
		AttendanceTargetRegistry::register( AudienceAttendanceTarget::TYPE, static fn() => new AudienceAttendanceTarget() );

		$write = static function ( string $method ): string {
			$by = Attendance::METHOD_MANUAL === $method ? 9 : null;
			return Attendance::METHOD_TERMINAL === $method
				? Attendance::record( 'audience_booking', 123, 42, self::NOW, $method, $by )
				: AudienceAttendanceWriter::record( 123, 42, '2027-01-15', $method, $by, self::NOW );
		};

		$this->assertSame( Attendance::RECORDED, $write( $first ) );
		$this->assertSame( Attendance::ALREADY, $write( $second ) );
		$this->assertCount( 1, $written );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function path_pairs(): array {
		$pairs = array();
		foreach ( Attendance::METHODS as $first ) {
			foreach ( Attendance::METHODS as $second ) {
				$pairs[ $first . ' then ' . $second ] = array( $first, $second );
			}
		}
		return $pairs;
	}
}
