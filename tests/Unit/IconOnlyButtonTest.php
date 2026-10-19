<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Icon-only buttons are the family's, and they keep a name (#1879).
 *
 * Every icon-only close or remove was once a hand-drawn `&times;`: a glyph
 * sized by `font-size`, a per-screen colour, and on four of them no
 * accessible name at all, so a screen reader announced "times" or nothing.
 * They are now the button family's icon-only variant (`Core\Button`
 * `icon_only`, `FFC.button({ iconOnly })`, `.ffc-btn-icon`), whose label
 * becomes the `aria-label` and the `title`.
 *
 * Two directions, both blocking at zero:
 *
 * 1. A `<button>` or `<a>` whose only content is a multiplication sign —
 *    `×`, `&times;`, `&#215;`, `&#xd7;` or the JS escape — is a hand-drawn
 *    close. Use the icon-only variant with the `cross` icon.
 * 2. An element carrying `ffc-btn-icon` without a non-empty `aria-label` has
 *    no name: the variant prints no text, so the attribute is the only one.
 *    The helpers always write it; this catches markup written by hand.
 *
 * It reads PHP under `includes/` and `templates/` and the non-minified JS
 * under `assets/js/`, with every `<?php … ?>` block read as an opaque value,
 * so `aria-label="<?php esc_attr_e( … ); ?>"` counts as a label and the
 * `?>` inside it does not end the tag. Markup built through
 * `Core\Button::html()` or `FFC.buttonHtml()` never reaches either check: the
 * helpers refuse an icon-only button without a label (`ButtonTest`).
 *
 * @coversNothing
 */
class IconOnlyButtonTest extends TestCase {

	/**
	 * The trees read, and the extension each one is read for.
	 */
	private const TREES = array(
		'includes'  => 'php',
		'templates' => 'php',
		'assets/js' => 'js',
	);

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	public function test_no_button_is_a_bare_multiplication_sign(): void {
		$found = array();
		foreach ( self::files() as $file ) {
			$lines = self::glyph_buttons( (string) file_get_contents( self::root() . '/' . $file ) );
			if ( array() !== $lines ) {
				$found[ $file ] = $lines;
			}
		}
		$this->assertSame( array(), $found, 'a hand-drawn close or remove: use the icon-only button (Core\Button icon_only / FFC.button iconOnly) with the cross icon' );
	}

	public function test_every_icon_only_element_has_an_accessible_name(): void {
		$found = array();
		foreach ( self::files() as $file ) {
			$lines = self::unnamed_icon_only( (string) file_get_contents( self::root() . '/' . $file ) );
			if ( array() !== $lines ) {
				$found[ $file ] = $lines;
			}
		}
		$this->assertSame( array(), $found, 'an .ffc-btn-icon element prints no text: give it a non-empty aria-label (and the same title)' );
	}

	/**
	 * The scan's file list against a second traversal written differently:
	 * a recursive `scandir()` instead of the SPL iterator. Adding a file moves
	 * both sides, so this never needs maintenance; a walk that stops early or
	 * a filter that drifts fails here instead of reading as clean.
	 */
	public function test_the_scan_reads_every_file_an_independent_recount_finds(): void {
		$recount = array();
		foreach ( self::TREES as $dir => $ext ) {
			self::recount( $dir, $ext, $recount );
		}
		sort( $recount );

		$this->assertNotSame( array(), $recount, 'the recount found nothing: the trees moved' );
		$this->assertSame( $recount, self::files() );
	}

	/**
	 * The hand-written icon-only sites are what direction 2 exists for, so the
	 * scan must see them as icon-only elements — a read that reached the files
	 * but no longer recognised the tag would pass direction 2 vacuously.
	 */
	public function test_the_scan_recognises_the_hand_written_icon_only_sites(): void {
		$seen = array();
		foreach ( self::files() as $file ) {
			if ( self::icon_only_tags( (string) file_get_contents( self::root() . '/' . $file ) ) > 0 ) {
				$seen[] = $file;
			}
		}
		$this->assertContains( 'templates/admin/recruitment/notice-edit/adjutancies-section.php', $seen, 'a PHP echo of a hand-written icon-only link' );
		$this->assertContains( 'assets/js/ffc-audience-admin.js', $seen, 'a JS string of a hand-written icon-only button' );
	}

