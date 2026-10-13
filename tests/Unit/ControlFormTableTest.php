<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The steps and segmented controls keep their layout inside a .form-table.
 *
 * WordPress's forms.css declares `.form-table td fieldset label { display:
 * inline-block; margin: .35em 0 .5em !important }`. Both controls are a
 * fieldset of labels, so without an override that outranks it a stop loses
 * its column (its mark sits left of the rail) and a segment gains a margin.
 * That shipped once on every settings table that adopted the steps control
 * (#1750); the quiz metabox, outside a .form-table, never showed it.
 *
 * @coversNothing
 */
class ControlFormTableTest extends TestCase {

	/**
	 * Option label class => the display it needs.
	 */
	private const LABELS = array(
		'ffc-control-steps-stop'       => 'flex',
		'ffc-control-segmented-option'  => 'flex',
	);

	public function test_each_control_label_outranks_the_wordpress_fieldset_label_rule(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ffc-common.css' );
		$this->assertNotSame( '', $css );

		foreach ( self::LABELS as $class => $display ) {
			$pattern = '/\.form-table td fieldset \.' . preg_quote( $class, '/' ) . '\s*\{([^}]*)\}/';
			$this->assertMatchesRegularExpression( $pattern, $css, "{$class} has a .form-table override" );
			preg_match( $pattern, $css, $m );
			$this->assertMatchesRegularExpression( '/display:\s*' . $display . '\s*;/', $m[1], "{$class} keeps display: {$display}" );
			$this->assertMatchesRegularExpression( '/margin:\s*0\s*!important\s*;/', $m[1], "{$class} beats the forced margin, which is !important in core" );
		}
	}
}
