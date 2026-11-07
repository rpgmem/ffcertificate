<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\AdminDashboard;

/**
 * The shared pieces of a module dashboard (#2486).
 *
 * @covers \FreeFormCertificate\Core\AdminDashboard
 */
class AdminDashboardTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
		Functions\when( 'esc_attr' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => number_format( (float) $n ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_figures_escapes_and_formats(): void {
		$this->assertSame(
			'<ul class="ffc-dashboard__figures"><li><span>Draft &lt;b&gt;</span><span>1,500</span></li><li><span>Date</span><span>12/11</span></li></ul>',
			AdminDashboard::figures(
				array(
					'Draft <b>' => 1500,
					'Date'      => '12/11',
				)
			),
			'labels are escaped, numbers formatted, strings kept'
		);
	}

	public function test_section_card_draws_the_heading_body_and_buttons(): void {
		ob_start();
		AdminDashboard::section_card(
			'ffc-icon-list',
			'Rules',
			'<p>Body</p>',
			array(
				array(
					'label' => 'Open rules',
					'icon'  => 'list',
					'href'  => 'admin.php?page=x',
				),
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringStartsWith( '<div class="card ffc-dashboard__card"><h2 class="ffc-icon-list">Rules</h2><p>Body</p><div class="ffc-dashboard__actions">', $html );
		$this->assertStringContainsString( 'href="admin.php?page=x"', $html );
		$this->assertStringContainsString( 'Open rules', $html );
		$this->assertStringEndsWith( '</div></div>', trim( $html ) );
	}
}