	public function test_the_reader_catches_the_shapes_a_hand_drawn_close_took(): void {
		$this->assertSame( array( 1 ), self::glyph_buttons( '<button type="button" class="x">&times;</button>' ) );
		$this->assertSame( array( 1 ), self::glyph_buttons( "<button class=\"x\" aria-label=\"<?php esc_attr_e( 'Close', 'd' ); ?>\">&times;</button>" ), 'a `?>` inside an attribute does not end the tag' );
		$this->assertSame( array( 2 ), self::glyph_buttons( "<p></p>\n<button class=\"x\">\n\t<span aria-hidden=\"true\">&times;</span>\n</button>" ), 'a glyph wrapped in a span is still the only content' );
		$this->assertSame( array( 1 ), self::glyph_buttons( "'<button type=\"button\" class=\"x\">\u{00D7}</button>' +" ) );
		$this->assertSame( array( 1 ), self::glyph_buttons( "'<button>\\u00d7</button>'" ) );
		$this->assertSame( array( 1 ), self::glyph_buttons( '<a href="#" title="Detach">&#215;</a>' ) );
		$this->assertSame( array(), self::glyph_buttons( '<button type="button">Close</button>' ) );
		$this->assertSame( array(), self::glyph_buttons( '<button type="button" class="ffc-btn ffc-btn-icon ffc-icon-cross" aria-label="Close"></button>' ) );
		$this->assertSame( array(), self::glyph_buttons( "'1 \u{00D7} 2'" ), 'a sign outside a button is arithmetic, not a control' );
	}

	public function test_the_reader_requires_a_name_on_an_icon_only_element(): void {
		$this->assertSame( array( 1 ), self::unnamed_icon_only( '<button class="ffc-btn ffc-btn-icon ffc-icon-cross"></button>' ) );
		$this->assertSame( array( 1 ), self::unnamed_icon_only( '<button class="ffc-btn ffc-btn-icon" aria-label=""></button>' ) );
		$this->assertSame( array( 1 ), self::unnamed_icon_only( "<button class=\"ffc-btn ffc-btn-icon\" title=\"Close\"></button>" ), 'a title alone is not a name every reader announces' );
		$this->assertSame( array(), self::unnamed_icon_only( "<button class=\"ffc-btn ffc-btn-icon\" aria-label=\"<?php esc_attr_e( 'Close', 'd' ); ?>\"></button>" ) );
		$this->assertSame( array(), self::unnamed_icon_only( "'<button class=\"ffc-btn ffc-btn-icon\" aria-label=\"' + esc(label) + '\"></button>'" ) );
		$this->assertSame( array(), self::unnamed_icon_only( "echo '<a class=\"ffc-btn ffc-btn-icon\" data-id=\"' . \$a->id . '\" aria-label=\"' . \$label . '\"></a>';" ), 'a `->` inside a PHP concatenation does not end the tag' );
		$this->assertSame( array(), self::unnamed_icon_only( '<button class="ffc-btn-icon-wide">Go</button>' ), 'a longer name is not the variant' );
		$this->assertSame( 1, self::icon_only_tags( '<button class="ffc-btn ffc-btn-icon" aria-label="x"></button>' ) );
	}

