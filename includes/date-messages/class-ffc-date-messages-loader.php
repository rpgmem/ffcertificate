<?php
/**
 * Date Messages module loader.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single bootstrap entry point of the date-messages screens (#1538).
 *
 * `Loader` calls it only while the module toggle is on, so turning the module
 * off removes the menu, the screen and its AJAX with nothing else to gate.
 * The daily cron and the unsubscribe link are wired by `Loader` itself, beside
 * the plugin's other cron callbacks: WP-Cron never runs with `is_admin()`
 * true, and the unsubscribe link must keep working whatever the toggle says.
 */
class DateMessagesLoader {

	/**
	 * Admin page, held alive for its hooks.
	 *
	 * @var DateMessagesAdminPage|null
	 */
	protected ?DateMessagesAdminPage $admin_page = null;

	/**
	 * Wire the module's admin side.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ! is_admin() ) {
			return;
		}

		$this->admin_page = new DateMessagesAdminPage();
		$this->admin_page->init();

		DateMessagesAjaxEndpoint::init();
	}
}
