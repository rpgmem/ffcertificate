<?php
/**
 * Recruitment Notices List Table.
 *
 * `WP_List_Table` subclass that powers the Notices tab of the recruitment
 * admin page. Mirrors the patterns established by
 * {@see \FreeFormCertificate\Admin\SubmissionsList}: paginated rows,
 * sortable columns, search, bulk actions, row actions per item.
 *
 * The table is read-only here — sort / pagination / search / bulk-delete /
 * row actions live on this class, but row creation and per-row editing
 * are owned by the admin page (and, in sprint B, the dedicated edit
 * screen).
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Notices list table.
 *
 * @phpstan-import-type NoticeRow from RecruitmentNoticeRepository
 */
class RecruitmentNoticesListTable extends \WP_List_Table {

	/**
	 * Default and max page sizes (mirrors `WP_List_Table` defaults).
	 */
	private const DEFAULT_PER_PAGE = 20;
	private const MAX_PER_PAGE     = 100;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'notice',
				'plural'   => 'notices',
				'ajax'     => false,
			)
		);
	}

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
	 * Bulk actions.
	 *
	 * Delete is the only bulk action shipped in sprint A1; bulk status
	 * transitions land in sprint B alongside the per-notice edit screen
	 * (where the state-machine guards already live).
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions() {
		return array(
			'bulk-delete' => __( 'Delete', 'ffcertificate' ),
		);
	}

	/**
	 * Render the checkbox column.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="notice_ids[]" value="%d" />',
			(int) $item['id']
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

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'ffcertificate' ) ),
			'delete' => sprintf(
				'<a href="%s" onclick="return confirm(\'%s\');" class="submitdelete">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Delete this notice? This cannot be undone.', 'ffcertificate' ) ),
				esc_html__( 'Delete', 'ffcertificate' )
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
	 * Adjutancies column — count of attached adjutancies, linkable to the
	 * notice edit screen's Adjutancies section once sprint B lands.
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

	/**
	 * Created at column.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_created_at( $item ): string {
		return esc_html( (string) $item['created_at'] );
	}

	/**
	 * Default column renderer (catches `name` and any future plain
	 * string column without a dedicated method).
	 *
	 * @param array<string, mixed> $item        Row.
	 * @param string               $column_name Column key.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		$value = $item[ $column_name ] ?? '';
		return esc_html( (string) $value );
	}

	/**
	 * Build the dataset, apply pagination + search + sort, and feed the
	 * parent's `$items`.
	 *
	 * The repository returns the full row set; this method paginates +
	 * filters in PHP. That's fine for the expected data volume (notices
	 * are tens, not thousands); if a customer ever crosses 1k notices we
	 * push the WHERE/LIMIT down into the repository.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		$this->process_bulk_action();

		$rows = self::convert_rows( RecruitmentNoticeRepository::get_all() );

		// Search.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, list pages don't need a nonce per WP convention.
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : '';
		if ( '' !== $search ) {
			$needle = strtolower( $search );
			$rows   = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $needle ): bool {
						return false !== strpos( strtolower( (string) $row['code'] ), $needle )
							|| false !== strpos( strtolower( (string) $row['name'] ), $needle );
					}
				)
			);
		}

		// Sort.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort, no nonce per WP convention.
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( (string) $_REQUEST['orderby'] ) : 'created_at';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = isset( $_REQUEST['order'] ) && 'asc' === strtolower( (string) $_REQUEST['order'] ) ? 'asc' : 'desc';
		$rows  = self::sort_rows( $rows, $orderby, $order );

		// Pagination.
		$per_page     = $this->get_items_per_page( 'ffc_recruitment_notices_per_page', self::DEFAULT_PER_PAGE );
		$per_page     = min( max( 1, $per_page ), self::MAX_PER_PAGE );
		$current_page = max( 1, $this->get_pagenum() );
		$total_items  = count( $rows );
		$rows         = array_slice( $rows, ( $current_page - 1 ) * $per_page, $per_page );

		$this->items = $rows;
		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Honor the `bulk-delete` action.
	 *
	 * @return void
	 */
	protected function process_bulk_action(): void {
		if ( 'bulk-delete' !== $this->current_action() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- _wpnonce is checked below.
		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above.
		$ids = isset( $_REQUEST['notice_ids'] ) && is_array( $_REQUEST['notice_ids'] )
			? array_map( 'absint', wp_unslash( $_REQUEST['notice_ids'] ) )
			: array();

		foreach ( $ids as $id ) {
			if ( $id > 0 ) {
				RecruitmentNoticeRepository::delete( $id );
			}
		}
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

	/**
	 * In-memory sort (small dataset).
	 *
	 * @param array<int, array<string, mixed>> $rows    Rows.
	 * @param string                           $orderby Column key.
	 * @param string                           $order   'asc' or 'desc'.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sort_rows( array $rows, string $orderby, string $order ): array {
		$allowed = array( 'code', 'name', 'status', 'created_at' );
		if ( ! in_array( $orderby, $allowed, true ) ) {
			$orderby = 'created_at';
		}

		usort(
			$rows,
			static function ( $a, $b ) use ( $orderby, $order ) {
				$av  = (string) ( $a[ $orderby ] ?? '' );
				$bv  = (string) ( $b[ $orderby ] ?? '' );
				$cmp = strnatcasecmp( $av, $bv );
				return 'asc' === $order ? $cmp : -$cmp;
			}
		);

		return $rows;
	}
}
