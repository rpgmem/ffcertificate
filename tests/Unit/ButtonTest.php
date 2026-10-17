<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\AdminUI;
use FreeFormCertificate\Core\Button;
use FreeFormCertificate\Tests\Support\CallLog;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The button family (#1874): one markup for every category, admin and public.
 *
 * @covers \FreeFormCertificate\Core\Button
 * @covers \FreeFormCertificate\Admin\AdminUI
 */
class ButtonTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Messages `_doing_it_wrong()` received.
	 *
	 * @var array<int, string>
	 */
	private array $wrong = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_attr' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'esc_html' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'esc_url' )->alias( static fn( $v ) => str_starts_with( (string) $v, 'javascript:' ) ? '' : htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( '__' )->returnArg();
		$this->wrong = array();
		Functions\when( '_doing_it_wrong' )->alias(
			function ( $method, $message ) {
				$this->wrong[] = (string) $message;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_every_category_prints_its_class(): void {
		foreach ( Button::CATEGORIES as $category ) {
			$html = Button::html(
				array(
					'label'    => 'Go',
					'category' => $category,
				)
			);
			$this->assertSame( '<button type="button" class="ffc-btn ffc-btn-' . $category . '">Go</button>', $html );
		}
		$this->assertSame( array(), $this->wrong );
	}

	public function test_the_five_categories_are_the_agreed_ones(): void {
		$this->assertSame( array( 'primary', 'neutral', 'create', 'caution', 'danger' ), Button::CATEGORIES );
	}

	public function test_an_unknown_category_falls_back_to_neutral_and_says_so(): void {
		$html = Button::html(
			array(
				'label'    => 'Go',
				'category' => 'success',
			)
		);
		$this->assertStringContainsString( 'class="ffc-btn ffc-btn-neutral"', $html );
		$this->assertCount( 1, $this->wrong );
		$this->assertStringContainsString( 'success&quot; is not a button category', $this->wrong[0] );
	}

	public function test_the_default_category_is_neutral(): void {
		$this->assertStringContainsString( 'ffc-btn-neutral', Button::html( array( 'label' => 'Go' ) ) );
		$this->assertSame( array(), $this->wrong );
	}

	public function test_a_known_icon_becomes_its_class(): void {
		$html = Button::html(
			array(
				'label'    => 'Remove',
				'category' => 'danger',
				'icon'     => 'delete',
			)
		);
		$this->assertStringContainsString( 'class="ffc-btn ffc-btn-danger ffc-icon-delete"', $html );
	}

	public function test_an_unknown_icon_is_dropped_and_reported(): void {
		$html = Button::html(
			array(
				'label' => 'Save',
				'icon'  => 'floppy',
			)
		);
		$this->assertStringNotContainsString( 'ffc-icon-', $html );
		$this->assertCount( 1, $this->wrong );
		$this->assertStringContainsString( 'floppy&quot; is not a Core\Icons class', $this->wrong[0] );
	}

	public function test_no_label_prints_nothing(): void {
		$this->assertSame( '', Button::html( array( 'category' => 'primary' ) ) );
	}

	public function test_label_and_attributes_are_escaped(): void {
		$html = Button::html(
			array(
				'label' => '<script>x</script>',
				'id'    => 'a"b',
				'class' => 'hook"><b',
				'attrs' => array( 'data-x' => '"><img>' ),
			)
		);
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img>', $html );
		$this->assertStringNotContainsString( '"><b', $html );
		$this->assertStringContainsString( '&lt;script&gt;x&lt;/script&gt;', $html );
		$this->assertStringContainsString( 'id="a&quot;b"', $html );
	}

	public function test_event_handlers_and_malformed_names_are_refused(): void {
		$html = Button::html(
			array(
				'label' => 'Go',
				'attrs' => array(
					'onclick'   => 'alert(1)',
					'OnMouseOver' => 'alert(1)',
					'bad name'  => 'x',
					'data-ok'   => 'yes',
					'data-gone' => false,
					'data-null' => null,
					'hidden'    => true,
				),
			)
		);
		$this->assertStringNotContainsString( 'alert', $html );
		$this->assertStringNotContainsString( 'bad', $html );
		$this->assertStringNotContainsString( 'data-gone', $html );
		$this->assertStringNotContainsString( 'data-null', $html );
		$this->assertStringContainsString( ' data-ok="yes"', $html );
		$this->assertStringContainsString( ' hidden>', $html );
	}

	public function test_attrs_cannot_override_the_class_or_type(): void {
		$html = Button::html(
			array(
				'label' => 'Go',
				'attrs' => array(
					'class' => 'button',
					'type'  => 'submit',
				),
			)
		);
		$this->assertSame( '<button type="button" class="ffc-btn ffc-btn-neutral">Go</button>', $html );
	}

	public function test_href_makes_a_link_without_form_attributes(): void {
		$html = Button::html(
			array(
				'label'    => 'Open',
				'href'     => 'https://example.test/?a=1&b=2',
				'type'     => 'submit',
				'name'     => 'n',
				'value'    => 'v',
				'id'       => 'open-it',
				'disabled' => true,
			)
		);
		$this->assertStringStartsWith( '<a href="https://example.test/?a=1&amp;b=2"', $html );
		$this->assertStringContainsString( 'class="ffc-btn ffc-btn-neutral disabled"', $html );
		$this->assertStringContainsString( 'id="open-it"', $html );
		$this->assertStringContainsString( 'aria-disabled="true"', $html );
		$this->assertStringNotContainsString( 'type=', $html );
		$this->assertStringNotContainsString( 'name=', $html );
		$this->assertStringNotContainsString( 'value=', $html );
		$this->assertStringEndsWith( '>Open</a>', $html );
	}

	public function test_a_hostile_href_is_emptied_by_esc_url(): void {
		$html = Button::html(
			array(
				'label' => 'Go',
				'href'  => 'javascript:alert(1)',
			)
		);
		$this->assertStringContainsString( 'href=""', $html );
	}

	public function test_a_submit_button_carries_name_value_and_disabled(): void {
		$html = Button::html(
			array(
				'label'    => 'Send',
				'category' => 'primary',
				'type'     => 'submit',
				'name'     => 'go',
				'value'    => '1',
				'disabled' => true,
			)
		);
		$this->assertSame( '<button type="submit" class="ffc-btn ffc-btn-primary" name="go" value="1" disabled>Send</button>', $html );
	}

	public function test_an_unknown_type_is_a_plain_button(): void {
		$this->assertStringContainsString( 'type="button"', Button::html( array( 'label' => 'Go', 'type' => 'image' ) ) );
	}

	public function test_extra_classes_keep_their_order_and_do_not_repeat(): void {
		$html = Button::html(
			array(
				'label'    => 'Go',
				'category' => 'create',
				'class'    => '  ffc-hook   ffc-btn  other ',
			)
		);
		$this->assertStringContainsString( 'class="ffc-btn ffc-btn-create ffc-hook other"', $html );
	}

	public function test_render_prints_the_markup_and_a_separating_newline(): void {
		ob_start();
		Button::render( array( 'label' => 'Go' ) );
		$this->assertSame( '<button type="button" class="ffc-btn ffc-btn-neutral">Go</button>' . "\n", ob_get_clean() );
	}

	public function test_admin_ui_entry_points_delegate(): void {
		$args = array(
			'label'    => 'Go',
			'category' => 'caution',
			'icon'     => 'restore',
		);
		$this->assertSame( Button::html( $args ), AdminUI::button( $args ) );

		ob_start();
		AdminUI::render_button( $args );
		$this->assertSame( Button::html( $args ) . "\n", ob_get_clean() );
	}

	public function test_the_save_button_is_the_primary_submit_in_a_submit_paragraph(): void {
		ob_start();
		AdminUI::render_save_button();
		$html = (string) ob_get_clean();
		$this->assertSame(
			'<p class="submit"><button type="submit" class="ffc-btn ffc-btn-primary ffc-icon-checkmark" name="submit">Save Changes</button>' . "\n" . '</p>',
			$html
		);

		ob_start();
		AdminUI::render_save_button( 'Save email texts', array( 'id' => 'x' ) );
		$this->assertStringContainsString( 'id="x" name="submit">Save email texts</button>', (string) ob_get_clean() );
	}

	public function test_every_icon_the_settings_screens_use_is_registered(): void {
		$log = CallLog::record( '_doing_it_wrong', null );
		foreach ( array( 'checkmark', 'image', 'cross', 'delete', 'plus', 'list', 'edit', 'restore', 'send', 'external', 'zap', 'history', 'key', 'sync', 'copy', 'calendar' ) as $icon ) {
			$this->assertStringContainsString( 'ffc-icon-' . $icon, Button::html( array( 'label' => 'x', 'icon' => $icon ) ) );
		}
		$this->assertSame( 0, $log->count() );
	}
}