	/**
	 * Every file the guard reads, repository-relative and sorted.
	 *
	 * @return array<int, string>
	 */
	private static function files(): array {
		$files = array();
		foreach ( self::TREES as $dir => $ext ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				$name = $file->getFilename();
				if ( ! str_ends_with( $name, '.' . $ext ) || str_ends_with( $name, '.min.js' ) ) {
					continue;
				}
				$files[] = substr( $file->getPathname(), strlen( self::root() ) + 1 );
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * The same list, walked with `scandir()`.
	 *
	 * @param string             $dir   Repository-relative directory.
	 * @param string             $ext   Extension read in this tree.
	 * @param array<int, string> $files Accumulator.
	 */
	private static function recount( string $dir, string $ext, array &$files ): void {
		foreach ( (array) scandir( self::root() . '/' . $dir ) as $entry ) {
			$entry = (string) $entry;
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( self::root() . '/' . $path ) ) {
				self::recount( $path, $ext, $files );
			} elseif ( pathinfo( $entry, PATHINFO_EXTENSION ) === $ext && 1 !== preg_match( '/\.min\.js$/', $entry ) ) {
				$files[] = $path;
			}
		}
	}

	/**
	 * The source with each PHP block read as one opaque, non-empty value, line
	 * breaks kept so a reported line is the file's line.
	 *
	 * @param string $source A file's source.
	 * @return string
	 */
	private static function normalise( string $source ): string {
		return (string) preg_replace_callback(
			'/<\?(?:php|=).*?\?>/s',
			static fn( array $m ): string => 'PHP' . str_repeat( "\n", substr_count( $m[0], "\n" ) ),
			$source
		);
	}

	/**
	 * An opening tag: a `>` right after `-` or `=` is PHP's `->` / `=>` (or a
	 * JS arrow) inside a concatenated attribute, not the end of the tag.
	 */
	private const OPEN_TAG = '(?:[^>]|(?<=[-=])>)*>';

	/**
	 * Lines where a button or link holds nothing but a multiplication sign.
	 *
	 * @param string $source A file's source.
	 * @return array<int, int>
	 */
	private static function glyph_buttons( string $source ): array {
		$source = self::normalise( $source );
		$lines  = array();
		if ( ! preg_match_all( '/<(button|a)\b' . self::OPEN_TAG . '(.*?)<\/\1>/is', $source, $m, PREG_OFFSET_CAPTURE ) ) {
			return $lines;
		}
		foreach ( $m[2] as $i => [ $content ] ) {
			$text = str_ireplace( '\\u00d7', "\u{00D7}", strip_tags( $content ) );
			$text = trim( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( "\u{00D7}" === $text ) {
				$lines[] = substr_count( substr( $source, 0, $m[0][ $i ][1] ), "\n" ) + 1;
			}
		}
		return array_values( array_unique( $lines ) );
	}

	/**
	 * Every opening tag carrying the icon-only class.
	 *
	 * @param string $source Normalised source.
	 * @return array<int, array{0: string, 1: int}>
	 */
	private static function icon_only_matches( string $source ): array {
		preg_match_all( '/<[a-z]+\b(?:[^>]|(?<=[-=])>)*?(?<![\w-])ffc-btn-icon(?![\w-])' . self::OPEN_TAG . '/i', $source, $m, PREG_OFFSET_CAPTURE );
		return $m[0];
	}

	/**
	 * How many icon-only opening tags the source carries.
	 *
	 * @param string $source A file's source.
	 * @return int
	 */
	private static function icon_only_tags( string $source ): int {
		return count( self::icon_only_matches( self::normalise( $source ) ) );
	}

	/**
	 * Lines of an icon-only element without a non-empty `aria-label`.
	 *
	 * @param string $source A file's source.
	 * @return array<int, int>
	 */
	private static function unnamed_icon_only( string $source ): array {
		$source = self::normalise( $source );
		$lines  = array();
		foreach ( self::icon_only_matches( $source ) as [ $tag, $offset ] ) {
			if ( 1 === preg_match( '/\baria-label\s*=\s*"([^"]*)"/i', $tag, $label ) && '' !== trim( $label[1] ) ) {
				continue;
			}
			$lines[] = substr_count( substr( $source, 0, $offset ), "\n" ) + 1;
		}
		return $lines;
	}
}
