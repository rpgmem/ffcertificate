<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\CertTemplateAdminScreen;
use FreeFormCertificate\Generators\SignedQrSize;
use PHPUnit\Framework\TestCase;

/**
 * What the visual editor reads to say which QR modes a QR box prints
 * readably (#2639). The editor computes the minimum in JavaScript, so this
 * holds its inputs and its formula to the rule the server applies when it
 * picks the payload of a placement (`SignedQrSize::px_for()`).
 *
 * @covers \FreeFormCertificate\Admin\CertTemplateAdminScreen::qr_fit
 */
final class CertTemplateQrFitTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_it_lists_the_modes_largest_first_with_the_modules_the_server_measures(): void {
		$fit = CertTemplateAdminScreen::qr_fit();

		$this->assertSame( array( 'Signed, full', 'Signed, compact', 'Link only' ), array_column( $fit['modes'], 'label' ) );
		$this->assertSame(
			array(
				SignedQrSize::modules( SignedQrSize::sample( 'full' ) ),
				SignedQrSize::modules( SignedQrSize::sample( 'compact' ) ),
				SignedQrSize::modules( SignedQrSize::sample( 'link' ) ),
			),
			array_column( $fit['modes'], 'modules' )
		);
		$modules = array_column( $fit['modes'], 'modules' );
		$this->assertGreaterThan( $modules[1], $modules[0], 'The editor reads the list largest first.' );
		$this->assertGreaterThan( $modules[2], $modules[1] );
	}

	public function test_the_editor_formula_gives_the_server_minimum_at_every_quiet_zone(): void {
		$fit = CertTemplateAdminScreen::qr_fit();

		foreach ( $fit['modes'] as $mode ) {
			for ( $margin = 0; $margin <= 10; $margin++ ) {
				// What qrFitText() computes in ffc-visual-editor.js.
				$editor = (int) ceil( ( $mode['modules'] + 2 * $margin ) * $fit['modulePx'] );
				$this->assertSame( SignedQrSize::px_for( $mode['modules'], $margin ), $editor, $mode['label'] . ' at margin ' . $margin );
			}
		}
	}
}
