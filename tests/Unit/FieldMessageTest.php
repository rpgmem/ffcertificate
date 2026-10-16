<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\FieldMessage;
use PHPUnit\Framework\TestCase;

/**
 * The server half of the field-message standard (#1844): the markup a known
 * error prints with the page, and the attributes that tie a control to it,
 * the same ids and classes `FFC.fieldMessage()` in `ffc-core.js` uses.
 *
 * @covers \FreeFormCertificate\Core\FieldMessage
 */
class FieldMessageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_attr' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'esc_html' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_id_is_the_scripts_id(): void {
		$this->assertSame( 'ffc-msg-email', FieldMessage::id( 'email' ) );
		// Anything an id cannot hold becomes a hyphen, as in fieldMessageId().
		$this->assertSame( 'ffc-msg-fields-a-b-', FieldMessage::id( 'fields[a b]' ) );
	}

	public function test_an_error_renders_with_its_icon_and_escaped_text(): void {
		$this->assertSame(
			'<span id="ffc-msg-phone" class="ffc-field-message is-error ffc-icon-error">Bad &lt;b&gt;value&lt;/b&gt;</span>',
			FieldMessage::render( 'phone', 'Bad <b>value</b>' )
		);
	}

	public function test_a_warning_renders_with_the_warning_icon(): void {
		$this->assertSame(
			'<span id="ffc-msg-n" class="ffc-field-message is-warning ffc-icon-warning">Careful</span>',
			FieldMessage::render( 'n', 'Careful', 'warning' )
		);
	}

	public function test_a_printed_message_carries_no_live_region_role(): void {
		$this->assertStringNotContainsString( 'role=', FieldMessage::render( 'n', 'x' ) );
	}

	public function test_no_text_prints_nothing(): void {
		$this->assertSame( '', FieldMessage::render( 'n', '' ) );
		$this->assertSame( '', FieldMessage::control_attributes( 'n', '' ) );
		$this->assertSame( '', FieldMessage::control_class( '' ) );
	}

	public function test_an_unknown_kind_is_an_error(): void {
		$this->assertStringContainsString( 'is-error ffc-icon-error', FieldMessage::render( 'n', 'x', 'nope' ) );
		$this->assertSame( 'is-invalid', FieldMessage::control_class( 'x', 'nope' ) );
	}

	public function test_an_error_ties_the_control_appending_the_message_after_the_hint(): void {
		$this->assertSame(
			' class="is-invalid" aria-invalid="true" data-ffc-message-id="ffc-msg-n" aria-describedby="n-hint ffc-msg-n"',
			FieldMessage::control_attributes( 'n', 'x', 'error', 'n-hint' )
		);
	}

	public function test_a_warning_sets_no_aria_invalid(): void {
		$attrs = FieldMessage::control_attributes( 'n', 'x', 'warning' );
		$this->assertStringContainsString( 'class="is-warning"', $attrs );
		$this->assertStringNotContainsString( 'aria-invalid', $attrs );
		$this->assertSame( 'is-warning', FieldMessage::control_class( 'x', 'warning' ) );
	}

	public function test_a_hint_alone_is_still_described(): void {
		$this->assertSame( ' aria-describedby="n-hint"', FieldMessage::control_attributes( 'n', '', 'error', 'n-hint' ) );
	}

	public function test_the_class_can_be_left_to_the_caller(): void {
		$attrs = FieldMessage::control_attributes( 'n', 'x', 'error', '', false );
		$this->assertStringNotContainsString( 'class=', $attrs );
		$this->assertStringContainsString( 'aria-invalid="true"', $attrs );
	}

	public function test_the_script_strings_carry_the_required_message(): void {
		$this->assertSame( array( 'fieldRequired' => 'This field is required.' ), FieldMessage::script_strings() );
	}

	public function test_the_kinds_match_the_scripts_map(): void {
		$js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/ffc-core.js' );
		$this->assertStringContainsString( "error: { message: 'is-error', icon: 'ffc-icon-error', control: 'is-invalid', role: 'alert', invalid: true }", $js );
		$this->assertStringContainsString( "warning: { message: 'is-warning', icon: 'ffc-icon-warning', control: 'is-warning', role: 'status', invalid: false }", $js );
		$this->assertStringContainsString( "'ffc-msg-' + String(key).replace(/[^A-Za-z0-9_-]/g, '-')", $js, 'The id rule matches FieldMessage::id().' );
	}
}
