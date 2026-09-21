<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\DocumentFormatter;

/**
 * The RF check-digit setting is wired at every site that has to agree on its
 * key, and the toggle survives a Save.
 *
 * WHY THIS GUARD EXISTS
 *
 * A mutation wrote it: renaming `DocumentFormatter::SETTING_CHECK_DIGIT` left
 * the whole suite green, because the behaviour tests use the constant as the
 * array key and therefore follow it wherever it points -- asserting against a
 * value the test itself supplies, which is the shape `AssertionCoverageTest`
 * exists for and which no static checker sees.
 *
 * The other four sites carry the key as a LITERAL: the declared default, the
 * save handler's rebuild, the autosave allowlist and the field name in the
 * view. If the constant drifts from them the administrator flips a switch
 * that is stored under one key and read under another, and nothing reports it.
 *
 * THE NO-CLOBBER INVARIANT IS THE SECOND HALF
 *
 * An autosave toggle must ALSO be a named field inside its tab's form, and
 * the tab's save must rebuild it from presence. A key the rebuild omits is
 * silently reset on the next Save, however recently the autosave wrote it --
 * `CLAUDE.md` states it and this is what holds it for this key.
 *
 * It reads the sources as TEXT and boots no WordPress.
 *
 * @coversNothing
 */
final class RfCheckDigitSettingWiringTest extends TestCase {

	/**
	 * Read one source file.
	 *
	 * @param string $relative Path from the repository root.
	 * @return string
	 */
	private static function source( string $relative ): string {
		$path   = dirname( __DIR__, 2 ) . '/' . $relative;
		$source = file_get_contents( $path );

		return is_string( $source ) ? $source : '';
	}

	/**
	 * Self-check: every file this guard reads must be there and non-trivial,
	 * or each assertion below passes over an empty string (#1071 / #1094).
	 */
	public function test_the_scan_reads_every_site(): void {
		foreach ( array(
			'includes/admin/class-ffc-settings.php',
			'includes/admin/class-ffc-settings-save-handler.php',
			'includes/admin/class-ffc-settings-ajax-endpoint.php',
			'includes/settings/views/ffc-tab-general.php',
		) as $relative ) {
			$this->assertGreaterThan(
				1000,
				strlen( self::source( $relative ) ),
				"{$relative} did not read, so the assertions over it prove nothing."
			);
		}
	}

	public function test_the_key_is_declared_with_a_false_default(): void {
		$this->assertMatchesRegularExpression(
			"/'" . preg_quote( DocumentFormatter::SETTING_CHECK_DIGIT, '/' ) . "'\s*=>\s*false/",
			self::source( 'includes/admin/class-ffc-settings.php' ),
			'The key is read but never declared, so `SettingsDefaultsTest` has nothing to compare a read-site default against.'
		);
	}

	/**
	 * The rebuild half of the no-clobber invariant.
	 */
	public function test_the_general_save_rebuilds_the_key(): void {
		$this->assertStringContainsString(
			"\$clean['" . DocumentFormatter::SETTING_CHECK_DIGIT . "']",
			self::source( 'includes/admin/class-ffc-settings-save-handler.php' ),
			'A key the general save does not rebuild is reset to off on the next Save, whatever the autosave just wrote.'
		);
	}

	public function test_the_autosave_allowlist_carries_the_key(): void {
		$this->assertStringContainsString(
			"'" . DocumentFormatter::SETTING_CHECK_DIGIT . "',",
			self::source( 'includes/admin/class-ffc-settings-ajax-endpoint.php' ),
			'Without an allowlist entry the toggle flips in the browser and the endpoint refuses the write.'
		);
	}

	/**
	 * The named-field half of the no-clobber invariant, and the autosave
	 * attribute beside it. Both, because either alone is a defect: a field
	 * with no autosave key never saves on flip, and an autosave key on a
	 * field the form does not name is reset by the next Save.
	 */
	public function test_the_toggle_is_both_a_named_field_and_an_autosave_key(): void {
		$view = self::source( 'includes/settings/views/ffc-tab-general.php' );

		$this->assertStringContainsString(
			"'ffc_settings[" . DocumentFormatter::SETTING_CHECK_DIGIT . "]'",
			$view,
			'The toggle is not a named field inside the tab form.'
		);
		$this->assertStringContainsString(
			"'ffc-autosave-key' => '" . DocumentFormatter::SETTING_CHECK_DIGIT . "'",
			$view,
			'The toggle carries no autosave key, so flipping it saves nothing until the tab is saved.'
		);
	}
}
