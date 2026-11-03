<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\IpHeaderDivergence;
use PHPUnit\Framework\TestCase;

/**
 * The tally of forged-header disagreements IP Diagnostics shows (#2339).
 *
 * @covers \FreeFormCertificate\Core\IpHeaderDivergence
 */
class IpHeaderDivergenceTest extends TestCase {

	/**
	 * Transient store.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		IpHeaderDivergence::reset();
		$this->transients = array();
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		IpHeaderDivergence::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_init_listens_to_the_resolver(): void {
		IpHeaderDivergence::init();
		$this->assertNotFalse( has_action( 'ffcertificate_ip_header_disagrees', array( IpHeaderDivergence::class, 'record' ) ) );
	}

	public function test_nothing_counted_reads_as_no_tally(): void {
		$this->assertNull( IpHeaderDivergence::tally() );
	}

	public function test_one_request_counts_once_however_often_the_resolver_runs(): void {
		IpHeaderDivergence::record();
		IpHeaderDivergence::record();

		$tally = IpHeaderDivergence::tally();
		$this->assertNotNull( $tally );
		$this->assertSame( 1, $tally['count'] );
	}

	public function test_later_requests_add_to_the_same_tally(): void {
		IpHeaderDivergence::record();
		IpHeaderDivergence::reset();
		IpHeaderDivergence::record();

		$this->assertSame( 2, IpHeaderDivergence::tally()['count'] );
	}

	public function test_a_tally_older_than_its_window_starts_again(): void {
		$this->transients[ IpHeaderDivergence::TRANSIENT ] = array(
			'count' => 40,
			'first' => time() - IpHeaderDivergence::WINDOW - 10,
			'last'  => time() - 3600,
		);
		$this->assertNull( IpHeaderDivergence::tally() );

		IpHeaderDivergence::record();

		$this->assertSame( 1, IpHeaderDivergence::tally()['count'] );
	}

	public function test_the_tally_keeps_no_address(): void {
		IpHeaderDivergence::record();

		$this->assertSame( array( 'count', 'first', 'last' ), array_keys( $this->transients[ IpHeaderDivergence::TRANSIENT ] ) );
	}
}
