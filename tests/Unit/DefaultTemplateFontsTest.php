<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The default templates name installable fonts without depending on them
 * (#2574): every stack that opens with one of the recommended Google Fonts
 * goes on with the system font the default named before, so a site that
 * installs none renders exactly as it did.
 *
 * @coversNothing
 */
class DefaultTemplateFontsTest extends TestCase {

	/**
	 * The fonts the defaults may name first, and the fallback each must keep.
	 *
	 * @var array<string, list<string>>
	 */
	private const RECOMMENDED = array(
		'Cinzel'      => array( 'Georgia' ),
		'Great Vibes' => array( 'Georgia' ),
		'EB Garamond' => array( 'Georgia' ),
		'Montserrat'  => array( 'Arial' ),
		'Arimo'       => array( 'Arial' ),
		'Cousine'     => array( 'Courier New' ),
	);

	/**
	 * Every default template file.
	 *
	 * @return list<string>
	 */
	private static function files(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array_merge(
			(array) glob( $root . '/templates/certificate-defaults/*.html' ),
			(array) glob( $root . '/templates/documents/*.html' )
		);
		sort( $files );

		return array_values( array_filter( $files, 'is_string' ) );
	}

	/**
	 * The names of every font-family stack in a file.
	 *
	 * @param string $html Template HTML.
	 * @return list<list<string>>
	 */
	private static function stacks( string $html ): array {
		preg_match_all( '/font-family:\s*([^;"]+)/', $html, $matches );

		return array_map(
			static fn( string $stack ): array => array_map(
				static fn( string $name ): string => trim( trim( $name ), "'\"" ),
				explode( ',', $stack )
			),
			$matches[1]
		);
	}

	public function test_the_scan_reads_every_default(): void {
		$this->assertSame(
			9,
			count( self::files() ),
			'Six certificates, two appointment receipts and the record.'
		);
	}

	public function test_every_recommended_font_is_followed_by_the_system_font_it_replaces(): void {
		foreach ( self::files() as $file ) {
			$stacks = self::stacks( (string) file_get_contents( $file ) );
			$this->assertNotSame( array(), $stacks, basename( $file ) . ' names no font.' );

			foreach ( $stacks as $stack ) {
				$first = $stack[0];
				$this->assertArrayHasKey(
					$first,
					self::RECOMMENDED,
					basename( $file ) . ' opens a stack with ' . $first . ', which is not one of the recommended fonts.'
				);
				$this->assertSame(
					self::RECOMMENDED[ $first ][0],
					$stack[1] ?? '',
					basename( $file ) . ': ' . $first . ' must be followed by the system font the default used before.'
				);
			}
		}
	}

	public function test_the_documentation_names_every_recommended_font(): void {
		$doc = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/settings/views/documentation/reference-html-styling.php' );

		foreach ( array_keys( self::RECOMMENDED ) as $font ) {
			$this->assertStringContainsString( '<strong>' . $font . '</strong>', $doc );
		}
	}
}
