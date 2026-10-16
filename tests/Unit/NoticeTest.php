<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\Icons;
use FreeFormCertificate\Core\Notice;
use PHPUnit\Framework\TestCase;

/**
 * The public notice component (#1842): its markup, its escaping, its tones,
 * icons and roles.
 *
 * @covers \FreeFormCertificate\Core\Notice
 */
class NoticeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_attr' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_html' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'wp_kses_post' )->alias( static fn( $v ) => strip_tags( (string) $v, '<a><strong><em>' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_it_prints_the_component_markup(): void {
		$html = Notice::render( 'warning', 'clock', 'Wait a minute.', 'Too many attempts' );

		$this->assertSame(
			'<div class="ffc-notice ffc-notice-warning" role="status">'
			. '<span class="ffc-notice-icon ffc-icon-clock" aria-hidden="true"></span>'
			. '<div class="ffc-notice-body"><strong class="ffc-notice-title">Too many attempts</strong><p class="ffc-notice-text">Wait a minute.</p></div>'
			. '</div>',
			$html
		);
	}

	public function test_the_title_is_optional(): void {
		$html = Notice::render( 'info', 'info', 'Just this.' );
		$this->assertStringNotContainsString( 'ffc-notice-title', $html );
		$this->assertStringContainsString( '<p class="ffc-notice-text">Just this.</p>', $html );
	}

	public function test_text_and_title_are_escaped_by_default(): void {
		$html = Notice::render( 'danger', 'error', '<script>alert(1)</script>', '<b>x</b>' );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringContainsString( '&lt;b&gt;x&lt;/b&gt;', $html );
	}

	public function test_html_is_passed_through_kses_only_when_asked(): void {
		$html = Notice::render( 'info', 'info', 'Read <a href="/x">this</a><script>x</script>', '', array( 'html' => true ) );

		$this->assertStringContainsString( '<a href="/x">this</a>', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_a_list_of_paragraphs_prints_one_each_and_skips_empty_ones(): void {
		$html = Notice::render( 'warning', 'warning', array( 'First.', '', 'Second.' ) );
		$this->assertSame( 2, substr_count( $html, '<p class="ffc-notice-text">' ) );
	}

	/**
	 * @dataProvider tones
	 */
	public function test_each_tone_prints_its_class( string $tone ): void {
		$this->assertStringStartsWith( '<div class="ffc-notice ffc-notice-' . $tone . '"', Notice::render( $tone, 'info', 'x' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function tones(): array {
		return array(
			'info'    => array( 'info' ),
			'warning' => array( 'warning' ),
			'danger'  => array( 'danger' ),
			'success' => array( 'success' ),
			'neutral' => array( 'neutral' ),
		);
	}

	public function test_an_unknown_tone_is_info(): void {
		$this->assertStringStartsWith( '<div class="ffc-notice ffc-notice-info"', Notice::render( 'purple" onclick="x', 'info', 'x' ) );
	}

	public function test_an_icon_outside_the_registry_falls_back_to_the_tones_own(): void {
		$this->assertStringContainsString( 'ffc-icon-error"', Notice::render( 'danger', 'not-an-icon', 'x' ) );
		$this->assertStringContainsString( 'ffc-icon-warning"', Notice::render( 'warning', '" onmouseover="x', 'x' ) );
	}

	public function test_every_tone_fallback_icon_is_a_registry_class(): void {
		foreach ( Notice::TONES as $tone => $icon ) {
			$this->assertArrayHasKey( $icon, Icons::classes(), "The {$tone} fallback icon is drawn by the registry." );
		}
	}

	public function test_roles_and_live_regions(): void {
		$this->assertStringContainsString( 'role="alert" aria-live="assertive"', Notice::render( 'danger', 'error', 'x', '', array( 'role' => 'alert', 'live' => 'assertive' ) ) );
		$this->assertStringContainsString( 'role="note"', Notice::render( 'info', 'eye', 'x', '', array( 'role' => 'note' ) ) );
		$this->assertStringContainsString( 'role="status"', Notice::render( 'info', 'eye', 'x', '', array( 'role' => 'banner' ) ), 'An unknown role is status.' );
		$this->assertStringNotContainsString( 'aria-live', Notice::render( 'info', 'eye', 'x', '', array( 'live' => 'rude' ) ) );
	}

	public function test_the_hook_and_only_safe_attributes_are_printed(): void {
		$html = Notice::render(
			'info',
			'info',
			'x',
			'',
			array(
				'hook'  => 'quiz-leave',
				'attrs' => array(
					'id'       => 'n"1',
					'data-a'   => 'b',
					'onclick'  => 'evil()',
					'style'    => 'color:red',
				),
			)
		);

		$this->assertStringContainsString( 'data-ffc-notice="quiz-leave"', $html );
		$this->assertStringContainsString( 'id="n&quot;1"', $html );
		$this->assertStringContainsString( 'data-a="b"', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( 'style', $html, 'A notice never carries inline CSS.' );
	}

	public function test_actions_print_after_the_text_through_kses(): void {
		$html = Notice::render(
			'warning',
			'lock',
			'Log in first.',
			'',
			array( 'actions' => '<a href="/login" class="button">Login</a><script>evil()</script>' )
		);

		$this->assertStringContainsString(
			'<p class="ffc-notice-text">Log in first.</p><div class="ffc-notice-actions"><a href="/login" class="button">Login</a>evil()</div></div></div>',
			$html
		);
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_no_actions_prints_no_actions_row(): void {
		$this->assertStringNotContainsString( 'ffc-notice-actions', Notice::render( 'info', 'info', 'x', '', array( 'actions' => '' ) ) );
		$this->assertStringNotContainsString( 'ffc-notice-actions', Notice::render( 'info', 'info', 'x' ) );
	}

	public function test_blocks_put_trusted_html_in_the_body_as_it_is(): void {
		// The admin callouts of #1843 bring their own paragraphs and lists.
		Functions\when( 'wp_kses_post' )->alias( static fn( $v ) => strip_tags( (string) $v, '<a><strong><em><p>' ) );
		$html = Notice::render( 'info', 'info', '<p>One</p><p>Two <strong>bold</strong></p>', '', array( 'blocks' => true ) );

		$this->assertStringContainsString( '<div class="ffc-notice-body"><p>One</p><p>Two <strong>bold</strong></p></div>', $html );
		$this->assertStringNotContainsString( 'ffc-notice-text', $html, 'no paragraph is wrapped in another' );
	}

	public function test_blocks_still_go_through_kses(): void {
		$html = Notice::render( 'danger', 'error', '<p>ok</p><script>x()</script>', '', array( 'blocks' => true ) );

		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_further_classes_are_added_and_filtered(): void {
		$html = Notice::render( 'warning', 'warning', 'm', '', array( 'classes' => array( 'ffc-role-impact', 'x" onclick="y', '' ) ) );

		$this->assertStringStartsWith( '<div class="ffc-notice ffc-notice-warning ffc-role-impact xonclicky"', $html );
	}
}
