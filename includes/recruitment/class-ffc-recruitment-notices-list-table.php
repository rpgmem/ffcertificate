<?php
/**
 * Recruitment Notices List Table.
 *
 * `WP_List_Table` subclass that powers the Notices page of the recruitment
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

	/** The notice statuses, in lifecycle order. */
	private const STATUSES = array( 'draft', 'preliminary', 'definitive', 'closed' );

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
			'actions'     => __( 'Actions', 'ffcertificate' ),
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
		$code     = (string) $item['code'];
		$id       = (int) $item['id'];
		$edit_url = add_query_arg(
			array(
				'page'      => RecruitmentAdminPage::PAGE_SLUG,
				'action'    => 'edit-notice',
				'notice_id' => $id,
			),
			admin_url( 'admin.php' )
		);
		return sprintf(
			'<strong><a href="%s">%s</a></strong>',
			esc_url( $edit_url ),
			esc_html( $code )
		);
	}

	/**
	 * The row's actions as buttons (#2486).
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_actions( $item ): string {
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

		$actions = array(
			'edit' => $this->edit_button( $edit_url ),
		);

		// Only a draft is deletable (#2245); a published notice is closed.
		if ( RecruitmentNoticeLocks::allows( RecruitmentNoticeLocks::DELETE, (string) $item['status'] ) ) {
			$delete_consequences = wp_json_encode(
				array(
					__( 'The draft notice, its imported lists and its adjutancy links will be permanently removed.', 'ffcertificate' ),
					__( 'This cannot be undone.', 'ffcertificate' ),
				)
			);
			$actions['delete']   = $this->delete_row_action_link(
				$delete_url,
				__( 'Delete this notice?', 'ffcertificate' ),
				__( 'You are about to permanently delete this notice.', 'ffcertificate' ),
				(string) $delete_consequences
			);
		}

		return $this->row_buttons( $actions );
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
	protected function delete_one( int $id ): bool {
		return RecruitmentDeleteService::delete_notice( $id )['success'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $kept Rows the guard kept.
	 * @return string
	 */
	protected function blocked_delete_message( int $kept ): string {
		return sprintf(
			/* translators: %d: number of notices that were not deleted */
			_n( '%d notice was not deleted: only a draft notice can be deleted.', '%d notices were not deleted: only a draft notice can be deleted.', $kept, 'ffcertificate' ),
			$kept
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function fetch_rows(): array {
		$rows   = self::convert_rows( RecruitmentNoticeReader::get_all() );
		$status = self::status_filter();
		if ( '' === $status ) {
			return $rows;
		}
		return array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $status ): bool {
					return $status === $row['status'];
				}
			)
		);
	}

	/**
	 * The status the list is narrowed to, or '' for every notice.
	 *
	 * @return string
	 */
	public static function status_filter(): string {
		$status = \FreeFormCertificate\Core\RequestInput::get_get_key( 'notice_status' );
		return in_array( $status, self::STATUSES, true ) ? $status : '';
	}

	/**
	 * The status views as pills with their counts (#2486).
	 *
	 * @return array<string, string>
	 */
	protected function get_views() {
		$counts = array_fill_keys( self::STATUSES, 0 );
		$total  = 0;
		foreach ( RecruitmentNoticeReader::get_all() as $row ) {
			$status = (string) $row->status;
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
			++$total;
		}
		$current = self::status_filter();
		$views   = array(
			'all' => sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( RecruitmentAdminPage::section_url( 'notices' ) ),
				'' === $current ? ' class="current" aria-current="page"' : '',
				esc_html__( 'All', 'ffcertificate' ),
				esc_html( number_format_i18n( $total ) )
			),
		);
		foreach ( $counts as $status => $count ) {
			$views[ $status ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( 'notice_status', $status, RecruitmentAdminPage::section_url( 'notices' ) ) ),
				$status === $current ? ' class="current" aria-current="page"' : '',
				esc_html( RecruitmentAdminPage::notice_status_label( $status ) ),
				esc_html( number_format_i18n( $count ) )
			);
		}
		return $views;
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
