<?php
/**
 * Recruitment Notices List Table.
 *
 * `WP_List_Table` subclass that powers the Notices tab of the recruitment
 * admin page. Extends {@see AbstractRecruitmentListTable} for the shared
 * fetch-all / sort / paginate / bulk-delete mechanics; this class supplies
 * only the columns, row shape and domain hooks.
 *
 * The table is read-only here — sort / pagination / search / bulk-delete /
 * row actions live on the base + this class, but row creation and per-row
 * editing are owned by the admin page and the dedicated edit screen.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notices list table.
 *
 * @phpstan-import-type NoticeRow from RecruitmentNoticeReader
 */
class RecruitmentNoticesListTable extends AbstractRecruitmentListTable {

	/**
	 * Columns rendered in the table header / each row.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox" />',
			'code'        => __( 'Code', 'ffcertificate' ),
			'name'        => __( 'Name', 'ffcertificate' ),
			'status'      => __( 'Status', 'ffcertificate' ),
			'reopened'    => __( 'Reopened?', 'ffcertificate' ),
			'adjutancies' => __( 'Adjutancies', 'ffcertificate' ),
			'created_at'  => __( 'Created at', 'ffcertificate' ),
		);
	}

	/**
	 * Columns the user can sort by.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns() {
		return array(
			'code'       => array( 'code', false ),
			'name'       => array( 'name', false ),
			'status'     => array( 'status', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * Render the `code` column with row actions (Edit / Delete).
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_code( $item ): string {
		$code       = (string) $item['code'];
		$id         = (int) $item['id'];
		$edit_url   = add_query_arg(
			array(
				'page'      => RecruitmentAdminPage::PAGE_SLUG,
				'action'    => 'edit-notice',
				'notice_id' => $id,
			),
			admin_url( 'admin.php' )
		);
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'      => RecruitmentAdminPage::PAGE_SLUG,
					'action'    => 'delete-notice',
					'notice_id' => $id,
				),
				admin_url( 'admin.php' )
			),
			'ffc_recruitment_delete_notice_' . $id
		);

		$delete_consequences = wp_json_encode(
			array(
				__( 'The notice and its candidate rows will be permanently removed.', 'ffcertificate' ),
				__( 'Any classifications, calls and adjutancy links referencing it are detached.', 'ffcertificate' ),
				__( 'This cannot be undone.', 'ffcertificate' ),
			)
		);
		$actions             = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'ffcertificate' ) ),
			'delete' => $this->delete_row_action_link(
				$delete_url,
				__( 'Delete this notice?', 'ffcertificate' ),
				__( 'You are about to permanently delete this notice.', 'ffcertificate' ),
				(string) $delete_consequences
			),
		);

		return sprintf(
			'<strong><a href="%s">%s</a></strong>%s',
			esc_url( $edit_url ),
			esc_html( $code ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Status column with a colored badge.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_status( $item ): string {
		return RecruitmentAdminPage::notice_status_badge( (string) $item['status'] );
	}

	/**
	 * Reopened? column.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_reopened( $item ): string {
		return '1' === (string) $item['was_reopened'] ? esc_html__( 'Yes', 'ffcertificate' ) : '—';
	}

	/**
	 * Adjutancies column — count of attached adjutancies.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_adjutancies( $item ): string {
		$ids   = RecruitmentNoticeAdjutancyRepository::get_adjutancy_ids_for_notice( (int) $item['id'] );
		$count = count( $ids );
		if ( 0 === $count ) {
			return '<em>' . esc_html__( '(none)', 'ffcertificate' ) . '</em>';
		}
		return esc_html( (string) $count );
	}

	// ------------------------------------------------------------------
	// AbstractRecruitmentListTable hooks
	// ------------------------------------------------------------------

	/**
	 * {@inheritDoc}
	 */
	protected function singular(): string {
		return 'notice';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function plural(): string {
		return 'notices';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function ids_request_key(): string {
		return 'notice_ids';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function per_page_option(): string {
		return 'ffc_recruitment_notices_per_page';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return list<string>
	 */
	protected function search_fields(): array {
		return array( 'code', 'name' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function bulk_delete_capability(): string {
		return 'ffc_delete_recruitment';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Row id.
	 */
	protected function delete_one( int $id ): void {
		RecruitmentNoticeWriter::delete( $id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function fetch_rows(): array {
		return self::convert_rows( RecruitmentNoticeReader::get_all() );
	}

	/**
	 * Coerce repository row stdClass objects into the array shape this
	 * table speaks. WP_List_Table expects associative arrays.
	 *
	 * @param array<int, object> $rows Repository rows.
	 * @phpstan-param list<NoticeRow> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private static function convert_rows( array $rows ): array {
		return array_map(
			static function ( $row ): array {
				return array(
					'id'           => (int) $row->id,
					'code'         => (string) $row->code,
					'name'         => (string) $row->name,
					'status'       => (string) $row->status,
					'was_reopened' => (string) $row->was_reopened,
					'created_at'   => (string) $row->created_at,
				);
			},
			$rows
		);
	}
}
