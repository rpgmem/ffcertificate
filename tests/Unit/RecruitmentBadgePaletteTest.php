<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentBadgePalette;

/**
 * Guards the generated badge palette (#1193).
 *
 * The colours are operator data, so they cannot live in a stylesheet; they are
 * emitted as rules through `wp_add_inline_style()`. Two things can go wrong
 * with that arrangement and neither is visible by reading either side alone:
 * a status the code can render but the palette does not colour (the badge
 * comes out with no background — the #1191 `definitive` case), and a rule for
 * a status that no longer exists (dead CSS that reads as coverage).
 *
 * So the register is cross-checked against the label maps, which are the
 * domain's own list of statuses, in both directions.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentBadgePalette
 */
class RecruitmentBadgePaletteTest extends TestCase {

	/**
	 * Label map => the variant class prefix its statuses render under.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const LABEL_MAPS = array(
		'notice_status_label'         => array( 'includes/recruitment/class-ffc-recruitment-admin-page.php', 'ffc-recruitment-status-' ),
		'classification_status_label' => array( 'includes/recruitment/class-ffc-recruitment-admin-page.php', 'ffc-recruitment-status-' ),
		'status_label'                => array( 'includes/recruitment/class-ffc-recruitment-public-shortcode-renderer.php', 'ffc-recruitment-status-' ),
		'preview_status_label'        => array( 'includes/recruitment/class-ffc-recruitment-public-shortcode-renderer.php', 'ffc-recruitment-preview-status-' ),
	);

	/**
	 * Variants with no label map: the subscription badge is a boolean, so its
	 * two values are built inline rather than looked up.
	 *
	 * @var array<int, string>
	 */
	private const VARIANTS_WITHOUT_LABEL_MAP = array(
		'ffc-recruitment-subscription-pcd',
		'ffc-recruitment-subscription-geral',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Recruitment\\RecruitmentBadgePalette' );
		Functions\when( 'get_option' )->justReturn( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Every variant class the generated CSS declares.
	 *
	 * @return array<int, string>
	 */
	private static function emitted_variants(): array {
		preg_match_all( '/^\.([a-z0-9_-]+)\{/mi', RecruitmentBadgePalette::css(), $found );

		return $found[1];
	}

	/**
	 * The statuses each label map knows, as variant class names.
	 *
	 * @return array<int, string>
	 */
	private static function variants_from_label_maps(): array {
		$out = array();

		foreach ( self::LABEL_MAPS as $method => list( $path, $prefix ) ) {
			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
			$at     = strpos( $source, ' function ' . $method . '(' );
			if ( false === $at ) {
				continue;
			}
			// The map is the array literal that opens right after the signature;
			// reading to the method's closing brace is enough because these are
			// one-statement lookups.
			$body = substr( $source, $at, 1200 );
			preg_match_all( "/'([a-z0-9_]+)'\s*=>\s*__\(/i", $body, $found );
			foreach ( $found[1] as $status ) {
				$out[] = $prefix . $status;
			}
		}

		return array_values( array_unique( $out ) );
	}

	// ==================================================================
	// Autoverificação
	// ==================================================================

	/**
	 * A scan that collapses must never read as "clean" (#1071 / #1094).
	 */
	public function test_the_scans_find_something(): void {
		$this->assertGreaterThanOrEqual( 15, count( self::variants_from_label_maps() ), 'A varredura dos mapas de rótulo desabou.' );
		$this->assertGreaterThanOrEqual( 15, count( self::emitted_variants() ), 'O gerador não emitiu regra nenhuma.' );
	}

	// ==================================================================
	// As duas direções
	// ==================================================================

	/**
	 * Direction A — a status the code can render has a colour.
	 *
	 * Without this, a new status renders with no background at all, which is
	 * exactly what `definitive` did until #1191 found it by accident.
	 */
	public function test_every_renderable_status_has_a_generated_rule(): void {
		$missing = array_diff( self::variants_from_label_maps(), self::emitted_variants() );

		$this->assertSame(
			array(),
			array_values( $missing ),
			"Status que o código renderiza e a paleta não colore:\n  " . implode( "\n  ", $missing )
		);
	}

	/**
	 * Direction B — a rule points at a status that exists.
	 *
	 * The mirror of A, and the one that catches a migrated-away enum: the
	 * recruitment sheet carried a rule for `active` long after the activator
	 * had rewritten every row to `definitive`.
	 */
	public function test_every_generated_rule_names_a_real_status(): void {
		$known = array_merge( self::variants_from_label_maps(), self::VARIANTS_WITHOUT_LABEL_MAP );
		$stale = array_diff( self::emitted_variants(), $known );

		$this->assertSame(
			array(),
			array_values( $stale ),
			"Regras geradas para status que não existem:\n  " . implode( "\n  ", $stale )
		);
	}

	// ==================================================================
	// A forma do que é gerado
	// ==================================================================

	/**
	 * A rule that paints a ground without painting its text is #1126 defeito 4:
	 * a form control, and a badge, do not inherit the colour that would make
	 * the pair readable.
	 */
	public function test_every_rule_declares_both_halves_of_the_pair(): void {
		foreach ( explode( "\n", RecruitmentBadgePalette::css() ) as $rule ) {
			if ( '' === trim( $rule ) ) {
				continue;
			}
			$this->assertStringContainsString( 'background:', $rule );
			$this->assertStringContainsString( 'color:', $rule );
		}
	}

	/**
	 * The value is interpolated into a `<style>` block, so anything that is not
	 * a hex colour must not travel verbatim.
	 */
	public function test_a_stored_value_that_is_not_a_colour_cannot_inject_css(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'notice_status_color_draft' => 'red;} body {display:none' )
		);

		$css = RecruitmentBadgePalette::css();

		$this->assertStringNotContainsString( 'display:none', $css );
		$this->assertStringContainsString( '.ffc-recruitment-status-draft{background:#e9ecef', $css );
	}

