<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The background the redrawn model 3 stopped using stays shipped while forms
 * and user templates may still name it (CLAUDE.md "Legacy and tech debt").
 *
 * @coversNothing
 */
class CertificateDefaultsShimTest extends TestCase {

	public function test_the_retired_model_3_background_still_ships(): void {
		$file = dirname( __DIR__, 2 ) . '/assets/img/certificate-defaults/default_background_certificate_3.png';

		$this->assertFileExists( $file, 'Forms and user templates may point at this URL; removing it needs the exit condition in the shim log.' );
		$this->assertGreaterThan( 0, (int) filesize( $file ) );
	}
}
