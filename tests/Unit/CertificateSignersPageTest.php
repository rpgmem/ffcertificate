<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CertificateSignersPage;
use FreeFormCertificate\Core\CertificateSigners;
use FreeFormCertificate\Core\Encryption;

/**
 * Certificates → Signers (#2582).
 *
 * @covers \FreeFormCertificate\Admin\CertificateSignersPage
 */
class CertificateSignersPageTest extends TestCase {

	/** @var array<int, string> Capabilities the viewer holds. */
	private array $caps = array();

	/** @var mixed */
	private $option = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		foreach ( array( '__', 'esc_html__', 'esc_attr__', 'esc_html', 'esc_attr', 'esc_url', 'wp_kses_post' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );
		Functions\when( 'esc_attr_e' )->alias( static function ( $t ) { echo $t; } );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.test/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'wp_get_attachment_image' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'disabled' )->justReturn( '' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'user_can' )->alias( fn( $uid, $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => CertificateSigners::OPTION === $name ? $this->option : array() );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'get_user_meta' )->alias( static fn( $id, $key ) => 7 === $id ? Encryption::encrypt( '5181780' ) : '' );
		$user = (object) array( 'ID' => 7, 'display_name' => 'Maria Souza', 'user_login' => 'maria', 'user_email' => 'm@example.test' );
		Functions\when( 'get_userdata' )->alias( static fn( $id ) => 7 === $id ? $user : false );
		$this->option = array( array( 'id' => 1, 'user_id' => 7, 'role' => 'Divisão Pedagógica', 'active' => true ) );
	}

	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render(): string {
		ob_start();
		( new CertificateSignersPage() )->render_page();
		return (string) ob_get_clean();
	}

	public function test_a_viewer_sees_the_list_with_the_rf_masked_and_no_form(): void {
		$this->caps = array( 'ffc_view_forms' );
		$html       = $this->render();

		$this->assertStringContainsString( 'ffc-page-certificate-signers', $html );
		$this->assertStringContainsString( 'Maria Souza', $html );
		$this->assertStringNotContainsString( '5181780', $html, 'never in clear' );
		$this->assertStringNotContainsString( '518.178-0', $html );
		$this->assertStringNotContainsString( 'ffc-reveal-pii', $html, 'no Reveal without the PII cap' );
		$this->assertStringNotContainsString( 'Add signer', $html, 'a viewer manages nothing' );
	}

	public function test_a_manager_with_the_pii_cap_gets_reveal_and_the_add_form(): void {
		$this->caps = array( 'ffc_view_forms', 'ffc_manage_forms', 'ffc_view_certificates_pii' );
		$html       = $this->render();

		$this->assertStringContainsString( 'ffc-reveal-pii', $html );
		$this->assertStringContainsString( 'data-type="certificate_signer"', $html );
		$this->assertStringContainsString( 'name="ffc_signer_user"', $html );
		$this->assertStringContainsString( 'ffc_save_certificate_signer', $html );
		$this->assertStringNotContainsString( '518.178-0', $html );
	}

	public function test_the_screen_refuses_without_the_view_cap(): void {
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'die' );
			}
		);
		$this->expectExceptionMessage( 'die' );
		( new CertificateSignersPage() )->render_page();
	}

	/**
	 * Run the search endpoint with `$term`, capturing what get_users() was asked.
	 *
	 * @return array{0: string, 1: mixed, 2: array<string, mixed>|null} Outcome, payload, query args.
	 */
	private function search( string $term, array $users = array() ): array {
		$_POST = array( 'term' => $term, 'nonce' => 'n' );
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		$asked = null;
		Functions\when( 'get_users' )->alias(
			static function ( $args ) use ( &$asked, $users ) {
				$asked = $args;
				return $users;
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data ) {
				throw new \RuntimeException( 'success:' . wp_json_encode( $data ) );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data, $status = null ) {
				throw new \RuntimeException( 'error:' . $status );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		try {
			( new CertificateSignersPage() )->ajax_search_candidates();
		} catch ( \RuntimeException $e ) {
			list( $outcome, $payload ) = explode( ':', $e->getMessage(), 2 );
			return array( $outcome, json_decode( $payload, true ) ?? $payload, $asked );
		}
		$this->fail( 'the endpoint must answer' );
	}

	private function wp_user( int $id, string $name, string $login ): \WP_User {
		$user               = new \WP_User();
		$user->ID           = $id;
		$user->display_name = $name;
		$user->user_login   = $login;
		$user->user_email   = $login . '@example.test';
		return $user;
	}

	public function test_the_search_finds_accounts_with_an_rf_by_name_and_returns_no_pii(): void {
		$this->caps = array( 'ffc_manage_forms' );
		list( $outcome, $payload, $asked ) = $this->search( ' mar ', array( $this->wp_user( 9, 'Mário Lima', 'mario' ) ) );

		$this->assertSame( 'success', $outcome );
		$this->assertSame( array( 'users' => array( array( 'id' => 9, 'label' => 'Mário Lima (mario)' ) ) ), $payload, 'name and login only: no e-mail, no RF' );
		$this->assertSame( '*mar*', $asked['search'] );
		$this->assertSame( array( 'user_login', 'user_email', 'display_name', 'user_nicename' ), $asked['search_columns'] );
		$this->assertSame( CertificateSigners::RF_META_KEY, $asked['meta_key'], 'only accounts with an RF on the profile' );
		$this->assertSame( 'EXISTS', $asked['meta_compare'] );
		$this->assertSame( array( 7 ), $asked['exclude'], 'a current signer is never offered again' );
		$this->assertSame( 20, $asked['number'] );
	}

	public function test_a_full_rf_is_matched_through_the_profile_hash(): void {
		$this->caps = array( 'ffc_manage_forms' );
		$wpdb       = new class() {
			/** @var string */
			public $prefix = 'wp_';
			/** @var array<int, mixed> */
			public $asked = array();
			public function prepare( $sql, ...$args ) {
				$this->asked = $args;
				return $sql;
			}
			public function get_col( $sql ) {
				return array( '9' );
			}
		};
		$GLOBALS['wpdb'] = $wpdb;
		list( $outcome, , $asked ) = $this->search( '518.178-0', array( $this->wp_user( 9, 'Mário Lima', 'mario' ) ) );

		$this->assertSame( 'success', $outcome );
		$this->assertSame( array( 9 ), $asked['include'], 'the accounts whose profile carries that RF' );
		$this->assertArrayNotHasKey( 'search', $asked, 'an RF is not searched as text' );
		$this->assertSame( 'rf_hash', $wpdb->asked[1] );
		$this->assertSame( \FreeFormCertificate\Core\SensitiveFieldRegistry::hash_identifier( 'rf', '5181780' ), $wpdb->asked[2] );
	}

	public function test_an_rf_nobody_carries_finds_nobody(): void {
		$this->caps = array( 'ffc_manage_forms' );
		$GLOBALS['wpdb'] = new class() {
			/** @var string */
			public $prefix = 'wp_';
			public function prepare( $sql, ...$args ) {
				return $sql;
			}
			public function get_col( $sql ) {
				return array();
			}
		};
		list( $outcome, $payload, $asked ) = $this->search( '1234567' );
		$this->assertSame( 'success', $outcome );
		$this->assertSame( array( 'users' => array() ), $payload );
		$this->assertNull( $asked, 'no account query runs' );
	}

	public function test_the_search_refuses_without_the_cap_and_a_short_term(): void {
		$this->caps = array( 'ffc_view_forms' );
		$this->assertSame( array( 'error', 403, null ), $this->search( 'maria' ) );

		$this->caps = array( 'ffc_manage_forms' );
		$this->assertSame( array( 'error', 400, null ), $this->search( ' m ' ) );
	}

	public function test_the_add_form_offers_the_search_instead_of_a_list(): void {
		$this->caps = array( 'ffc_view_forms', 'ffc_manage_forms' );
		$html       = $this->render();
		$this->assertStringContainsString( 'data-ffc-signer-user-search', $html );
		$this->assertStringContainsString( 'role="combobox"', $html );
		$this->assertStringContainsString( '<input type="hidden" name="ffc_signer_user" value="">', $html );
		$this->assertStringNotContainsString( '<select name="ffc_signer_user"', $html );
	}
}
