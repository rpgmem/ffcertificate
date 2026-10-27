<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\FilenameHelper;

/**
 * Tests for FilenameHelper: PDF and export filenames, and filename
 * sanitisation. Moved out of UtilsTest when it was split by subject (#2092).
 *
 * @covers \FreeFormCertificate\Core\FilenameHelper
 */
class FilenameHelperTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The whole premise of #1264: flipping the sources to English changes
	 * NOTHING for a Brazilian install.
	 *
	 * The prefixes used to be Portuguese source strings translating to
	 * themselves, so an English site downloaded `certificado_123.pdf`. The
	 * sources are English now and pt_BR translates back -- which is only true
	 * while the catalogue carries the three entries, and that is what this
	 * test holds. Without it, renaming a source and forgetting the `.po` would
	 * pass every other assertion in this file while quietly renaming every PDF
	 * the plugin's actual users download.
	 */
	public function test_build_pdf_filename_keeps_portuguese_names_when_translated(): void {
		$this->stub_pdf_filename_helpers_pt_br();

		$this->assertSame(
			'certificado_666_C-MLQQZ9UX9MWF.pdf',
			FilenameHelper::build_pdf_filename( 'certificate', 666, 'MLQQZ9UX9MWF' )
		);
		$this->assertSame(
			'recibo_42_A-7K3M9P2XQRST.pdf',
			FilenameHelper::build_pdf_filename( 'appointment_receipt', 42, '7K3M9P2XQRST' )
		);
		$this->assertSame(
			'ficha_99_R-ABCDEF123456.pdf',
			FilenameHelper::build_pdf_filename( 'ficha', 99, 'ABCDEF123456' )
		);
	}

	public function test_build_pdf_filename_certificate_attaches_C_prefix(): void {
		$this->stub_pdf_filename_helpers();
		// Raw 12-char auth code from DB → helper prepends "C-" to match
		// DocumentFormatter::PREFIX_CERTIFICATE, and strips inner dashes
		// from the code body for filesystem compactness.
		$this->assertSame(
			'certificate_666_C-MLQQZ9UX9MWF.pdf',
			FilenameHelper::build_pdf_filename( 'certificate', 666, 'MLQQZ9UX9MWF' )
		);
	}

	public function test_build_pdf_filename_appointment_receipt_attaches_A_prefix(): void {
		$this->stub_pdf_filename_helpers();
		$this->assertSame(
			'receipt_42_A-7K3M9P2XQRST.pdf',
			FilenameHelper::build_pdf_filename( 'appointment_receipt', 42, '7K3M9P2XQRST' )
		);
	}

	public function test_build_pdf_filename_record_attaches_R_prefix_for_real_authcode(): void {
		$this->stub_pdf_filename_helpers();
		// Approved record → real auth code from AuthCodeService.
		$this->assertSame(
			'record_99_R-ABCDEF123456.pdf',
			FilenameHelper::build_pdf_filename( 'ficha', 99, 'ABCDEF123456' )
		);
	}

	public function test_build_pdf_filename_record_synthetic_code_skips_prefix(): void {
		$this->stub_pdf_filename_helpers();
		// Draft / submitted record (auth_code not yet generated) — synthetic
		// S{id} stays as-is, no `R-` prefix, since it's not a verifiable code.
		$this->assertSame(
			'record_99_S12345.pdf',
			FilenameHelper::build_pdf_filename( 'ficha', 99, 'S12345' )
		);
	}

	public function test_build_pdf_filename_does_not_double_prefix_pre_formatted_code(): void {
		$this->stub_pdf_filename_helpers();
		// Caller passed already-formatted `C-MLQQ-Z9UX-9MWF` (the display
		// shape). Helper detects the existing `C-` prefix and only strips
		// inner dashes — does not re-prepend, does not duplicate.
		$this->assertSame(
			'certificate_666_C-MLQQZ9UX9MWF.pdf',
			FilenameHelper::build_pdf_filename( 'certificate', 666, 'C-MLQQ-Z9UX-9MWF' )
		);
	}

	public function test_build_pdf_filename_code_is_uppercased(): void {
		$this->stub_pdf_filename_helpers();
		$this->assertSame(
			'certificate_1_C-ABC123.pdf',
			FilenameHelper::build_pdf_filename( 'certificate', 1, 'abc123' )
		);
	}

	public function test_build_pdf_filename_strips_unsafe_chars_from_code(): void {
		$this->stub_pdf_filename_helpers();
		// Spaces, slashes stripped — alphanumerics preserved, dashes from
		// the original input collapsed by the compact-body step.
		$this->assertSame(
			'certificate_1_C-ABCDEF123.pdf',
			FilenameHelper::build_pdf_filename( 'certificate', 1, 'abc def/123' )
		);
	}

	public function test_build_pdf_filename_empty_code_drops_segment(): void {
		$this->stub_pdf_filename_helpers();
		$this->assertSame(
			'record_99.pdf',
			FilenameHelper::build_pdf_filename( 'ficha', 99, '' )
		);
	}

	public function test_build_pdf_filename_negative_id_clamped_to_zero(): void {
		$this->stub_pdf_filename_helpers();
		$this->assertSame(
			'certificate_0_C-X.pdf',
			FilenameHelper::build_pdf_filename( 'certificate', -5, 'X' )
		);
	}

	public function test_build_pdf_filename_unknown_type_falls_back_to_slug(): void {
		$this->stub_pdf_filename_helpers();
		// Unknown types echo the slug as prefix AND skip the auth-code
		// virtual prefix — those map only to known types. Useful escape
		// hatch for sites adding custom PDF types via the central filter.
		$this->assertSame(
			'invoice_7_NF42.pdf',
			FilenameHelper::build_pdf_filename( 'invoice', 7, 'NF42' )
		);
	}

	public function test_build_pdf_filename_locale_translatable_prefix(): void {
		// Site running in EN locale — _x returns the translated string.
		Functions\when( '_x' )->alias( function ( $text, $context, $domain ) {
			$map = array(
				'certificado' => 'certificate',
				'recibo'      => 'receipt',
				'ficha'       => 'record',
			);
			return $map[ $text ] ?? $text;
		} );
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) {
			return $value;
		} );

		$this->assertSame(
			'certificate_666_C-MLQQZ9UX9MWF.pdf',
			FilenameHelper::build_pdf_filename( 'certificate', 666, 'MLQQZ9UX9MWF' )
		);
		$this->assertSame(
			'receipt_42_A-7K3M9P2X.pdf',
			FilenameHelper::build_pdf_filename( 'appointment_receipt', 42, '7K3M9P2X' )
		);
		$this->assertSame(
			'record_99_S12345.pdf',
			FilenameHelper::build_pdf_filename( 'ficha', 99, 'S12345' )
		);
	}

	public function test_build_pdf_filename_central_filter_fires(): void {
		Functions\when( '_x' )->returnArg();
		// Filter rewrites filename to a stable EN slug — the documented
		// escape hatch for DMS automation sites. The $code arg has
		// already been normalised (uppercase + virtual prefix attached)
		// by the time the filter fires.
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value, $type = null, $entity_id = null, $code = null ) {
			if ( 'ffcertificate_pdf_filename' === $hook ) {
				$stable = array(
					'certificate'         => 'certificate',
					'appointment_receipt' => 'receipt',
					'ficha'               => 'record',
				);
				$prefix = $stable[ $type ] ?? $type;
				return '' !== $code
					? sprintf( '%s_%d_%s.pdf', $prefix, $entity_id, $code )
					: sprintf( '%s_%d.pdf', $prefix, $entity_id );
			}
			return $value;
		} );

		$this->assertSame(
			'certificate_666_C-MLQQZ9UX9MWF.pdf',
			FilenameHelper::build_pdf_filename( 'certificate', 666, 'MLQQZ9UX9MWF' )
		);
	}

	public function test_sanitize_filename_simple(): void {
		$this->assertSame( 'certificate.pdf', FilenameHelper::sanitize_filename( 'Certificate.pdf' ) );
	}

	public function test_sanitize_filename_special_chars(): void {
		$this->assertSame( 'meu-certificado.pdf', FilenameHelper::sanitize_filename( 'Meu Certificado!.pdf' ) );
	}

	public function test_sanitize_filename_multiple_dashes_collapsed(): void {
		$this->assertSame( 'a-b.txt', FilenameHelper::sanitize_filename( 'a---b.txt' ) );
	}

	public function test_sanitize_filename_edge_dashes_trimmed(): void {
		$this->assertSame( 'test.pdf', FilenameHelper::sanitize_filename( '--test--.pdf' ) );
	}

	public function test_sanitize_filename_no_extension(): void {
		$this->assertSame( 'readme', FilenameHelper::sanitize_filename( 'README' ) );
	}

	public function test_sanitize_filename_accented_chars(): void {
		// ç and ã are 2 bytes each, both replaced by '-', then collapsed
		$this->assertSame( 'certifica-o.pdf', FilenameHelper::sanitize_filename( 'Certificação.pdf' ) );
	}

	public function test_get_export_filename_without_title(): void {
		Functions\when( 'sanitize_file_name' )->returnArg();

		$result = FilenameHelper::get_export_filename( 'submissions' );

		$this->assertMatchesRegularExpression( '/^submissions-\d{4}-\d{2}-\d{2}\.csv$/', $result );
	}

	public function test_get_export_filename_with_title(): void {
		Functions\when( 'sanitize_file_name' )->returnArg();

		$result = FilenameHelper::get_export_filename( 'submissions', 'Course X' );

		$this->assertMatchesRegularExpression( '/^submissions-Course X-\d{4}-\d{2}-\d{2}\.csv$/', $result );
	}

	public function test_get_export_filename_with_empty_string_title_skips_segment(): void {
		Functions\when( 'sanitize_file_name' )->returnArg();

		$result = FilenameHelper::get_export_filename( 'audit', '' );

		$this->assertMatchesRegularExpression( '/^audit-\d{4}-\d{2}-\d{2}\.csv$/', $result );
	}

	public function test_get_export_filename_passes_title_through_sanitize_file_name(): void {
		Functions\when( 'sanitize_file_name' )->alias( static function ( string $name ): string {
			return strtolower( preg_replace( '/[^a-z0-9_-]/i', '_', $name ) );
		} );

		$result = FilenameHelper::get_export_filename( 'forms', 'My / Bad Name!' );

		$this->assertMatchesRegularExpression( '/^forms-my___bad_name_-\d{4}-\d{2}-\d{2}\.csv$/', $result );
	}

	/**
	 * Helper: stub `_x` + `apply_filters` so the helper runs deterministically.
	 *
	 * `_x` returns its source string untranslated, so every expectation below
	 * is what an install with NO translation loaded receives -- English, since
	 * #1264. To exercise the translated path instead, see
	 * `stub_pdf_filename_helpers_pt_br()`.
	 */
	private function stub_pdf_filename_helpers(): void {
		Functions\when( '_x' )->alias( function ( $text, $context, $domain ) {
			return $text;
		} );
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) {
			return $value;
		} );
	}

	/**
	 * Helper: same, but `_x` answers with the pt_BR translation.
	 *
	 * The map mirrors the three entries in `ffcertificate-pt_BR.po`, so a
	 * source string renamed here without the catalogue is a red test rather
	 * than a filename that silently changes for Brazilian installs.
	 */
	private function stub_pdf_filename_helpers_pt_br(): void {
		$pt_br = array(
			'certificate' => 'certificado',
			'receipt'     => 'recibo',
			'record'      => 'ficha',
		);
		Functions\when( '_x' )->alias( function ( $text, $context, $domain ) use ( $pt_br ) {
			return $pt_br[ $text ] ?? $text;
		} );
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) {
			return $value;
		} );
	}
}
