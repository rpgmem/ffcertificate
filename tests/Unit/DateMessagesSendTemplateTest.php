<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\Rule;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The Send now tab of Date Messages (#2336): it offers only the rules that
 * can be sent, which are the active ones, and says why an account without
 * the send capability sees no Send button.
 *
 * @coversNothing
 */
class DateMessagesSendTemplateTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		foreach ( array( '__', 'esc_html', 'esc_attr', 'esc_url', 'esc_html__', 'esc_attr__', 'sanitize_text_field', 'wp_kses_post' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) { echo $text; } );
		Functions\when( 'esc_attr_e' )->alias( static function ( $text ) { echo $text; } );
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.org/wp-admin/' . $path );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A rule.
	 *
	 * @param int  $id     Id.
	 * @param bool $active Whether it is active.
	 * @return Rule
	 */
	private function rule( int $id, bool $active ): Rule {
		$rule = Rule::from_array(
			array(
				'id'        => $id,
				'name'      => 'Rule ' . $id,
				'subject'   => 's',
				'body'      => 'b',
				'is_active' => $active ? '1' : '0',
			)
		);
		$this->assertInstanceOf( Rule::class, $rule );
		return $rule;
	}

	/**
	 * Render the tab.
	 *
	 * @param array<int, Rule> $rules    Every rule.
	 * @param bool             $can_send Whether the user may send.
	 * @return string
	 */
	private function render( array $rules, bool $can_send ): string {
		$queue_ready = true;
		$today       = new \DateTimeImmutable( '2026-11-05', new \DateTimeZone( 'UTC' ) );
		ob_start();
		include dirname( __DIR__, 2 ) . '/templates/admin/date-messages/send.php';
		return (string) ob_get_clean();
	}

	public function test_only_active_rules_are_offered(): void {
		$html = $this->render( array( $this->rule( 1, false ), $this->rule( 2, true ) ), true );

		$this->assertStringContainsString( '>Rule 2</option>', $html );
		$this->assertStringNotContainsString( 'Rule 1', $html );
	}

	public function test_no_active_rule_says_so_instead_of_an_empty_picker(): void {
		$html = $this->render( array( $this->rule( 1, false ) ), true );

		$this->assertStringContainsString( 'No rule is active.', $html );
		$this->assertStringNotContainsString( '<select', $html );
	}

	public function test_a_missing_send_capability_is_explained(): void {
		$sender = $this->render( array( $this->rule( 2, true ) ), true );
		$viewer = $this->render( array( $this->rule( 2, true ) ), false );

		$this->assertStringNotContainsString( 'Send date messages', $sender );
		$this->assertStringContainsString( 'sending takes the "Send date messages" capability', $viewer );
	}
}
