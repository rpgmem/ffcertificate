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

	/** @var object The classification row the call points at; PCD lives here (#2245). */
	private object $classification;

	/**
	 * Every `wp_mail()` call, with the attachments' content read at send
	 * time (the dispatcher deletes the temp file right after).
	 *
	 * @var list<array{to: string, subject: string, body: string, headers: array<int, string>, attachments: list<string>, files: list<string>}>
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
		Functions\when( 'home_url' )->alias( static fn ( $path = '' ) => 'https://example.test' . $path );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $value = null, $url = null ) {
				if ( ! is_array( $args ) ) {
					$args = array( $args => $value );
				} else {
					$url = $value;
				}
				return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
			}
		);
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/painel/' );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		// The dispatcher guards on function_exists( 'wp_tempnam' ), so the branch
		// is chosen here rather than inherited from whichever test ran first.
		Functions\when( 'wp_tempnam' )->alias( static fn ( $name = '' ) => (string) tempnam( sys_get_temp_dir(), 'ffc-test-' ) );
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
			function ( $to, $subject, $body, $headers = array(), $attachments = array() ) {
				$files         = array_map( static fn ( $f ) => (string) file_get_contents( $f ), (array) $attachments );
				$attachments   = (array) $attachments;
				$this->mails[] = compact( 'to', 'subject', 'body', 'headers', 'attachments', 'files' );
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
			'user_id'         => null,
			'name'            => 'Alice',
			'cpf_encrypted'   => Encryption::encrypt( Identifiers::CPF_A ),
			'rf_encrypted'    => Encryption::encrypt( Identifiers::RF_A ),
			'email_encrypted' => Encryption::encrypt( 'alice@example.test' ),
			'pcd_hash'        => '',
		);

		$this->classification = (object) array(
			'id'           => '10',
			'candidate_id' => '100',
			'adjutancy_id' => '2',
			'notice_id'    => '5',
			'rank'         => '1',
			'score'        => '90.0000',
			'pcd_hash'     => '',
		);

		$rows = array(
			'wp_ffc_recruitment_call WHERE id = 7'            => (object) array(
				'id'                => '7',
				'classification_id' => '10',
				'called_at'         => 1777968000,
				'date_to_assume'    => '2026-06-01',
				'time_to_assume'    => '08:00:00',
				'notes'             => 'Bring documents',
				'cancelled_at'      => null,
			),
			'wp_ffc_recruitment_classification WHERE id = 10' => $this->classification,
			'wp_ffc_recruitment_candidate WHERE id = 100'     => $this->candidate,
			'wp_ffc_recruitment_notice WHERE id = 5'          => (object) array(
				'id'     => '5',
				'code'   => 'EDITAL-2026-01',
				'name'   => 'Edital de 2026',
				'status' => 'definitive',
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
	 * classification's PCD claim.
	 *
	 * The candidate row carries the opposite claim, so a dispatcher still
	 * reading the candidate would resolve the wrong answer (#2245).
	 *
	 * @param array<string, string> $settings Subject, body and From settings.
	 * @param bool                  $is_pcd   The classification's PCD claim.
	 */
	private function configure( array $settings, bool $is_pcd, int $response_days = 0 ): void {
		$this->options['ffc_email_bodies']         = array(
			'recruitment-convocation' => array(
				'subject' => $settings['email_subject'],
				'body'    => $settings['email_body_html'],
			),
		);
		$this->options['ffc_recruitment_settings'] = array(
			'email_from_address' => $settings['email_from_address'],
			'email_from_name'    => $settings['email_from_name'],
			'response_days'      => $response_days,
		);

		$this->classification->pcd_hash = RecruitmentPcdHasher::compute_for_row( 5, 100, 2, $is_pcd );
		$this->candidate->pcd_hash      = RecruitmentPcdHasher::compute( 100, ! $is_pcd );
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
		$this->assertStringContainsString( 'em 01/06/2026 às 08:00', $captured['body'], 'The presentation date and time go through the site\'s formats.' );
		$this->assertStringContainsString( 'Bring documents', $captured['body'] );
		$this->assertStringContainsString( '05/05/2026 08:00', $captured['body'], 'called_at goes through the site\'s date format.' );
		$this->assertStringContainsString( 'Test Site', $captured['body'] );
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $captured['headers'] );
		$this->assertContains( 'From: "Recursos Humanos" <rh@example.test>', $captured['headers'] );
	}

	public function test_escapes_every_token_value_in_the_body_and_none_in_the_subject(): void {
		Functions\when( 'esc_html' )->alias( static fn ( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		$this->candidate->name = 'Ana <script>x</script>';
		$this->configure(
			array(
				'email_subject'      => 'Call for {{name}}',
				'email_body_html'    => '<p>{{name}} — {{notes}}</p>',
				'email_from_address' => '',
				'email_from_name'    => '',
			),
			false
		);

		$this->assertTrue( RecruitmentEmailDispatcher::send_for_call( 7 ) );
		$captured = $this->sent();
		$this->assertStringContainsString( '<p>Ana &lt;script&gt;x&lt;/script&gt; — Bring documents</p>', $captured['body'], 'A name from the CSV is text in the HTML body, never markup.' );
		$this->assertStringNotContainsString( '<script>', $captured['body'] );
		$this->assertSame( 'Call for Ana <script>x</script>', $captured['subject'], 'The subject is a plain-text header: no entity reaches it.' );
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

	public function test_convocation_attaches_the_call_ics_and_removes_the_temp_file(): void {
		$this->configure(
			array(
				'email_subject'      => 'Sub',
				'email_body_html'    => '<p>body</p>',
				'email_from_address' => '',
				'email_from_name'    => '',
			),
			false
		);

		$this->assertTrue( RecruitmentEmailDispatcher::send_for_call( 7 ) );
		$captured = $this->sent();
		$this->assertCount( 1, $captured['attachments'], 'The call .ics travels with the convocation.' );
		$this->assertStringContainsString( 'BEGIN:VCALENDAR', $captured['files'][0] );
		$this->assertStringContainsString( 'UID:ffc-recruitment-call-7', $captured['files'][0] );
		$this->assertFileDoesNotExist( $captured['attachments'][0], 'The temp file is deleted after the send.' );
	}

	public function test_cancellation_attaches_nothing(): void {
		$this->options['ffc_email_bodies'] = array(
			'recruitment-cancellation' => array(
				'subject' => 'Cancelled',
				'body'    => '<p>{{set_password_url}}|{{name}}</p>',
			),
		);
		$this->classification->pcd_hash    = RecruitmentPcdHasher::compute_for_row( 5, 100, 2, false );

		$this->assertTrue( RecruitmentEmailDispatcher::send_for_cancellation( 7 ) );
		$captured = $this->sent();
		$this->assertSame( array(), $captured['attachments'] );
		$this->assertStringContainsString( '<p>|Alice</p>', $captured['body'], 'A cancellation issues no password link.' );
	}

	public function test_links_to_my_calls_and_falls_back_there_without_an_account(): void {
		$this->options['ffc_dashboard_page_id'] = 42;
		$this->configure(
			array(
				'email_subject'      => 'Sub',
				'email_body_html'    => '<p>{{my_calls_url}}|{{set_password_url}}</p>',
				'email_from_address' => '',
				'email_from_name'    => '',
			),
			false
		);

		$this->assertTrue( RecruitmentEmailDispatcher::send_for_call( 7 ) );
		$this->assertStringContainsString(
			'<p>https://example.test/painel/?tab=recruitment|https://example.test/painel/?tab=recruitment</p>',
			$this->sent()['body'],
			'A candidate with no account yet is sent to My calls, never to a dead link.'
		);
	}

	public function test_respond_by_line_names_the_deadline_and_never_passes_the_presentation(): void {
		// Called on 2026-05-05 (1777968000); the presentation is 2026-06-01.
		$this->configure(
			array(
				'email_subject'      => 'Sub',
				'email_body_html'    => '<p>[{{respond_by_line}}]</p>',
				'email_from_address' => '',
				'email_from_name'    => '',
			),
			false,
			3
		);
		$this->assertTrue( RecruitmentEmailDispatcher::send_for_call( 7 ) );
		$this->assertStringContainsString( '<p>[Please answer by 08/05/2026.]</p>', $this->sent()['body'] );

		$this->mails = array();
		$this->configure(
			array(
				'email_subject'      => 'Sub',
				'email_body_html'    => '<p>[{{respond_by_line}}]</p>',
				'email_from_address' => '',
				'email_from_name'    => '',
			),
			false,
			60
		);
		$this->assertTrue( RecruitmentEmailDispatcher::send_for_call( 7 ) );
		$this->assertStringContainsString( '<p>[Please answer by 01/06/2026.]</p>', $this->sent()['body'], 'The deadline stops at the presentation date.' );
	}

	public function test_respond_by_line_is_empty_without_a_deadline(): void {
		$this->configure(
			array(
				'email_subject'      => 'Sub',
				'email_body_html'    => '<p>[{{respond_by_line}}]</p>',
				'email_from_address' => '',
				'email_from_name'    => '',
			),
			false
		);
		$this->assertTrue( RecruitmentEmailDispatcher::send_for_call( 7 ) );
		$this->assertStringContainsString( '<p>[]</p>', $this->sent()['body'] );
	}

	public function test_issues_a_password_link_for_the_linked_account(): void {
		$this->candidate->user_id = '55';
		$user                     = new \WP_User( 55 );
		$user->user_login         = 'alice';
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_password_reset_key' )->justReturn( 'k3y' );
		$this->configure(
			array(
				'email_subject'      => 'Sub',
				'email_body_html'    => '<p>{{set_password_url}}</p>',
				'email_from_address' => '',
				'email_from_name'    => '',
			),
			false
		);

		$this->assertTrue( RecruitmentEmailDispatcher::send_for_call( 7 ) );
		$body = $this->sent()['body'];
		$this->assertStringContainsString( 'ffc_key=k3y', $body, 'An account the import created gets the link that sets its first password.' );
		$this->assertStringContainsString( 'ffc_login=alice', $body );
	}
}
