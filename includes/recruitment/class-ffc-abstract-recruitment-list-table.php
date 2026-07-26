<?php
/**
 * AbstractRecruitmentListTable
 *
 * Shared base for the recruitment admin catalog tables that follow the same
 * "fetch all rows, filter/sort/paginate in PHP" shape: {@see
 * RecruitmentNoticesListTable}, {@see RecruitmentAdjutanciesListTable} and
 * {@see RecruitmentReasonsListTable}. It owns the mechanics those three
 * duplicated line-for-line — the pagination tail of `prepare_items()`, the
 * in-memory sort, the checkbox / created-at / default column renderers, the
 * bulk-delete skeleton and the destructive row-action link — leaving each
 * subclass only its columns, row shape and domain hooks.
 *
 * The candidate list table is deliberately NOT a subclass: it paginates
 * SQL-side with a hash-lookup constraint and cache priming, so it doesn't fit
 * the fetch-all template here.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.16.0
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
 * Template-method base for the recruitment fetch-all list tables.
 */
abstract class AbstractRecruitmentListTable extends \WP_List_Table {

	/**
	 * Default and max page sizes (mirrors `WP_List_Table` defaults).
	 */
	protected const DEFAULT_PER_PAGE = 20;
	protected const MAX_PER_PAGE     = 100;

	/**
	 * Constructor — wires the WP_List_Table singular/plural/ajax args from the
	 * subclass hooks.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => $this->singular(),
				'plural'   => $this->plural(),
				'ajax'     => false,
			)
		);
	}

	// ------------------------------------------------------------------
	// Subclass hooks
	// ------------------------------------------------------------------

	/**
	 * WP_List_Table `singular` arg (e.g. `notice`).
	 *
	 * @return string
	 */
	abstract protected function singular(): string;

	/**
	 * WP_List_Table `plural` arg (e.g. `notices`).
	 *
	 * @return string
	 */
	abstract protected function plural(): string;

	/**
	 * The `$_REQUEST` key holding the selected row ids for a bulk action
	 * (e.g. `notice_ids`). Also the checkbox `name` in {@see column_cb()}.
	 *
	 * @return string
	 */
	abstract protected function ids_request_key(): string;

	/**
	 * The per-user screen option key for the page size (e.g.
	 * `ffc_recruitment_notices_per_page`).
	 *
	 * @return string
	 */
	abstract protected function per_page_option(): string;

	/**
	 * Row keys the free-text search box matches against (e.g. `code`, `name`).
	 *
	 * @return list<string>
	 */
	abstract protected function search_fields(): array;

	/**
	 * The capability that gates bulk delete (admin-or). Making this a required
	 * hook means no subclass can ship a bulk-delete without a cap.
	 *
	 * @return string
	 */
	abstract protected function bulk_delete_capability(): string;

	/**
	 * Delete a single row by id (already absint-ed and > 0). Subclasses apply
	 * any per-row guard (e.g. refuse when the record is still referenced).
	 *
	 * @param int $id Row id.
	 * @return void
	 */
	abstract protected function delete_one( int $id ): void;

