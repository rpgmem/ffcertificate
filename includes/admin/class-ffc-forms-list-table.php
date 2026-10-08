<?php
/**
 * FormsListTable
 *
 * The core posts list table on the ffc_form screen, with the plugin's empty
 * state in place of WordPress's one-line `labels->not_found`.
 *
 * @since 6.35.0
 * @package FreeFormCertificate\Admin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Posts list table for forms. Every other behaviour is core's: views, search,
 * bulk actions, row actions and the plugin's columns all keep working because
 * nothing else is overridden. Swapped in by FormListColumns::list_table_class().
 */
class FormsListTable extends \WP_Posts_List_Table {

	/**
	 * Print the shared empty state.
	 *
	 * @return void
	 */
	public function no_items() {
		echo FormListColumns::empty_state(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUI::get_empty_state() escapes every value.
	}
}
