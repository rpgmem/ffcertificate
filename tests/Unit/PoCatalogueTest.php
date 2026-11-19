<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\PoCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * The reader every translation guard shares refuses a malformed entry
 * (#2653).
 *
 * A `.pot` entry with no msgstr line shipped past every guard, because the
 * reader took the absence for an empty translation, which is what every
 * `.pot` entry is. `msgfmt` rejects such a file outright.
 *
 * @covers \FreeFormCertificate\Tests\Support\PoCatalogue
 */
final class PoCatalogueTest extends TestCase {

	/** @var list<string> */
	private array $files = array();

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		parent::tearDown();
	}

	private function catalogue( string $body ): string {
		$file = (string) tempnam( sys_get_temp_dir(), 'ffc-po-' );
		file_put_contents( $file, "msgid \"\"\nmsgstr \"\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\n" . $body );
		$this->files[] = $file;
		return $file;
	}

	public function test_well_formed_entries_read_including_empty_and_multiline_ones(): void {
		$file = $this->catalogue(
			"#: a.php\nmsgid \"One\"\nmsgstr \"\"\n\n" .
			"msgctxt \"verb\"\nmsgid \"\"\n\"Two \"\n\"lines\"\nmsgstr \"Duas linhas\"\n\n" .
			"msgid \"%d item\"\nmsgid_plural \"%d items\"\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n"
		);

		$entries = PoCatalogue::read( $file );

		$this->assertSame( array( 'One', 'Two lines', '%d item' ), array_column( $entries, 'msgid' ) );
		$this->assertSame( array( '' ), $entries[0]['forms'] );
		$this->assertSame( array( 'Duas linhas' ), $entries[1]['forms'] );
		$this->assertSame( array( '', '' ), $entries[2]['forms'] );
	}

	public function test_an_entry_without_msgstr_is_refused_with_its_line(): void {
		$file = $this->catalogue( "#: a.php\nmsgid \"Fine\"\nmsgstr \"\"\n\n#: b.php\nmsgid \"Broken\"\n\n#: c.php\nmsgid \"Next\"\nmsgstr \"\"\n" );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/:10: entry "Broken" has no msgstr line\\.$/' );

		PoCatalogue::read( $file );
	}

	public function test_a_plural_without_its_first_form_is_refused(): void {
		$file = $this->catalogue( "msgid \"%d item\"\nmsgid_plural \"%d items\"\n" );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'has no msgstr[0] line' );

		PoCatalogue::read( $file );
	}

	public function test_the_last_entry_of_the_file_is_checked_too(): void {
		$file = $this->catalogue( 'msgid "Last"' );

		$this->expectException( \RuntimeException::class );

		PoCatalogue::read( $file );
	}
}
