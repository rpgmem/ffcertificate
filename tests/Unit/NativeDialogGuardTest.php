<?php
/**
 * No native browser dialog, anywhere in the plugin (#2534, #2535).
 *
 * `alert()`, `confirm()` and `prompt()` block the page, cannot carry the
 * plugin's notice and button standard or the dark theme, and a `prompt()`
 * has no label assistive technology can read. The plugin asks with
 * `FFC.confirmDialog()` (its `reasonField` replaces `prompt()`) and says a
 * result with `FFC.adminNotice()`, `FFC.say()` or the screen's own notice.
 *
 * This was a per-file ratchet while the calls moved: 79 in 36 files when it
 * was frozen, none when #2535 closed. It blocks at zero now, so the frozen
 * register is gone, and with it the exact comparison that was its self-check.
 * The self-check it owes in exchange is explicit: the scan must find a
 * dialog injected into real files of each kind it reads, and its file list
 * must equal a second traversal. What counts is written once in
 * `Tests\Support\NativeDialogs`. It sees presence, never truth: a notice that
 * says the wrong thing passes.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Core\Icons;
use FreeFormCertificate\Tests\Support\NativeDialogs;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class NativeDialogGuardTest extends TestCase {

	/**
	 * No script, PHP class or template opens a native dialog.
	 */
	public function test_no_file_opens_a_native_dialog(): void {
		$found = array();
		foreach ( NativeDialogs::found() as $file => $kinds ) {
			foreach ( $kinds as $kind => $count ) {
				$found[] = sprintf( '%s: %s() x%d', $file, $kind, $count );
			}
		}

		$this->assertSame(
			array(),
			$found,
			"A native browser dialog was added:\n  " . implode( "\n  ", $found )
			. "\n\nAsk with FFC.confirmDialog() -- its reasonField replaces prompt() -- and say a result with"
			. "\nFFC.adminNotice(), FFC.say() or the screen's own notice."
		);
	}

	/**
	 * The self-check that replaced the register: a dialog injected into real
	 * files of each kind the scan reads is found, so a zero is a reading.
	 */
	public function test_the_scan_finds_a_dialog_in_real_files(): void {
		$root  = dirname( __DIR__, 2 );
		$cases = array(
			'assets/js/ffc-core.js'                           => array( "\nalert('x');\n", false ),
			'includes/core/class-ffc-privacy-policy-link.php' => array( "\n?><a onclick=\"return confirm('x')\">x</a><?php\n", true ),
			'templates/admin/audience/general-tab.php'        => array( "\n<a onclick=\"return confirm('x')\">x</a>\n", true ),
		);
		foreach ( $cases as $file => list( $injected, $is_php ) ) {
			$this->assertContains( $file, NativeDialogs::files(), $file . ' is no longer scanned.' );
			$src = (string) file_get_contents( $root . '/' . $file );
			$this->assertSame( array(), NativeDialogs::in_code( $src, $is_php ), $file . ' already opens a dialog.' );
			$kinds = NativeDialogs::in_code( $src . $injected, $is_php );
			$this->assertSame( 1, array_sum( $kinds ), 'The scan did not see a dialog injected into ' . $file . '.' );
		}
	}

	/**
	 * Every icon a dialog names is a registry class: an unknown one draws a
	 * button with no icon and nothing reports it, which is how `trash` and
	 * `refresh` shipped in the first moves.
	 */
	public function test_the_dialogs_name_registered_icons(): void {
		$root    = dirname( __DIR__, 2 );
		$known   = Icons::classes();
		$unknown = array();
		$by_file = array();
		foreach ( NativeDialogs::files() as $file ) {
			if ( '.js' !== substr( $file, -3 ) ) {
				continue;
			}
			$by_file[ $file ] = self::dialog_icons( (string) file_get_contents( $root . '/' . $file ) );
			foreach ( $by_file[ $file ] as $icon ) {
				if ( ! isset( $known[ $icon ] ) ) {
					$unknown[] = $file . ': ' . $icon;
				}
			}
		}

		// Self-check by a named member: the core's own [data-confirm] dialog.
		$this->assertContains( 'delete', $by_file['assets/js/ffc-core.js'] ?? array(), 'The icon scan no longer reads the core dialog.' );
		$this->assertSame( array(), $unknown, "Dialogs name icons Core\\Icons does not know:\n  " . implode( "\n  ", $unknown ) );
	}

	/**
	 * The icon scan reads a literal, a fallback and a ternary.
	 */
	public function test_the_icon_scan_reads_its_shapes(): void {
		$js = "FFC.confirmDialog({ message: 'a', icon: 'trash' });\n"
			. "window.FFC.confirmDialog({ message: m, category: 'danger', icon: el.getAttribute('x') || 'sync' });\n"
			. "core.overrideConfirm({ agreeIcon: on ? 'cross' : 'checkmark' });\n"
			. "FFC.button({ icon: 'not-a-dialog' });\n";

		$this->assertSame( array( 'trash', 'sync', 'cross', 'checkmark' ), self::dialog_icons( $js ) );
	}

	/**
	 * The icon names a dialog call in this source asks for.
	 *
	 * @param string $src JS source.
	 * @return list<string>
	 */
	private static function dialog_icons( string $src ): array {
		preg_match_all( '/(?:confirmDialog|overrideConfirm)\s*\(\s*\{[^;]*?\b(?:icon|agreeIcon)\s*:\s*([^,}\n]*)/s', $src, $m );
		$icons = array();
		foreach ( $m[1] as $expr ) {
			// An attribute read names an attribute, not an icon.
			$expr = (string) preg_replace( "/\\b(?:getAttribute|attr|data)\\(\\s*'[^']*'\\s*\\)/", '', $expr );
			preg_match_all( "/'([a-z0-9-]+)'/", $expr, $names );
			foreach ( $names[1] as $name ) {
				$icons[] = $name;
			}
		}

		return $icons;
	}

	/**
	 * The shapes on synthetic source: what counts, and what must not.
	 */
	public function test_the_shapes_on_synthetic_source(): void {
		$js = "alert('a'); window.confirm('b'); var r = prompt('c');\n"
			. "if (!confirm (x)) {}\n"
			. "// alert('in a comment')\n"
			. "/* prompt('in a block') */\n"
			. "FFC.confirm(1); core.overrideConfirm({}); FFC.confirmDialog({}); $.alert(); obj.prompt(); myalert(2);\n"
			. "function confirm(x) {}\n";

		$this->assertSame(
			array(
				'alert'   => 1,
				'confirm' => 2,
				'prompt'  => 1,
			),
			NativeDialogs::in_code( $js, false ),
			'The JS shapes no longer read what they exist for -- or a member call, a declaration or a comment started counting.'
		);

		$php = '<a onclick="return confirm(\'Remove?\');">x</a>'
			. "<?php // confirm('in a comment')\n"
			. " /** alert( in a docblock */ \$a->confirm( 1 ); Checkin::confirm( 2 );"
			. ' function prompt() {} ?>';

		$this->assertSame(
			array( 'confirm' => 1 ),
			NativeDialogs::in_code( $php, true ),
			'The PHP shapes no longer read what they exist for.'
		);
	}

	/**
	 * The file list holds every script and PHP file a second traversal finds.
	 */
	public function test_the_file_list_matches_an_independent_recount(): void {
		$recount = array();
		foreach ( array( 'assets/js', 'includes', 'templates' ) as $dir ) {
			$stack = array( dirname( __DIR__, 2 ) . '/' . $dir );
			while ( $stack ) {
				$current = array_pop( $stack );
				foreach ( (array) scandir( $current ) as $entry ) {
					if ( '.' === $entry || '..' === $entry ) {
						continue;
					}
					$path = $current . '/' . $entry;
					if ( is_dir( $path ) ) {
						$stack[] = $path;
					} elseif ( NativeDialogs::scanned( $path ) ) {
						$recount[] = str_replace( dirname( __DIR__, 2 ) . '/', '', $path );
					}
				}
			}
		}
		sort( $recount );

		$this->assertSame( $recount, NativeDialogs::files(), 'The scan and a second traversal disagree about which files exist.' );
	}
}