	/**
	 * The operator's choice reaches the rule.
	 */
	public function test_a_stored_colour_reaches_the_generated_rule(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'notice_status_color_draft' => '#123456' )
		);

		$this->assertStringContainsString( '.ffc-recruitment-status-draft{background:#123456', RecruitmentBadgePalette::css() );
	}

	/**
	 * `attach()` appends to the handle it is given — inline styles print after
	 * that handle's own file, which is what makes the generated rule win.
	 */
	public function test_attach_appends_to_the_supplied_handle(): void {
		$seen = array();
		Functions\when( 'wp_add_inline_style' )->alias(
			static function ( $handle, $css ) use ( &$seen ) {
				$seen[ $handle ] = $css;
				return true;
			}
		);

		RecruitmentBadgePalette::attach( 'ffc-recruitment-public' );

		$this->assertArrayHasKey( 'ffc-recruitment-public', $seen );
		$this->assertStringContainsString( '.ffc-recruitment-status-draft{', $seen['ffc-recruitment-public'] );
	}
	/**
	 * A status with no rule of its own still renders as a badge.
	 *
	 * The helpers used to floor this with a `?? '#e9ecef'` on the colour
	 * lookup. With the colour out of the markup the floor has to be CSS, and
	 * putting it here rather than in the two module sheets keeps it in one
	 * place and lets it read the palette, so it follows the dark theme.
	 */
	public function test_the_families_carry_a_neutral_floor(): void {
		$first = strtok( RecruitmentBadgePalette::css(), "\n" );

		$this->assertStringContainsString( '.ffc-recruitment-status-badge', (string) $first );
		$this->assertStringContainsString( '.ffc-recruitment-preview-status-badge', (string) $first );
		$this->assertStringContainsString( '.ffc-recruitment-subscription-badge', (string) $first );
		$this->assertStringContainsString( 'var(--ffc-badge-row-bg)', (string) $first );
		$this->assertStringContainsString( 'var(--ffc-badge-row-text)', (string) $first );
	}

	/**
	 * The floor comes FIRST, or it would outrank the variant it exists to
	 * back up — same specificity, so order is the whole mechanism.
	 */
	public function test_the_floor_precedes_every_variant(): void {
		$lines = explode( "\n", RecruitmentBadgePalette::css() );

		$this->assertStringStartsWith( '.ffc-recruitment-status-badge,', $lines[0] );
		$this->assertGreaterThan( 15, count( $lines ) );
	}

}
