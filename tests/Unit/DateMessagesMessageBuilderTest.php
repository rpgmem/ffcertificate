<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\DateSourceInterface;
use FreeFormCertificate\DateMessages\MessageBuilder;
use FreeFormCertificate\DateMessages\Rule;
use PHPUnit\Framework\TestCase;

/**
 * What a date message says, and that it can always be unsubscribed from
 * (#1538).
 *
 * @covers \FreeFormCertificate\DateMessages\MessageBuilder
 */
class DateMessagesMessageBuilderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'esc_html' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'esc_url' )->alias( static fn( $v ) => 'URL(' . $v . ')' );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => strip_tags( (string) $v ) );
		Functions\when( 'get_bloginfo' )->justReturn( 'SME' );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.org' . $p );
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.org/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A source double with fixed tokens.
	 *
	 * @return DateSourceInterface
	 */
	private function source(): DateSourceInterface {
		return new class() implements DateSourceInterface {
			public function id(): string {
				return 'birthday';
			}
			public function label(): string {
				return 'Birthday';
			}
			public function due( \DateTimeImmutable $target, int $after_user_id, int $limit ): array {
				return array();
			}
			public function tokens( int $user_id, \DateTimeImmutable $target ): array {
				return array( 'date' => '10/10/2026', 'age' => '41' );
			}
			public function sample_tokens( \DateTimeImmutable $target ): array {
				return array( 'date' => '10/10/2026', 'age' => '35' );
			}
		};
	}

	/**
	 * @param string $body Body template.
	 * @return Rule
	 */
	private function rule( string $body ): Rule {
		$rule = Rule::from_array(
			array(
				'name'    => 'r',
				'subject' => 'Hi {{first_name}} <b>!</b>',
				'body'    => $body,
			)
		);
		$this->assertInstanceOf( Rule::class, $rule );
		return $rule;
	}

	public function test_values_are_escaped_for_the_html_they_land_in(): void {
		Functions\when( 'get_user_meta' )->justReturn( '' );

		$message = MessageBuilder::build(
			$this->rule( '<p>{{name}} turns {{age}} on {{date}}, in {{days_until}} days. <a href="{{dashboard_url}}">x</a> <a href="{{unsubscribe_url}}">u</a></p>' ),
			$this->source(),
			array(
				'user_id' => 9,
				'email'   => 'a@b.c',
				'name'    => 'Ana <script>x</script> Lima',
			),
			new \DateTimeImmutable( '2026-10-10' ),
			new \DateTimeImmutable( '2026-10-03' )
		);

		$this->assertStringContainsString( 'Ana &lt;script&gt;x&lt;/script&gt; Lima turns 41 on 10/10/2026, in 7 days.', $message['body'] );
		$this->assertStringContainsString( 'href="URL(https://example.org/dashboard)"', $message['body'] );
		$this->assertStringContainsString( 'action=ffc_date_messages_unsubscribe', $message['body'] );
		$this->assertSame( 'Hi Ana !', $message['subject'], 'The first name falls back to the first word; the subject is plain text.' );
	}

	public function test_a_body_without_the_link_gets_it_appended(): void {
		Functions\when( 'get_user_meta' )->justReturn( 'Ana' );

		$message = MessageBuilder::build(
			$this->rule( '<p>Happy birthday</p>' ),
			$this->source(),
			array(
				'user_id' => 9,
				'email'   => 'a@b.c',
				'name'    => 'Ana Lima',
			),
			new \DateTimeImmutable( '2026-10-10' ),
			new \DateTimeImmutable( '2026-10-10' )
		);

		$this->assertSame( 1, substr_count( $message['body'], 'action=ffc_date_messages_unsubscribe' ) );
		$this->assertStringStartsWith( '<p>Happy birthday</p>', $message['body'] );
	}

	public function test_the_sample_uses_fictional_values_and_no_real_link(): void {
		$message = MessageBuilder::sample( 'S {{first_name}}', '<p>{{name}} {{age}} {{email}}</p>', $this->source(), new \DateTimeImmutable( '2026-10-10' ), new \DateTimeImmutable( '2026-10-03' ) );

		$this->assertSame( 'S Maria', $message['subject'] );
		$this->assertStringContainsString( 'Maria da Silva 35 maria.silva@example.org', $message['body'] );
		$this->assertStringNotContainsString( 'ffc_date_messages_unsubscribe', $message['body'] );
	}
}
