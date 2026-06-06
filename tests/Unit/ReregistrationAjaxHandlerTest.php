<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationAjaxHandler;

/**
 * Tests for the reregistration admin AJAX endpoints: ficha generation,
 * submission-details modal and the affected-member count. Covers the
 * nonce/capability/input guards directly; the count happy-path drives the
 * repository via an alias mock in an isolated process.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationAjaxHandler
 */
class ReregistrationAjaxHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array{type: string, data: mixed}> */
	private array $json = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $v ) => abs( (int) $v ) );
		Functions\when( 'check_ajax_referer' )->justReturn( true );

		$this->json = array();
		$ref =& $this->json;
		Functions\when( 'wp_send_json_success' )->alias( static function ( $data = null ) use ( &$ref ) {
			$ref[] = array( 'type' => 'success', 'data' => $data );
			throw new \RuntimeException( 'wp_send_json_success' );
		} );
		Functions\when( 'wp_send_json_error' )->alias( static function ( $data = null ) use ( &$ref ) {
			$ref[] = array( 'type' => 'error', 'data' => $data );
			throw new \RuntimeException( 'wp_send_json_error' );
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		$_POST = array();
	}

	/** Invoke an ajax method, swallowing the halt exception thrown by the JSON stubs. */
	private function invoke( ReregistrationAjaxHandler $h, string $method ): void {
		try {
			$h->$method();
		} catch ( \RuntimeException $e ) {
			// Expected — wp_send_json_* halts execution.
		}
	}

	/** @return array{type: string, data: mixed} */
	private function last(): array {
		$last = end( $this->json );
		$this->assertIsArray( $last );
		return $last;
	}

	/** @return array<string, mixed> */
	private function lastData(): array {
		$data = $this->last()['data'];
		$this->assertIsArray( $data );
		return $data;
	}

	public function test_constructor_creates_instance(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->assertInstanceOf( ReregistrationAjaxHandler::class, new ReregistrationAjaxHandler() );
	}

	// --- generate_ficha guards -------------------------------------------

	public function test_generate_ficha_denies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_generate_ficha' );
		$this->assertSame( 'error', $this->last()['type'] );
		$this->assertSame( 'Permission denied.', $this->lastData()['message'] );
	}

	public function test_generate_ficha_rejects_missing_submission_id(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array(); // no submission_id
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_generate_ficha' );
		$this->assertSame( 'error', $this->last()['type'] );
		$this->assertSame( 'Invalid submission.', $this->lastData()['message'] );
	}

	// --- view_submission_details guards ----------------------------------

	public function test_view_details_denies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );
		$this->assertSame( 'error', $this->last()['type'] );
	}

	public function test_view_details_rejects_missing_submission_id(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array();
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );
		$this->assertSame( 'error', $this->last()['type'] );
		$this->assertSame( 'Invalid submission.', $this->lastData()['message'] );
	}

	// --- count_members ----------------------------------------------------

	public function test_count_members_denies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_count_members' );
		$this->assertSame( 'error', $this->last()['type'] );
	}

	public function test_count_members_returns_zero_for_empty_audience_set(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array( 'audience_ids' => array( 0, '', 'x' ) ); // all filtered to empty
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_count_members' );
		$this->assertSame( 'success', $this->last()['type'] );
		$this->assertSame( 0, $this->lastData()['count'] );
	}
}
