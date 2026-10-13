<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\QuizGradingHandler;
use FreeFormCertificate\UserDashboard\CapabilityManager;
use FreeFormCertificate\UserDashboard\CapabilityMigrator;

/**
 * Grading quiz answers has a capability of its own (#1732).
 *
 * @covers \FreeFormCertificate\UserDashboard\CapabilityMigrator
 */
class QuizGradeCapabilityTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Whoever could open a submission to fix it can grade it after the upgrade.
	 */
	public function test_the_grant_map_seeds_grading_from_editing(): void {
		$this->assertSame(
			array( 'ffc_edit_certificates' => array( 'ffc_grade_certificates' ) ),
			CapabilityMigrator::grade_cap_grant_map()
		);
	}

	public function test_the_handler_gates_on_a_registered_capability(): void {
		$this->assertContains( QuizGradingHandler::CAPABILITY, CapabilityManager::get_all_capabilities() );
	}

	public function test_the_action_is_not_a_capability_slug(): void {
		$this->assertNotContains( QuizGradingHandler::ACTION, CapabilityManager::get_all_capabilities() );
	}
}
