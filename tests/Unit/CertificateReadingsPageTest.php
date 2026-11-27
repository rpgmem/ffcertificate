<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CertificateReadingsPage;
use FreeFormCertificate\Core\CertificateSigners;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Frontend\CertificateTerminalReadingRepository;

/**
 * A certificate form's terminal readings (#2892).
 *
 * @covers \FreeFormCertificate\Admin\CertificateReadingsPage
 * @covers \FreeFormCertificate\Frontend\CertificateTerminalReadingRepository
 */
class CertificateReadingsPageTest extends TestCase {

	/** @var array<int, string> Capabilities the viewer holds. */
	private array $caps = array();

	/** @var list<string> Queries the screen ran. */
	private array $queries = array();

	/** @var list<array<string, mixed>> Reading rows the list query returns. */
	private array $readings = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		foreach ( array( '__', 'esc_html__', 'esc_attr__', 'esc_html', 'esc_attr', 'esc_url', 'wp_kses_post' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === (int) $n ? $single : $plural );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.test/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $value = null, $url = null ) {
				if ( is_array( $args ) ) {
					return $value . '?' . http_build_query( $args );
				}
				return $url . '&' . $args . '=' . $value;
			}
		);
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts ) => gmdate( $format, (int) $ts ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ) );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'selected' )->alias( static fn( $a, $b ) => (string) $a === (string) $b ? ' selected' : '' );
		Functions\when( 'paginate_links' )->justReturn( '' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'user_can' )->alias( fn( $uid, $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_title' )->justReturn( 'Inclusive Education Seminar' );
		Functions\when( 'get_edit_post_link' )->justReturn( 'https://example.test/wp-admin/post.php?post=12&action=edit' );
		Functions\when( 'get_user_meta' )->alias( static fn( $id, $key ) => 7 === $id && CertificateSigners::RF_META_KEY === $key ? Encryption::encrypt( '5181780' ) : '' );
		$user = (object) array( 'ID' => 7, 'display_name' => 'Maria Souza' );
		Functions\when( 'get_userdata' )->alias( static fn( $id ) => 7 === $id ? $user : false );
		$form            = new \WP_Post();
		$form->ID        = 12;
		$form->post_type = 'ffc_form';
		Functions\when( 'get_post' )->alias( static fn( $id ) => 12 === $id ? $form : null );
		Functions\when( 'wp_die' )->alias(
			static function ( $message ) {
				throw new \RuntimeException( (string) $message );
			}
		);
		$GLOBALS['wpdb'] = $this->database();
		$_GET            = array(
			'page'    => CertificateReadingsPage::MENU_SLUG,
			'form_id' => '12',
		);
	}

	protected function tearDown(): void {
		$_GET = array();
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A `$wpdb` double: `prepare()` interpolates, and each read answers by
	 * the table it names.
	 *
	 * @return Mockery\MockInterface
	 */
	private function database() {
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
				if ( false !== strpos( $sql, 'ffc_audience_terminals' ) ) {
					return array(
						array(
							'id'           => 2,
							'label'        => 'Block B entrance',
							'bookings'     => '["certificate_form:12"]',
							'paired_at'    => time() - 7200,
							'expires_at'   => time() + 7200,
							'last_seen_at' => time() - 3600,
							'queued'       => 9,
						),
					);
				}
				if ( false !== strpos( $sql, 'GROUP BY result' ) ) {
					return array(
						array(
							'result' => 'issued',
							'total'  => '131',
						),
						array(
							'result' => 'awaiting_data',
							'total'  => '7',
						),
						array(
							'result' => 'refused',
							'total'  => '5',
						),
					);
				}
				if ( false !== strpos( $sql, 'LEFT JOIN' ) ) {
					return $this->readings;
				}
				$this->fail( 'Unexpected get_results(): ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				return (string) count( $this->readings );
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				return false !== strpos( $sql, 'ffc_audience_terminals' ) ? array(
					'id'         => 2,
					'label'      => 'Block B entrance',
					'bookings'   => '[]',
					'expires_at' => time() + 7200,
				) : null;
			}
		);
		return $wpdb;
	}

	private function render(): string {
		ob_start();
		try {
			( new CertificateReadingsPage() )->render_page();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	private function reading( array $fields ): array {
		return $fields + array(
			'id'            => 1,
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
		);
	}

	public function test_the_screen_needs_the_check_in_capability(): void {
		$this->caps = array( 'ffc_view_certificates' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'permission to see the terminal readings' );
		$this->render();
	}

	public function test_a_form_that_is_not_a_certificate_form_is_refused(): void {
		$this->caps       = array( CertificateReadingsPage::CAP );
		$_GET['form_id'] = '99';
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'does not exist' );
		$this->render();
	}

	public function test_the_screen_counts_lists_and_masks(): void {
		$this->caps     = array( CertificateReadingsPage::CAP );
		$this->readings = array(
			$this->reading( array() ),
			$this->reading(
				array(
					'id'            => 2,
					'result'        => 'refused',
					'reason'        => 'ffc_terminal_window_closed',
					'submission_id' => null,
					'auth_code'     => null,
				)
			),
		);
		$html = $this->render();

		$this->assertStringContainsString( 'ffc-page-certificate-readings', $html );
		$this->assertStringContainsString( 'Terminal readings · Inclusive Education Seminar', $html );
		$this->assertStringContainsString( '1 terminal', $html, 'the head counts the terminals holding the form' );
		$this->assertStringContainsString( '#ffc-tab-terminal', $html, 'Back returns to the Terminal tab' );
		foreach ( array( '143', '131', '7', '5' ) as $count ) {
			$this->assertMatchesRegularExpression( '/ffc-stat-card__value">' . $count . '</', $html, 'the four counts' );
		}
		$this->assertStringContainsString( '<strong>Block B entrance</strong> is offline with 9 readings in its queue', $html, 'an offline terminal holding readings is named' );
		$this->assertStringContainsString( 'Maria Souza', $html );
		$this->assertStringNotContainsString( '5181780', $html, 'the RF is never in clear' );
		$this->assertStringNotContainsString( 'ffc-reveal-pii', $html, 'no Reveal without the PII cap' );
		$this->assertStringContainsString( 'C-ABCD-1234-EFGH', $html, 'the certificate\'s validation code' );
		$this->assertStringContainsString( 'ffc-reading-result-refused', $html );
		$this->assertStringContainsString( 'The form was closed', $html, 'a refusal says why' );
	}

	public function test_the_pii_cap_adds_the_audited_reveal(): void {
		$this->caps     = array( CertificateReadingsPage::CAP, CertificateReadingsPage::PII_CAP );
		$this->readings = array( $this->reading( array( 'id' => 6 ) ) );
		$html           = $this->render();

		$this->assertStringContainsString( 'data-type="' . CertificateReadingsPage::REVEAL_TYPE . '"', $html );
		$this->assertStringContainsString( 'data-submission-id="6"', $html, 'the reveal names the reading, not the account' );
		$this->assertStringNotContainsString( '5181780', $html, 'masked until revealed' );
	}

	public function test_an_empty_search_or_filter_says_so(): void {
		$this->caps   = array( CertificateReadingsPage::CAP );
		$_GET['result'] = 'refused';
		$html           = $this->render();

		$this->assertStringContainsString( 'No reading matches.', $html );
		$this->assertStringContainsString( 'value="refused" selected', $html, 'the filter keeps its choice' );
		$list = array_values( array_filter( $this->queries, static fn( $sql ) => false !== strpos( $sql, 'LEFT JOIN' ) ) );
		$this->assertStringContainsString( "AND r.result = refused", $list[0] ?? '', 'the result narrows the query' );
	}

	public function test_a_search_matching_nobody_runs_no_list_query(): void {
		Functions\when( 'get_users' )->justReturn( array() );
		$this->assertSame( array(), CertificateTerminalReadingRepository::page( 12, '', array(), 50, 0 ) );
		$this->assertSame( 0, CertificateTerminalReadingRepository::count_matching( 12, '', array() ) );
		$this->assertSame( array(), $this->queries, 'an empty list of people is answered without a query' );
	}

	public function test_a_search_narrows_to_the_accounts_it_names(): void {
		Functions\when( 'get_users' )->justReturn( array( '7', '8' ) );
		$this->assertSame( array( 7, 8 ), CertificateReadingsPage::matching_accounts( 'maria' ) );

		CertificateTerminalReadingRepository::count_matching( 12, 'bogus', array( 7, 8 ) );
		$this->assertSame( 'SELECT COUNT(*) FROM wp_ffc_certificate_terminal_readings r WHERE r.form_id = 12 AND r.user_id IN (7, 8)', end( $this->queries ), 'an unknown result filters nothing' );
	}

	public function test_what_a_result_waits_on_or_why_it_was_refused(): void {
		$this->assertSame( 'Missing: phone, rf', CertificateReadingsPage::why( array( 'result' => 'awaiting_data', 'missing' => array( 'phone', 'rf' ) ) ) );
		$this->assertSame( 'Waiting on profile data', CertificateReadingsPage::why( array( 'result' => 'awaiting_data' ) ) );
		$this->assertSame( 'Submission limit reached', CertificateReadingsPage::why( array( 'result' => 'refused', 'reason' => 'ffc_terminal_limit' ) ) );
		$this->assertSame( '', CertificateReadingsPage::why( array( 'result' => 'refused', 'reason' => 'unknown' ) ) );
		$this->assertSame( '', CertificateReadingsPage::why( array( 'result' => 'issued' ) ) );
		$this->assertSame( 'Awaiting data', CertificateReadingsPage::result_label( 'awaiting_data' ) );
	}
}
