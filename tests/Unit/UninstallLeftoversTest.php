<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * What a full uninstall removes beyond its named lists (#2343).
 *
 * Read off `uninstall.php` as text, like the sibling sweep tests: the file is
 * never included, because including it would run the uninstaller. The
 * behaviour is proven where a database exists -- the `fresh-install` job runs a
 * real uninstall and asserts the plugin's footprint is zero.
 *
 * @coversNothing
 */
class UninstallLeftoversTest extends TestCase {

	private function source(): string {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
		$this->assertNotSame( '', $source, 'Could not read uninstall.php.' );
		return $source;
	}

	/**
	 * Every post type the plugin registers is deleted.
	 *
	 * `ffc_cert_template` was registered and never deleted; nothing compared
	 * the two. The registered types are read from the code that registers them.
	 */
	public function test_every_registered_post_type_is_deleted(): void {
		$root       = dirname( __DIR__, 2 );
		$registered = array();
		$iterator   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$code = (string) file_get_contents( $file->getPathname() );
			if ( ! preg_match_all( '/register_post_type\(\s*(self::POST_TYPE|\'([a-z_]+)\')/', $code, $calls, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $calls as $call ) {
				if ( isset( $call[2] ) && '' !== $call[2] ) {
					$registered[] = $call[2];
				} elseif ( preg_match( "/const POST_TYPE\\s*=\\s*'([a-z_]+)'/", $code, $const ) ) {
					$registered[] = $const[1];
				}
			}
		}
		$registered = array_values( array_unique( $registered ) );
		sort( $registered );

		$this->assertContains( 'ffc_form', $registered, 'The scan found no registration -- it collapsed.' );
		$this->assertGreaterThanOrEqual( 3, count( $registered ), 'The scan lost a registration.' );

		$this->assertSame( 1, preg_match( '/foreach \( array\(([^)]*)\) as \$ffcertificate_cpt \)/', $this->source(), $loop ), 'The post-type deletion loop did not parse.' );
		preg_match_all( "/'([a-z_]+)'/", $loop[1], $deleted );

		$this->assertSame( array(), array_values( array_diff( $registered, $deleted[1] ) ), 'A registered post type outlives a full uninstall.' );
	}

	public function test_options_and_site_transients_are_swept_by_prefix(): void {
		$source = $this->source();

		$this->assertStringContainsString( "\$wpdb->esc_like( 'ffc_' ) . '%'", $source, 'Options are swept by an escaped prefix, not only by the named list.' );
		$this->assertStringContainsString( '\\\\_site\\\\_transient\\\\_ffc\\\\_%', $source, 'Site transients are stored under their own prefix.' );
	}

	public function test_only_unedited_managed_pages_are_deleted(): void {
		$source = $this->source();

		$this->assertStringContainsString( "'meta_key'       => '_ffc_managed_page'", $source );
		$this->assertStringContainsString( "array( '[ffc_verification]', '[user_dashboard_personal]' )", $source, 'A page is deleted only while its body is the shortcode the activation wrote.' );
	}

	public function test_a_former_end_user_receives_the_default_role(): void {
		$source = $this->source();

		$this->assertStringContainsString( "get_option( 'default_role', 'subscriber' )", $source );
		$this->assertMatchesRegularExpression( '/\$ffcertificate_was_end_user && array\(\) === \$ffcertificate_user->roles/', $source, 'Only an account left with no role is given one.' );
	}
}
