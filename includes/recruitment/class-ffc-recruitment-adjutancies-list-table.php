<?php
/**
 * Recruitment Adjutancies List Table.
 *
 * `WP_List_Table` subclass that powers the Adjutancies tab. Mirrors
 * {@see RecruitmentNoticesListTable}: sortable columns, pagination,
 * search by slug/name, bulk delete (gated per §14 — repository's
 * `delete()` rejects when the adjutancy is referenced by any
 * notice_adjutancy or classification row), row actions (Edit / Delete).
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
 * Adjutancies list table.
 *
 * @phpstan-import-type AdjutancyRow from RecruitmentAdjutancyRepository
 */
class RecruitmentAdjutanciesListTable extends \WP_List_Table {

	private const DEFAULT_PER_PAGE = 20;
	private const MAX_PER_PAGE     = 100;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'adjutancy',
				'plural'   => 'adjutancies',
				'ajax'     => false,
			)
		);
	}

	/**
	 * WP_List_Table contract method.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'slug'       => __( 'Slug', 'ffcertificate' ),
			'name'       => __( 'Name', 'ffcertificate' ),
			'usage'      => __( 'Notices using', 'ffcertificate' ),
			'created_at' => __( 'Created at', 'ffcertificate' ),
		);
	}

	/**
	 * WP_List_Table contract method.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns() {
		return array(
			'slug'       => array( 'slug', false ),
			'name'       => array( 'name', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * WP_List_Table contract method.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions() {
		return array(
			'bulk-delete' => __( 'Delete', 'ffcertificate' ),
		);
	}

	/**
	 * WP_List_Table contract method.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="adjutancy_ids[]" value="%d" />',
			(int) $item['id']
		);
	}

	/**
	 * Slug column with row actions (Edit / Delete).
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_slug( $item ): string {
		$slug = (string) $item['slug'];
		$id   = (int) $item['id'];

		$edit_url   = add_query_arg(
			array(
				'page'         => RecruitmentAdminPage::PAGE_SLUG,
				'tab'          => 'adjutancies',
				'action'       => 'edit-adjutancy',
				'adjutancy_id' => $id,
			),
			admin_url( 'admin.php' )
		);
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'         => RecruitmentAdminPage::PAGE_SLUG,
					'tab'          => 'adjutancies',
					'action'       => 'delete-adjutancy',
					'adjutancy_id' => $id,
				),
				admin_url( 'admin.php' )
			),
			'ffc_recruitment_delete_adjutancy_' . $id
		);

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'ffcertificate' ) ),
			'delete' => sprintf(
				'<a href="%s" onclick="return confirm(\'%s\');" class="submitdelete">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Delete this adjutancy? Blocked if any notice still references it.', 'ffcertificate' ) ),
				esc_html__( 'Delete', 'ffcertificate' )
			),
		);

		return sprintf(
			'<strong><a href="%s"><code>%s</code></a></strong>%s',
			esc_url( $edit_url ),
			esc_html( $slug ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Usage column — count of notices that have this adjutancy attached.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_usage( $item ): string {
		$ids = RecruitmentNoticeAdjutancyRepository::get_notice_ids_for_adjutancy( (int) $item['id'] );
		return esc_html( (string) count( $ids ) );
	}

	/**
	 * WP_List_Table contract method.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_created_at( $item ): string {
		return esc_html( (string) $item['created_at'] );
	}

	/**
	 * Default column renderer.
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
	 * Build dataset, paginate, search, sort.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		$this->process_bulk_action();

		$rows = self::convert_rows( RecruitmentAdjutancyRepository::get_all() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : '';
		if ( '' !== $search ) {
			$needle = strtolower( $search );
			$rows   = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $needle ): bool {
						return false !== strpos( strtolower( (string) $row['slug'] ), $needle )
							|| false !== strpos( strtolower( (string) $row['name'] ), $needle );
					}
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort.
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( (string) $_REQUEST['orderby'] ) : 'created_at';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = isset( $_REQUEST['order'] ) && 'asc' === strtolower( (string) $_REQUEST['order'] ) ? 'asc' : 'desc';
		$rows  = self::sort_rows( $rows, $orderby, $order );

		$per_page     = $this->get_items_per_page( 'ffc_recruitment_adjutancies_per_page', self::DEFAULT_PER_PAGE );
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
	 * Honor the `bulk-delete` action via the gated DeleteService.
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
		$ids = isset( $_REQUEST['adjutancy_ids'] ) && is_array( $_REQUEST['adjutancy_ids'] )
			? array_map( 'absint', wp_unslash( $_REQUEST['adjutancy_ids'] ) )
			: array();

		foreach ( $ids as $id ) {
			if ( $id > 0 ) {
				RecruitmentDeleteService::delete_adjutancy( $id );
			}
		}
	}

	/**
	 * Coerce repository row stdClass objects into the array shape the
	 * table speaks.
	 *
	 * @param array<int, object> $rows Repository rows.
	 * @phpstan-param list<AdjutancyRow> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private static function convert_rows( array $rows ): array {
		return array_map(
			static function ( $row ): array {
				return array(
					'id'         => (int) $row->id,
					'slug'       => (string) $row->slug,
					'name'       => (string) $row->name,
					'created_at' => (string) $row->created_at,
				);
			},
			$rows
		);
	}

	/**
	 * In-memory sort.
	 *
	 * @param array<int, array<string, mixed>> $rows    Rows.
	 * @param string                           $orderby Column key.
	 * @param string                           $order   'asc' or 'desc'.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sort_rows( array $rows, string $orderby, string $order ): array {
		$allowed = array( 'slug', 'name', 'created_at' );
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
