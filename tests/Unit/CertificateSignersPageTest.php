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
		$_GET = array();
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
}
