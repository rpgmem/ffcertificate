<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CertificateReadingsExportSource;
use FreeFormCertificate\Core\CertificateSigners;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Tests\Support\CallLog;

/**
 * The CSV export of a certificate form's terminal readings (#2892).
 *
 * @covers \FreeFormCertificate\Admin\CertificateReadingsExportSource
 * @covers \FreeFormCertificate\Frontend\CertificateTerminalReadingRepository::export_batch
 */
class CertificateReadingsExportSourceTest extends TestCase {

	/** @var array<int, string> Capabilities the exporter holds. */
	private array $caps = array();

	/** @var list<string> Queries run. */
	private array $queries = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		foreach ( array( '__', 'esc_html__', 'esc_html' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ) );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'user_can' )->alias( fn( $uid, $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts ) => gmdate( $format, (int) $ts ) );
		Functions\when( 'get_user_meta' )->alias( static fn( $id, $key ) => 7 === $id && CertificateSigners::RF_META_KEY === $key ? Encryption::encrypt( '5181780' ) : '' );
		$user = (object) array( 'display_name' => 'Maria Souza' );
		Functions\when( 'get_userdata' )->alias( static fn( $id ) => 7 === $id ? $user : false );

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				return (string) preg_replace_callback(
					'/%[ids]/',
					static function () use ( &$values ) {
						return (string) array_shift( $values );
					},
					(string) $sql
				);
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				return array(
					array(
						'id'            => 9,
						'form_id'       => 12,
						'user_id'       => 7,
						'terminal_id'   => 2,
						'read_at'       => 1765800000,
						'result'        => 'issued',
						'reason'        => '',
						'missing'       => null,
						'submission_id' => 40,
						'updated_at'    => 1765800000,
						'auth_code'     => 'ABCD1234EFGH',
					),
				);
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturn(
			array(
				'id'         => 2,
				'label'      => 'Main hall',
				'bookings'   => '[]',
				'expires_at' => 0,
			)
		);
		$GLOBALS['wpdb'] = $wpdb;
		$_POST           = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_filters_are_the_screens(): void {
		$_POST = array(
			'form_id' => '12',
			'result'  => 'awaiting_data',
			's'       => '',
		);
		$this->assertSame(
			array(
				'form_id' => 12,
				'result'  => 'awaiting_data',
				'people'  => null,
			),
			( new CertificateReadingsExportSource() )->sanitize_filters()
		);

		$_POST = array(
			'form_id' => '12',
			'result'  => 'bogus',
			's'       => 'maria',
		);
		Functions\when( 'get_users' )->justReturn( array( '7' ) );
		$filters = ( new CertificateReadingsExportSource() )->sanitize_filters();
		$this->assertSame( '', $filters['result'], 'an unknown result filters nothing' );
		$this->assertSame( array( 7 ), $filters['people'], 'the search is resolved to accounts once, at start' );
	}

	public function test_batches_follow_the_id_keyset_newest_first(): void {
		$source = new CertificateReadingsExportSource();
		$rows   = $source->fetch_page(
			array(
				'form_id' => 12,
				'result'  => 'issued',
				'people'  => array( 7 ),
			),
			array(),
			500,
			100
		);

		$this->assertSame( 9, $source->cursor_of( $rows[0] ) );
		$this->assertStringContainsString( 'r.form_id = 12 AND r.result = issued AND r.user_id IN (7) AND r.id < 500 ORDER BY r.id DESC LIMIT 100', end( $this->queries ) );
		$this->assertSame( array(), $source->fetch_page( array( 'form_id' => 0 ), array(), 500, 100 ), 'no form, no rows' );
	}

	public function test_the_rf_is_masked_unless_the_exporter_may_reveal(): void {
		$source = new CertificateReadingsExportSource();
		$row    = $source->fetch_page( array( 'form_id' => 12 ), array(), PHP_INT_MAX, 100 )[0];

		$masked = $source->format_row( $row, array( 'pii' => false ) );
		$this->assertSame( 'Maria Souza', $masked[0] );
		$this->assertStringNotContainsString( '5181780', $masked[1] );
		$this->assertStringNotContainsString( '518.178-0', $masked[1] );
		$this->assertSame( 'Main hall', $masked[3] );
		$this->assertSame( 'Issued', $masked[4] );
		$this->assertSame( 'C-ABCD-1234-EFGH', $masked[6] );

		$this->assertSame( '5181780', $source->format_row( $row, array( 'pii' => true ) )[1] );

		$this->caps = array();
		$this->assertSame( array( 'pii' => false ), $source->build_context( array() ), 'without the PII cap the file is masked' );
	}

	public function test_only_a_file_with_the_rf_in_clear_is_recorded(): void {
		$log = CallLog::record( 'apply_filters', true );
		( new CertificateReadingsExportSource() )->on_complete(
			'job',
			array(
				'context'   => array( 'pii' => false ),
				'processed' => 3,
			)
		);
		$this->assertSame( 0, $log->count(), 'a masked file disclosed nothing' );
	}

	public function test_the_export_needs_the_check_in_capability(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data, $status ) {
				throw new \RuntimeException( (string) $status );
			}
		);
		$this->caps = array( 'ffc_view_certificates' );
		$this->expectExceptionMessage( '403' );
		( new CertificateReadingsExportSource() )->authorize_start();
	}

	public function test_a_batch_belongs_to_whoever_started_it(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data ) {
				throw new \RuntimeException( (string) ( $data['message'] ?? '' ) );
			}
		);
		$this->caps = array( 'ffc_checkin_certificates' );
		$this->expectExceptionMessage( 'Session mismatch.' );
		( new CertificateReadingsExportSource() )->authorize_batch( array( 'user_id' => 2 ) );
	}
}
