<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentEmailDispatcher;

/**
 * Happy-path coverage for RecruitmentEmailDispatcher::send_for_call() —
 * the full render + send flow with the repositories, Encryption,
 * RecruitmentPcdHasher, DocumentFormatter, DateFormatter and
 * RecruitmentSettings stubbed via alias mocks.
 *
 * Kept in its own file so the alias-backed static stubs run under a
 * dedicated process (the sibling RecruitmentEmailDispatcherTest exercises
 * the early-return branches against real helper classes and must NOT be
 * process-isolated).
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentEmailDispatcher
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentEmailDispatcherSendTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		// The body is now wrapped in the configurable chrome (ffc_email_document
		// → layout.php), which needs these escaping / site helpers.
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'wp_date' )->justReturn( '2026' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = '' ) {
				if ( 'blogname' === $key ) {
					return 'Test Site';
				}
				if ( 'siteurl' === $key ) {
					return 'https://example.test';
				}
				return $default;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function call_stub(): object {
		return (object) array(
			'id'                => '7',
			'classification_id' => '10',
			'called_at'         => 1777968000,
			'date_to_assume'    => '2026-06-01',
			'time_to_assume'    => '08:00:00',
			'notes'             => 'Bring documents',
		);
	}

	private function classification_stub(): object {
		return (object) array(
			'id'           => '10',
			'candidate_id' => '100',
			'adjutancy_id' => '2',
			'notice_id'    => '5',
			'rank'         => '1',
			'score'        => '90.0000',
		);
	}

	private function candidate_stub(): object {
		return (object) array(
			'id'              => '100',
			'name'            => 'Alice',
			'cpf_encrypted'   => 'cpf-cipher',
			'rf_encrypted'    => 'rf-cipher',
			'email_encrypted' => 'email-cipher',
			'pcd_hash'        => 'pcd-hash',
		);
	}

	private function notice_stub(): object {
		return (object) array(
			'id'   => '5',
			'code' => 'EDITAL-2026-01',
			'name' => 'Edital de 2026',
		);
	}

	private function adjutancy_stub(): object {
		return (object) array(
			'id'   => '2',
			'slug' => 'matematica',
			'name' => 'Matemática',
		);
	}

	/**
	 * Wire the alias mocks shared by the send tests.
	 *
	 * @param array<string, mixed> $settings Settings::all() return.
	 * @param bool                 $is_pcd   PcdHasher::verify return.
	 * @return void
	 */
	private function wire_helpers( array $settings, bool $is_pcd ): void {
		$call_repo = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCallReader' );
		$call_repo->shouldReceive( 'get_by_id' )->andReturn( $this->call_stub() );

		$cls_repo = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationRepository' );
		$cls_repo->shouldReceive( 'get_by_id' )->andReturn( $this->classification_stub() );

		$cand_repo = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateReader' );
		$cand_repo->shouldReceive( 'get_by_id' )->andReturn( $this->candidate_stub() );

		$notice_repo = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeReader' );
		$notice_repo->shouldReceive( 'get_by_id' )->andReturn( $this->notice_stub() );

		$adj_repo = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader' );
		$adj_repo->shouldReceive( 'get_by_id' )->andReturn( $this->adjutancy_stub() );

		$enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
		$enc->shouldReceive( 'decrypt' )->andReturnUsing(
			static function ( $cipher ) {
				$map = array(
					'cpf-cipher'   => '12345678909',
					'rf-cipher'    => '1234567',
					'email-cipher' => 'alice@example.test',
				);
				return $map[ $cipher ] ?? null;
			}
		);

		$pcd = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentPcdHasher' );
		$pcd->shouldReceive( 'verify' )->andReturn( $is_pcd );

		$doc = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
		$doc->shouldReceive( 'mask_cpf' )->andReturn( '***.***.***-09' );
		$doc->shouldReceive( 'mask_rf' )->andReturn( '***4567' );
		$doc->shouldReceive( 'mask_email' )->andReturn( 'a***@example.test' );

		$date = Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' );
		$date->shouldReceive( 'format_datetime' )->andReturn( '05/05/2026 00:00' );
		// The chrome footer's {{date}} token calls format_date().
		$date->shouldReceive( 'format_date' )->andReturn( '05/05/2026' );

		$settings_mock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentSettings' );
		$settings_mock->shouldReceive( 'all' )->andReturn( $settings );
	}

	public function test_sends_email_with_resolved_tokens_and_from_header(): void {
		$this->wire_helpers(
			array(
				'email_subject'      => 'Convocação {{name}} — {{notice_code}}',
				'email_body_html'    => '<p>{{name}}, CPF {{cpf_masked}}, PCD {{is_pcd}}, em {{date_to_assume}} às {{time_to_assume}}. Notas: {{notes}}. {{called_at}} {{site_name}} {{site_url}}</p>',
				'email_from_address' => 'rh@example.test',
				'email_from_name'    => 'Recursos Humanos',
			),
			true
		);

		$captured = array();
		Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subject, $body, $headers ) use ( &$captured ) {
				$captured = compact( 'to', 'subject', 'body', 'headers' );
				return true;
			}
		);

		$result = RecruitmentEmailDispatcher::send_for_call( 7 );

		$this->assertTrue( $result );
		$this->assertSame( 'alice@example.test', $captured['to'] );
		$this->assertSame( 'Convocação Alice — EDITAL-2026-01', $captured['subject'] );
		$this->assertStringContainsString( '***.***.***-09', $captured['body'] );
		$this->assertStringContainsString( 'PCD Yes', $captured['body'] );
		$this->assertStringContainsString( '2026-06-01', $captured['body'] );
		$this->assertStringContainsString( 'Bring documents', $captured['body'] );
		$this->assertStringContainsString( 'Test Site', $captured['body'] );
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $captured['headers'] );
		$this->assertContains( 'From: "Recursos Humanos" <rh@example.test>', $captured['headers'] );
	}

	public function test_sends_email_with_no_from_header_when_address_blank(): void {
		$this->wire_helpers(
			array(
				'email_subject'      => 'Sub',
				'email_body_html'    => '<p>{{is_pcd}}</p>',
				'email_from_address' => '',
				'email_from_name'    => 'Ignored',
			),
			false
		);

		$captured = array();
		Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subject, $body, $headers ) use ( &$captured ) {
				$captured = compact( 'headers', 'body' );
				return true;
			}
		);

		$this->assertTrue( RecruitmentEmailDispatcher::send_for_call( 7 ) );
		// Only the Content-Type header; no From: line.
		$this->assertCount( 1, $captured['headers'] );
		$this->assertStringContainsString( 'No', $captured['body'], 'is_pcd false resolves to "No"' );
	}

	public function test_uses_bare_address_when_from_name_blank(): void {
		$this->wire_helpers(
			array(
				'email_subject'      => 'Sub',
				'email_body_html'    => '<p>body</p>',
				'email_from_address' => 'noreply@example.test',
				'email_from_name'    => '',
			),
			false
		);

		$captured = array();
		Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subject, $body, $headers ) use ( &$captured ) {
				$captured = $headers;
				return true;
			}
		);

		$this->assertTrue( RecruitmentEmailDispatcher::send_for_call( 7 ) );
		$this->assertContains( 'From: noreply@example.test', $captured );
	}
}
