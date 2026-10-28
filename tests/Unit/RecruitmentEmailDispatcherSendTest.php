<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Recruitment\RecruitmentEmailDispatcher;
use FreeFormCertificate\Recruitment\RecruitmentPcdHasher;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Happy-path coverage for RecruitmentEmailDispatcher::send_for_call() —
 * the full render + send flow.
 *
 * Everything below the dispatcher is the real class: the five recruitment
 * readers over a `$wpdb` double that answers the call, classification,
 * candidate, notice and adjutancy rows; Encryption with the bootstrap's key
 * constants (the candidate's CPF, RF and e-mail are stored encrypted);
 * RecruitmentPcdHasher over a stubbed `wp_salt()`; DocumentFormatter,
 * DateFormatter, RecruitmentSettings and EmailTemplates reading their
 * options through a stubbed `get_option()`; and EmailService down to a
 * stubbed `wp_mail()`, which records what it was handed.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentEmailDispatcher
 */
class RecruitmentEmailDispatcherSendTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/**
	 * Option values `get_option()` answers, by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/** @var object The candidate row the database holds. */
	private object $candidate;

	/**
	 * Every `wp_mail()` call, as `[ to, subject, body, headers ]`.
	 *
	 * @var list<array{to: string, subject: string, body: string, headers: array<int, string>}>
	 */
	private array $mails = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		// The body is wrapped in the configurable chrome (ffc_email_document
		// → layout.php), which needs these escaping / site helpers.
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'is_email' )->alias( static fn ( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_timezone' )->alias( static fn () => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static function ( $format, $ts = null, $tz = null ) {
				$zone = $tz instanceof \DateTimeZone ? $tz : new \DateTimeZone( 'UTC' );
				return ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $zone )->format( (string) $format );
			}
		);

		// Emails enabled, debug off: everything not set below is the default
		// of an install nobody configured.
		$this->options = array(
			'blogname' => 'Test Site',
			'siteurl'  => 'https://example.test',
		);
		Functions\when( 'get_option' )->alias(
			fn ( $key, $default = false ) => array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default
		);

		$this->mails = array();
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body, $headers = array() ) {
				$this->mails[] = compact( 'to', 'subject', 'body', 'headers' );
				return true;
			}
		);

		$GLOBALS['wpdb'] = $this->database();
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A database holding call 7 of classification 10, which ranks candidate
	 * 100 (Alice) for adjutancy 2 on notice 5.
	 *
	 * @return Mockery\MockInterface
	 */
	private function database() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		// Interpolates, so the reads below can dispatch on table and id.
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				foreach ( $values as $v ) {
					$sql = (string) preg_replace( '/%[ids]/', (string) $v, (string) $sql, 1 );
				}
				return $sql;
			}
		);

		$this->candidate = (object) array(
			'id'              => '100',
			'name'            => 'Alice',
			'cpf_encrypted'   => Encryption::encrypt( Identifiers::CPF_A ),
			'rf_encrypted'    => Encryption::encrypt( Identifiers::RF_A ),
			'email_encrypted' => Encryption::encrypt( 'alice@example.test' ),
			'pcd_hash'        => '',
		);

		$rows = array(
			'wp_ffc_recruitment_call WHERE id = 7'            => (object) array(
				'id'                => '7',
				'classification_id' => '10',
				'called_at'         => 1777968000,
				'date_to_assume'    => '2026-06-01',
				'time_to_assume'    => '08:00:00',
				'notes'             => 'Bring documents',
			),
			'wp_ffc_recruitment_classification WHERE id = 10' => (object) array(
				'id'           => '10',
				'candidate_id' => '100',
				'adjutancy_id' => '2',
				'notice_id'    => '5',
				'rank'         => '1',
				'score'        => '90.0000',
			),
			'wp_ffc_recruitment_candidate WHERE id = 100'     => $this->candidate,
			'wp_ffc_recruitment_notice WHERE id = 5'          => (object) array(
				'id'   => '5',
				'code' => 'EDITAL-2026-01',
				'name' => 'Edital de 2026',
			),
			'wp_ffc_recruitment_adjutancy WHERE id = 2'       => (object) array(
				'id'   => '2',
				'slug' => 'matematica',
				'name' => 'Matemática',
			),
		);

		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) use ( $rows ) {
				foreach ( $rows as $needle => $row ) {
					if ( str_ends_with( (string) $sql, $needle ) ) {
						return $row;
					}
				}
				$this->fail( 'Unexpected get_row(): ' . $sql );
			}
		);

		return $wpdb;
	}

	/**
	 * Store the convocation templates and the recruitment settings, and the
	 * candidate's PCD claim.
	 *
	 * @param array<string, string> $settings Subject, body and From settings.
	 * @param bool                  $is_pcd   The candidate's stored PCD claim.
	 */
	private function configure( array $settings, bool $is_pcd ): void {
		$this->options['ffc_email_bodies']         = array(
			'recruitment-convocation' => array(
				'subject' => $settings['email_subject'],
				'body'    => $settings['email_body_html'],
			),
		);
		$this->options['ffc_recruitment_settings'] = array(
			'email_from_address' => $settings['email_from_address'],
			'email_from_name'    => $settings['email_from_name'],
		);

		$this->candidate->pcd_hash = RecruitmentPcdHasher::compute( 100, $is_pcd );
	}

	/**
	 * The only mail sent.
	 *
	 * @return array{to: string, subject: string, body: string, headers: array<int, string>}
	 */
	private function sent(): array {
		$this->assertCount( 1, $this->mails, 'Exactly one wp_mail() call.' );
		return $this->mails[0];
	}

	public function test_sends_email_with_resolved_tokens_and_from_header(): void {
		$this->configure(
			array(
				'email_subject'      => 'Convocação {{name}} — {{notice_code}}',
				'email_body_html'    => '<p>{{name}}, CPF {{cpf_masked}}, PCD {{is_pcd}}, em {{date_to_assume}} às {{time_to_assume}}. Notas: {{notes}}. {{called_at}} {{site_name}} {{site_url}}</p>',
				'email_from_address' => 'rh@example.test',
				'email_from_name'    => 'Recursos Humanos',
			),
			true
		);

		$result = RecruitmentEmailDispatcher::send_for_call( 7 );

		$this->assertTrue( $result );
		$captured = $this->sent();
		$this->assertSame( 'alice@example.test', $captured['to'], 'The decrypted e-mail is the recipient.' );
		$this->assertSame( 'Convocação Alice — EDITAL-2026-01', $captured['subject'] );
		$masked_cpf = substr( Identifiers::CPF_A, 0, 3 ) . '.***.***-' . substr( Identifiers::CPF_A, -2 );
		$this->assertStringContainsString( 'CPF ' . $masked_cpf, $captured['body'] );
		$this->assertStringNotContainsString( Identifiers::CPF_A, $captured['body'], 'The clear CPF never reaches the body.' );
		$this->assertStringContainsString( 'PCD Yes', $captured['body'] );
		$this->assertStringContainsString( '2026-06-01', $captured['body'] );
		$this->assertStringContainsString( 'Bring documents', $captured['body'] );
		$this->assertStringContainsString( '05/05/2026 08:00', $captured['body'], 'called_at goes through the site\'s date format.' );
		$this->assertStringContainsString( 'Test Site', $captured['body'] );
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $captured['headers'] );
		$this->assertContains( 'From: "Recursos Humanos" <rh@example.test>', $captured['headers'] );
	}

	public function test_sends_email_with_no_from_header_when_address_blank(): void {
		$this->configure(
			array(
				'email_subject'      => 'Sub',
				'email_body_html'    => '<p>{{is_pcd}}</p>',
				'email_from_address' => '',
				'email_from_name'    => 'Ignored',
			),
			false
		);

		$this->assertTrue( RecruitmentEmailDispatcher::send_for_call( 7 ) );
		$captured = $this->sent();
		// Only the Content-Type header; no From: line.
		$this->assertSame( array( 'Content-Type: text/html; charset=UTF-8' ), $captured['headers'] );
		$this->assertStringContainsString( '<p>No</p>', $captured['body'], 'is_pcd false resolves to "No"' );
	}

	public function test_uses_bare_address_when_from_name_blank(): void {
		$this->configure(
			array(
				'email_subject'      => 'Sub',
				'email_body_html'    => '<p>body</p>',
				'email_from_address' => 'noreply@example.test',
				'email_from_name'    => '',
			),
			false
		);

		$this->assertTrue( RecruitmentEmailDispatcher::send_for_call( 7 ) );
		$this->assertContains( 'From: noreply@example.test', $this->sent()['headers'] );
	}
}
