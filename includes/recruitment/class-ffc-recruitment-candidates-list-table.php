<?php
/**
 * Recruitment Candidates List Table.
 *
 * `WP_List_Table` subclass that powers the Candidates tab. Pagination
 * and search hit the repository directly (unlike notices/adjutancies
 * which load the full row set in PHP) — candidate volume per install
 * can run into thousands.
 *
 * Search supports name (LIKE in SQL) and CPF / RF (digit-normalized,
 * hashed via `Encryption::hash`, single-row lookup). Email and phone
 * are encrypted, so `name` is the only LIKE-searchable column.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Candidates list table.
 *
 * @phpstan-import-type CandidateRow from RecruitmentCandidateRepository
 */
class RecruitmentCandidatesListTable extends \WP_List_Table {

	private const DEFAULT_PER_PAGE = 20;
	private const MAX_PER_PAGE     = 100;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'candidate',
				'plural'   => 'candidates',
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
			'name'       => __( 'Name', 'ffcertificate' ),
			'user_id'    => __( 'WP user', 'ffcertificate' ),
			'phone'      => __( 'Phone', 'ffcertificate' ),
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
			'name' => array( 'name', false ),
		);
	}

	/**
	 * WP_List_Table contract method.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions() {
		// Sprint A2 only ships a placeholder bulk-delete; the actual
		// delete path runs through DeleteService which gates on §7-bis
		// (zero classifications + reason). Bulk hard-delete sans reason
		// would violate that, so we deliberately scope this to row
		// actions only for now.
		return array();
	}

	/**
	 * WP_List_Table contract method.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="candidate_ids[]" value="%d" />',
			(int) $item['id']
		);
	}

	/**
	 * Name column with row actions (Edit / Delete).
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_name( $item ): string {
		$name = (string) $item['name'];
		$id   = (int) $item['id'];

		$edit_url   = add_query_arg(
			array(
				'page'         => RecruitmentAdminPage::PAGE_SLUG,
				'tab'          => 'candidates',
				'action'       => 'edit-candidate',
				'candidate_id' => $id,
			),
			admin_url( 'admin.php' )
		);
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'         => RecruitmentAdminPage::PAGE_SLUG,
					'tab'          => 'candidates',
					'action'       => 'delete-candidate',
					'candidate_id' => $id,
				),
				admin_url( 'admin.php' )
			),
			'ffc_recruitment_delete_candidate_' . $id
		);

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'ffcertificate' ) ),
			'delete' => sprintf(
				'<a href="%s" onclick="return confirm(\'%s\');" class="submitdelete">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Delete this candidate? Blocked if any classification still references them.', 'ffcertificate' ) ),
				esc_html__( 'Delete', 'ffcertificate' )
			),
		);

		return sprintf(
			'<strong><a href="%s">%s</a></strong>%s',
			esc_url( $edit_url ),
			esc_html( $name ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * WP user column — link to wp-admin user edit screen if linked,
	 * em-dash otherwise. Surfaces the §4 promotion state at a glance.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_user_id( $item ): string {
		$user_id = isset( $item['user_id'] ) ? (int) $item['user_id'] : 0;
		if ( $user_id <= 0 ) {
			return '—';
		}
		$user = get_userdata( $user_id );
		if ( false === $user ) {
			// Linked to a wp_user that no longer exists — defensive.
			return '<code>#' . esc_html( (string) $user_id ) . '</code>';
		}
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( get_edit_user_link( $user_id ) ),
			esc_html( $user->user_login )
		);
	}

	/**
	 * Phone column — plaintext per §10 (low-sensitivity, admin-editable).
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_phone( $item ): string {
		$phone = isset( $item['phone'] ) ? (string) $item['phone'] : '';
		return '' === $phone ? '—' : esc_html( $phone );
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
	 * Build dataset, paginate, search.
	 *
	 * Two search modes:
	 *
	 *   - Plain text in `?s=` → LIKE on name.
	 *   - Digit-only `?cpf=` / `?rf=` → hash + single-row lookup; if a
	 *     match is found, returns just that row regardless of pagination.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$cpf = isset( $_REQUEST['cpf'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['cpf'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rf = isset( $_REQUEST['rf'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['rf'] ) ) : '';

		// CPF / RF lookup short-circuits everything else.
		if ( '' !== $cpf || '' !== $rf ) {
			$candidate   = self::lookup_by_cpf_rf( $cpf, $rf );
			$rows        = null === $candidate ? array() : array( self::convert_row( $candidate ) );
			$this->items = $rows;
			$this->set_pagination_args(
				array(
					'total_items' => count( $rows ),
					'per_page'    => 1,
					'total_pages' => 1,
				)
			);
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$adjutancy_id = isset( $_REQUEST['adjutancy_id'] ) ? absint( wp_unslash( (string) $_REQUEST['adjutancy_id'] ) ) : 0;

		$per_page     = $this->get_items_per_page( 'ffc_recruitment_candidates_per_page', self::DEFAULT_PER_PAGE );
		$per_page     = min( max( 1, $per_page ), self::MAX_PER_PAGE );
		$current_page = max( 1, $this->get_pagenum() );
		$offset       = ( $current_page - 1 ) * $per_page;

		if ( $adjutancy_id > 0 ) {
			$total_items = RecruitmentCandidateRepository::count_paginated_for_adjutancy( $search, $adjutancy_id );
			$raw_rows    = RecruitmentCandidateRepository::get_paginated_for_adjutancy( $search, $adjutancy_id, $per_page, $offset );
		} else {
			$total_items = RecruitmentCandidateRepository::count_paginated( $search );
			$raw_rows    = RecruitmentCandidateRepository::get_paginated( $search, $per_page, $offset );
		}

		$this->items = array_map( array( self::class, 'convert_row' ), $raw_rows );
		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / max( 1, $per_page ) ),
			)
		);
	}

	/**
	 * Render the list table's extra navigation block — adds CPF / RF
	 * filter inputs above the standard search box. Sits next to the
	 * top tablenav per the WP_List_Table convention.
	 *
	 * @param string $which 'top' or 'bottom'.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$cpf = isset( $_REQUEST['cpf'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['cpf'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rf = isset( $_REQUEST['rf'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['rf'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$adjutancy_id = isset( $_REQUEST['adjutancy_id'] ) ? absint( wp_unslash( (string) $_REQUEST['adjutancy_id'] ) ) : 0;

		echo '<div class="alignleft actions">';
		echo '<input type="text" name="cpf" value="' . esc_attr( $cpf ) . '" placeholder="' . esc_attr__( 'CPF (digits only)', 'ffcertificate' ) . '" size="15">';
		echo ' <input type="text" name="rf" value="' . esc_attr( $rf ) . '" placeholder="' . esc_attr__( 'RF (digits only)', 'ffcertificate' ) . '" size="10">';

		// Adjutancy dropdown — limits the result set to candidates with at
		// least one classification in the selected adjutancy. Independent
		// of the CPF/RF lookup, which short-circuits everything else.
		$adjutancies = RecruitmentAdjutancyRepository::get_all();
		if ( ! empty( $adjutancies ) ) {
			echo ' <select name="adjutancy_id">';
			echo '<option value="0">' . esc_html__( 'All adjutancies', 'ffcertificate' ) . '</option>';
			foreach ( $adjutancies as $a ) {
				$id_int      = (int) $a->id;
				$is_selected = $id_int === $adjutancy_id ? ' selected' : '';
				echo '<option value="' . esc_attr( (string) $id_int ) . '"' . esc_attr( $is_selected ) . '>' . esc_html( (string) $a->name ) . '</option>';
			}
			echo '</select>';
		}

		echo ' <input type="submit" class="button" value="' . esc_attr__( 'Filter', 'ffcertificate' ) . '">';
		echo '</div>';
	}

	/**
	 * Resolve a single candidate by CPF or RF (digits-only input).
	 *
	 * @param string $cpf Operator-typed CPF.
	 * @param string $rf  Operator-typed RF.
	 * @return object|null
	 * @phpstan-return CandidateRow|null
	 */
	private static function lookup_by_cpf_rf( string $cpf, string $rf ): ?object {
		if ( '' !== $cpf ) {
			$digits = preg_replace( '/[^0-9]/', '', $cpf ) ?? '';
			if ( '' !== $digits ) {
				$hash = (string) Encryption::hash( $digits );
				return RecruitmentCandidateRepository::get_by_cpf_hash( $hash );
			}
		}
		if ( '' !== $rf ) {
			$digits = preg_replace( '/[^0-9]/', '', $rf ) ?? '';
			if ( '' !== $digits ) {
				$hash = (string) Encryption::hash( $digits );
				return RecruitmentCandidateRepository::get_by_rf_hash( $hash );
			}
		}
		return null;
	}

	/**
	 * Coerce a CandidateRow stdClass into the array shape the table speaks.
	 *
	 * @param object $row Repository row.
	 * @phpstan-param CandidateRow $row
	 * @return array<string, mixed>
	 */
	private static function convert_row( $row ): array {
		return array(
			'id'         => (int) $row->id,
			'name'       => (string) $row->name,
			'user_id'    => null === $row->user_id ? null : (int) $row->user_id,
			'phone'      => null === $row->phone ? '' : (string) $row->phone,
			'created_at' => (string) $row->created_at,
		);
	}
}
