<?php
/**
 * Every shipped certificate model carries the tags the default Required data asks for.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Settings\SettingsReader;
use PHPUnit\Framework\TestCase;

/**
 * Required tags in the shipped certificate models (#2524).
 *
 * The form editor refuses to save a layout that lacks a required tag
 * (`FormEditorSaveValidator::missing_required_tags()`), and an untouched site
 * requires `SettingsReader::DEFAULT_CERTIFICATE_TAGS`. From 6.39.0 that list
 * named `{{schedule}}` and no shipped model carried it, so a form on a shipped
 * model could not be saved until an administrator added the tag by hand.
 * Nothing compared the two lists. This test does.
 *
 * @covers \FreeFormCertificate\Settings\SettingsReader
 */
class CertificateDefaultsRequiredTagsTest extends TestCase {

	private const DIR = 'templates/certificate-defaults';

	/**
	 * The shipped certificate models, by glob.
	 *
	 * @return list<string> Absolute paths, sorted.
	 */
	private static function models(): array {
		$files = glob( dirname( __DIR__, 2 ) . '/' . self::DIR . '/*.html' );
		$files = false === $files ? array() : $files;
		sort( $files );
		return $files;
	}

	public function test_every_shipped_model_carries_every_default_required_tag(): void {
		$missing = array();
		foreach ( self::models() as $file ) {
			$html = (string) file_get_contents( $file );
			foreach ( SettingsReader::DEFAULT_CERTIFICATE_TAGS as $tag ) {
				$accepted = '{{name}}' === $tag ? array( '{{name}}', '{{nome}}' ) : array( $tag );
				$found    = false;
				foreach ( $accepted as $candidate ) {
					$found = $found || false !== strpos( $html, $candidate );
				}
				if ( ! $found ) {
					$missing[] = basename( $file ) . ' lacks ' . $tag;
				}
			}
		}
		$this->assertSame( array(), $missing, 'A form on this model could not be saved on an untouched site.' );
	}

	public function test_the_scan_reads_every_model_a_second_traversal_finds(): void {
		$root    = dirname( __DIR__, 2 ) . '/' . self::DIR;
		$recount = array();
		foreach ( (array) scandir( $root ) as $name ) {
			if ( '.html' === substr( (string) $name, -5 ) ) {
				$recount[] = $root . '/' . $name;
			}
		}
		sort( $recount );

		$this->assertNotSame( array(), $recount );
		$this->assertSame( $recount, self::models() );
	}
}
