<?php
/**
 * Ratchet on native browser dialogs (#2534).
 *
 * `alert()`, `confirm()` and `prompt()` block the page, cannot carry the
 * plugin's notice and button standard or the dark theme, and a `prompt()`
 * has no label assistive technology can read. The audience calendars moved
 * their booking and cancellation flows to `FFC.notice()` and to the reason
 * field of `FFC.confirmRangeCancel()`; the rest of the plugin still opens
 * them, frozen PER FILE and per kind in `tests/fixtures/native-dialogs-baseline.php`.
 * It is a ratchet both ways:
 *
 * - a file that gains one fails -- say a result with `FFC.notice()` and ask
 *   a question with `FFC.overrideConfirm()` (its `reasonField`,
 *   `matchField` and `choiceField` cover what a `prompt()` asked);
 * - a file that loses some fails too -- regenerate the register so the win
 *   is locked in, and a file at zero leaves it.
 *
 * Regenerate after an intentional change and review the diff:
 * `FFC_UPDATE_NATIVE_DIALOG_BASELINE=1 vendor/bin/phpunit --filter NativeDialogRatchet`.
 *
 * Its self-check is the register itself: it is not empty and the comparison
 * is exact in both directions, so a scan that read fewer files reports the
 * entries it lost. What counts is written once in `Tests\Support\NativeDialogs`.
 * It sees presence, never truth: a notice that says the wrong thing passes.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\NativeDialogs;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class NativeDialogRatchetTest extends TestCase {

	private const BASELINE_FILE = __DIR__ . '/../fixtures/native-dialogs-baseline.php';

	/**
	 * The frozen register.
	 *
	 * @return array<string, array<string, int>>
	 */
	private static function baseline(): array {
		/** @var array<string, array<string, int>> $baseline */
		$baseline = require self::BASELINE_FILE;

		return $baseline;
	}

	/**
	 * Nothing new: no file gains a native dialog, and no file joins.
	 */
	public function test_no_file_gains_a_native_dialog(): void {
		$found = NativeDialogs::found();

		if ( getenv( 'FFC_UPDATE_NATIVE_DIALOG_BASELINE' ) ) {
			$body = "<?php\n/**\n * Native-dialog register (#2534) -- generated.\n"
				. " * Regenerate: FFC_UPDATE_NATIVE_DIALOG_BASELINE=1 vendor/bin/phpunit --filter NativeDialogRatchet\n"
				. " *\n * File => alert / confirm / prompt => how many calls it still makes. A debt\n"
				. " * register that only shrinks; the remaining ones are tracked in #2535.\n */\n\nreturn array(\n";
			foreach ( $found as $file => $kinds ) {
				$body .= "\t'" . $file . "' => array(\n";
				foreach ( $kinds as $kind => $count ) {
					$body .= "\t\t'" . $kind . "' => " . $count . ",\n";
				}
				$body .= "\t),\n";
			}
			$body .= ");\n";
			file_put_contents( self::BASELINE_FILE, $body );
			$this->markTestSkipped( 'Native-dialog register regenerated (' . count( $found ) . ' files).' );
		}

		$baseline = self::baseline();
		$grown    = array();
		foreach ( $found as $file => $kinds ) {
			foreach ( $kinds as $kind => $count ) {
				$frozen = $baseline[ $file ][ $kind ] ?? 0;
				if ( $count > $frozen ) {
					$grown[] = sprintf( '%s: %s() (%d frozen, %d found)', $file, $kind, $frozen, $count );
				}
			}
		}

		$this->assertSame(
			array(),
			$grown,
			"A native browser dialog was added:\n  " . implode( "\n  ", $grown )
			. "\n\nSay a result with FFC.notice() in a live region of the screen, and ask a question with"
			. "\nFFC.overrideConfirm() -- its reasonField, matchField and choiceField cover what prompt() asked."
		);
	}

	/**
	 * A file that lost some, or all, leaves the register at its new count.
	 */
	public function test_the_register_only_shrinks(): void {
		$found = NativeDialogs::found();
		$stale = array();

		foreach ( self::baseline() as $file => $kinds ) {
			foreach ( $kinds as $kind => $frozen ) {
				$count = $found[ $file ][ $kind ] ?? 0;
				if ( $count < $frozen ) {
					$stale[] = sprintf( '%s: %s() (%d frozen, %d found)', $file, $kind, $frozen, $count );
				}
			}
		}

		$this->assertSame(
			array(),
			$stale,
			"These shrank or no longer exist -- regenerate the register to lock the win in\n"
			. "(FFC_UPDATE_NATIVE_DIALOG_BASELINE=1 vendor/bin/phpunit --filter NativeDialogRatchet):\n  "
			. implode( "\n  ", $stale )
		);
	}

	/**
	 * The register holds counts, never a zero, and is not empty: an empty
	 * one would give up the exact comparison as the scan's self-check.
	 */
	public function test_the_register_is_a_live_debt_register(): void {
		$baseline = self::baseline();
		$this->assertNotSame( array(), $baseline, 'The register reached zero: turn this guard into a block at zero with an explicit self-check.' );

		$zeros = array();
		foreach ( $baseline as $file => $kinds ) {
			foreach ( $kinds as $kind => $count ) {
				if ( $count < 1 ) {
					$zeros[] = $file . ': ' . $kind;
				}
			}
			if ( array() === $kinds ) {
				$zeros[] = $file;
			}
		}

		$this->assertSame( array(), $zeros, "Drop these entries from the register:\n  " . implode( "\n  ", $zeros ) );
	}

	/**
	 * The audience calendars' booking and cancellation flows left the
	 * register (#2534) and may not come back.
	 */
	public function test_the_audience_booking_flows_open_no_native_dialog(): void {
		$found = NativeDialogs::found();
		$clear = array(
			'assets/js/ffc-audience-booking-form.js',
			'assets/js/ffc-audience-bookings.js',
			// The public pages (#2535): what a visitor or a member sees.
			'assets/js/ffc-user-dashboard-audience-join.js',
			'assets/js/ffc-user-dashboard-profile.js',
			'assets/js/ffc-reregistration-frontend.js',
			'assets/js/ffc-self-scheduling-receipt.js',
			'assets/js/ffc-pdf-generator.js',
			// The scheduling screens (#2535).
			'assets/js/ffc-calendar-editor.js',
			'assets/js/ffc-self-scheduling-admin-appointments.js',
			'assets/js/ffc-audience-admin.js',
			'assets/js/ffc-geofence-admin.js',
			'assets/js/ffc-locations-crud.js',
			'includes/audience/class-ffc-audience-admin-audience-renderer.php',
			'templates/admin/audience/general-tab.php',
			// The certificate screens (#2535).
			'assets/js/ffc-admin-activity-log.js',
			'assets/js/ffc-admin-field-builder.js',
			'assets/js/ffc-admin-pdf.js',
			'assets/js/ffc-admin-submission-edit.js',
			'assets/js/ffc-admin-submissions-bulk.js',
			'assets/js/ffc-admin.js',
			'assets/js/ffc-cert-email-mode.js',
		);
		foreach ( $clear as $file ) {
			$this->assertArrayNotHasKey( $file, $found, $file . ' opens a native dialog again.' );
		}
	}

	/**
	 * The shapes on synthetic source: what counts, and what must not.
	 */
	public function test_the_shapes_on_synthetic_source(): void {
		$js = "alert('a'); window.confirm('b'); var r = prompt('c');\n"
			. "if (!confirm (x)) {}\n"
			. "// alert('in a comment')\n"
			. "/* prompt('in a block') */\n"
			. "FFC.confirm(1); core.overrideConfirm({}); $.alert(); obj.prompt(); myalert(2);\n"
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
			. " function prompt() {} ?>";

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
