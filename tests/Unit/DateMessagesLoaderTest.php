<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\RecordAutosave;
use FreeFormCertificate\DateMessages\DateMessagesAdminPage;
use FreeFormCertificate\DateMessages\DateMessagesAjaxEndpoint;
use FreeFormCertificate\DateMessages\DateMessagesLoader;
use PHPUnit\Framework\TestCase;

/**
 * The date-messages screens are wired on admin requests only (#1538).
 *
 * The real admin page, AJAX endpoint and record autosave run; `add_action`
 * records what they register, so "the loader wired X" is read off the hooks
 * X's own `init()` / `register()` adds.
 *
 * @covers \FreeFormCertificate\DateMessages\DateMessagesLoader
 */
class DateMessagesLoaderTest extends TestCase {

	/**
	 * Every add_action call, in order.
	 *
	 * @var list<array{hook: string, callback: string}>
	 */
	private array $actions = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->actions = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ): bool {
				$this->actions[] = array(
					'hook'     => (string) $hook,
					'callback' => self::describe( $callback ),
				);
				return true;
			}
		);

		// The record autosave keeps its entities in a static registry.
		RecordAutosave::reset();
	}

	protected function tearDown(): void {
		RecordAutosave::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_admin_requests_wire_the_page_and_the_ajax(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		( new DateMessagesLoader() )->init();

		$page = DateMessagesAdminPage::class;
		$ajax = DateMessagesAjaxEndpoint::class;
		$this->assertSame(
			array(
				array( 'hook' => 'admin_menu', 'callback' => $page . '::register_menu' ),
				array( 'hook' => 'admin_enqueue_scripts', 'callback' => $page . '::enqueue' ),
				array( 'hook' => 'admin_post_' . DateMessagesAdminPage::SAVE_ACTION, 'callback' => $page . '::handle_save' ),
				array( 'hook' => 'admin_post_' . DateMessagesAdminPage::DELETE_ACTION, 'callback' => $page . '::handle_delete' ),
				array( 'hook' => 'admin_post_' . DateMessagesAdminPage::DUPLICATE_ACTION, 'callback' => $page . '::handle_duplicate' ),
				array( 'hook' => 'admin_post_' . DateMessagesAdminPage::SEND_ACTION, 'callback' => $page . '::handle_send_now' ),
				array( 'hook' => 'wp_ajax_' . DateMessagesAjaxEndpoint::PREVIEW_ACTION, 'callback' => $ajax . '::preview' ),
				array( 'hook' => 'wp_ajax_' . DateMessagesAjaxEndpoint::TEST_ACTION, 'callback' => $ajax . '::test_send' ),
				array( 'hook' => 'wp_ajax_' . DateMessagesAjaxEndpoint::MESSAGE_ACTION, 'callback' => $ajax . '::message_preview' ),
			),
			$this->actions,
			'An admin request initialises the admin page once, then the AJAX endpoint once.'
		);
		$this->assertSame( array( 'date_message_rule' ), RecordAutosave::entities(), 'The rule edit screen registers its record autosave.' );
	}

	public function test_front_end_requests_wire_nothing(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		( new DateMessagesLoader() )->init();

		$this->assertSame( array(), $this->actions, 'A front-end request initialises neither the admin page nor the AJAX endpoint.' );
		$this->assertSame( array(), RecordAutosave::entities(), 'A front-end request registers no record autosave.' );
	}

	/**
	 * A callback as `Class::method`.
	 *
	 * @param mixed $callback Callback given to add_action.
	 * @return string
	 */
	private static function describe( $callback ): string {
		if ( is_array( $callback ) ) {
			$owner = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return $owner . '::' . $callback[1];
		}
		return is_string( $callback ) ? $callback : 'closure';
	}
}
