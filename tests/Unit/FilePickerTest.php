<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminUI;

/**
 * The plugin's one file field (#2038): the helper's markup, and a guard that
 * no other file input is printed.
 *
 * @covers \FreeFormCertificate\Admin\AdminUI::file_picker
 * @covers \FreeFormCertificate\Admin\AdminUI::render_file_picker
 */
class FilePickerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Files that print a file input of their own, each with why.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = array(
		'includes/admin/class-ffc-admin-ui.php'                => 'The helper itself.',
		'includes/admin/class-ffc-form-editor-layout-metabox.php' => 'A hidden input behind the "Import HTML" family button: picking the file IS the action, so there is no file name to show.',
		'assets/js/ffc-admin-pdf.js'                          => 'The same import, its fallback input built on the fly and never shown.',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_attr' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'esc_html' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_prints_the_input_then_its_label_as_a_family_button(): void {
		$html = AdminUI::file_picker(
			array(
				'id'       => 'csv',
				'name'     => 'csv_file',
				'accept'   => '.csv',
				'required' => true,
				'class'    => 'my-hook',
			)
		);

		$this->assertSame(
			'<span class="ffc-file-picker"><input type="file" id="csv" class="ffc-file-picker-input my-hook" name="csv_file" accept=".csv" required>'
			. '<label for="csv" class="ffc-btn ffc-btn-neutral ffc-icon-upload ffc-file-picker-button">Choose file</label></span>',
			$html
		);
	}

	public function test_optional_attributes_are_left_out_and_the_label_can_change(): void {
		$html = AdminUI::file_picker(
			array(
				'id'    => 'x',
				'label' => 'Pick a "CSV"',
			)
		);

		$this->assertStringNotContainsString( 'name=', $html );
		$this->assertStringNotContainsString( 'accept=', $html );
		$this->assertStringNotContainsString( 'required', $html );
		$this->assertStringContainsString( '>Pick a &quot;CSV&quot;</label>', $html );
	}

	public function test_an_input_without_an_id_gets_one_its_label_points_at(): void {
		Functions\when( 'wp_unique_id' )->alias( static fn( string $prefix ): string => $prefix . '7' );

		$html = AdminUI::file_picker( array() );

		$this->assertStringContainsString( 'id="ffc-file-7"', $html );
		$this->assertStringContainsString( 'for="ffc-file-7"', $html );
	}

	public function test_attributes_are_escaped(): void {
		$html = AdminUI::file_picker(
			array(
				'id'   => 'a"b',
				'name' => '"><script>',
			)
		);

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'id="a&quot;b"', $html );
	}

	public function test_render_prints_the_same_markup(): void {
		ob_start();
		AdminUI::render_file_picker( array( 'id' => 'r' ) );
		$this->assertSame( AdminUI::file_picker( array( 'id' => 'r' ) ), (string) ob_get_clean() );
	}

	public function test_no_file_input_is_printed_outside_the_helper(): void {
		$found = $this->files_printing_a_file_input();

		// Self-check: the scan must reach the helper and every allowed file,
		// or an empty result would read as clean.
		foreach ( array_keys( self::ALLOWED ) as $file ) {
			$this->assertContains( $file, $found, "The scan no longer finds the file input in {$file}; re-point ALLOWED or fix the scan." );
		}

		$this->assertSame(
			array(),
			array_values( array_diff( $found, array_keys( self::ALLOWED ) ) ),
			'Draw a file field with AdminUI::render_file_picker() (#2038), so every upload carries the same button and icon.'
		);
	}

	/**
	 * Repository-relative paths of the PHP and JS files whose code (comments
	 * stripped) prints a file input.
	 *
	 * @return list<string>
	 */
	private function files_printing_a_file_input(): array {
		$root  = dirname( __DIR__, 2 );
		$found = array();
		foreach ( array( 'includes', 'templates', 'assets/js' ) as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				$path = (string) $file;
				if ( ! preg_match( '/\.(php|js)$/', $path ) || preg_match( '/\.min\.js$/', $path ) ) {
					continue;
				}
				$code = str_ends_with( $path, '.php' )
					? php_strip_whitespace( $path )
					: (string) preg_replace( '#/\*.*?\*/|(?<![:\'"\\\\])//[^\n]*#s', '', (string) file_get_contents( $path ) );
				if ( preg_match( '/<input\b[^>]*\btype=(\\\\?["\'])file\1/i', $code ) ) {
					$found[] = substr( $path, strlen( $root ) + 1 );
				}
			}
		}
		sort( $found );
		return $found;
	}
}