	/**
	 * The full, converted row set (repository read + `convert_rows()`), before
	 * search / sort / pagination.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	abstract protected function fetch_rows(): array;

	// ------------------------------------------------------------------
	// Shared WP_List_Table contract
	// ------------------------------------------------------------------

	/**
	 * Bulk actions. Subclasses that gate the control (e.g. read-only tiers)
	 * override this; the server-side {@see process_bulk_action()} gate is the
	 * real authority regardless of what renders here.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions() {
		return array(
			'bulk-delete' => __( 'Delete', 'ffcertificate' ),
		);
	}

	/**
	 * Checkbox column.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="%s[]" value="%d" />',
			esc_attr( $this->ids_request_key() ),
			(int) $item['id']
		);
	}

	/**
	 * Created-at column.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_created_at( $item ): string {
		return esc_html( (string) $item['created_at'] );
	}

	/**
	 * Default column renderer (plain, escaped string).
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
	 * Build the dataset: fetch → search → sort → paginate.
	 *
	 * The repository returns the full row set; this paginates + filters in PHP.
	 * Fine for the expected volume (tens, not thousands) — a customer crossing
	 * ~1k rows would push WHERE/LIMIT down into the repository.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$this->process_bulk_action();

		$rows = $this->fetch_rows();

		// Search.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, list pages don't need a nonce per WP convention.
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : '';
		if ( '' !== $search ) {
			$needle = strtolower( $search );
			$fields = $this->search_fields();
			$rows   = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $needle, $fields ): bool {
						foreach ( $fields as $field ) {
							if ( isset( $row[ $field ] ) && false !== strpos( strtolower( (string) $row[ $field ] ), $needle ) ) {
								return true;
							}
						}
						return false;
					}
				)
			);
		}

		// Sort.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort, no nonce per WP convention.
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( (string) $_REQUEST['orderby'] ) : 'created_at';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = isset( $_REQUEST['order'] ) && 'asc' === strtolower( (string) $_REQUEST['order'] ) ? 'asc' : 'desc';
		$rows  = $this->sort_rows( $rows, $orderby, $order );

		// Pagination.
		$per_page     = $this->get_items_per_page( $this->per_page_option(), self::DEFAULT_PER_PAGE );
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
	 * Honor the `bulk-delete` action: cap gate → nonce → per-row delete.
	 *
	 * @return void
	 */
	protected function process_bulk_action(): void {
		if ( 'bulk-delete' !== $this->current_action() ) {
			return;
		}

		// Bulk delete is gated by the domain's destructive cap, not the
		// page-level cap that merely renders the table.
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( $this->bulk_delete_capability() ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- _wpnonce is checked below.
		check_admin_referer( 'bulk-' . (string) $this->_args['plural'] );

		$key = $this->ids_request_key();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above.
		$ids = isset( $_REQUEST[ $key ] ) && is_array( $_REQUEST[ $key ] )
			? array_map( 'absint', wp_unslash( $_REQUEST[ $key ] ) )
			: array();

		foreach ( $ids as $id ) {
			if ( $id > 0 ) {
				$this->delete_one( (int) $id );
			}
		}
	}

	// ------------------------------------------------------------------
	// Shared helpers
	// ------------------------------------------------------------------

	/**
	 * In-memory natural sort over a whitelisted column (the sortable keys),
	 * falling back to `created_at`.
	 *
	 * @param array<int, array<string, mixed>> $rows    Rows.
	 * @param string                           $orderby Column key.
	 * @param string                           $order   'asc' or 'desc'.
	 * @return array<int, array<string, mixed>>
	 */
	protected function sort_rows( array $rows, string $orderby, string $order ): array {
		$allowed = array_keys( $this->get_sortable_columns() );
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

	/**
	 * Build the destructive "Delete" row-action anchor (the confirm-modal
	 * markup is identical across the tables; only the copy + URL differ).
	 *
	 * @param string $delete_url        Nonced delete URL.
	 * @param string $title             Confirm-modal title (translated, unescaped).
	 * @param string $body              Confirm-modal body (translated, unescaped).
	 * @param string $consequences_json JSON array of consequence bullet strings.
	 * @return string
	 */
	protected function delete_row_action_link( string $delete_url, string $title, string $body, string $consequences_json ): string {
		return sprintf(
			'<a href="%s" class="submitdelete" data-ffc-confirm data-ffc-confirm-title="%s" data-ffc-confirm-body="%s" data-ffc-confirm-consequences="%s" data-ffc-confirm-cta="%s" data-ffc-confirm-style="destructive">%s</a>',
			esc_url( $delete_url ),
			esc_attr( $title ),
			esc_attr( $body ),
			esc_attr( $consequences_json ),
			esc_attr__( 'Delete', 'ffcertificate' ),
			esc_html__( 'Delete', 'ffcertificate' )
		);
	}
}
