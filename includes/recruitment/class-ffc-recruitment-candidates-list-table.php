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
 * @phpstan-import-type CandidateRow from RecruitmentCandidateReader
 */
class RecruitmentCandidatesListTable extends \WP_List_Table {

	private const DEFAULT_PER_PAGE = 20;
	private const MAX_PER_PAGE     = 100;

	/**
	 * Classification statuses the operator can filter by from the
	 * Candidates tab toolbar. Mirrors the §5.2 state machine vocabulary.
	 * Any other value typed into `?status=` is rejected and treated as
	 * "no filter".
	 *
	 * @var list<string>
	 */
	private const ALLOWED_STATUSES = array( 'empty', 'called', 'accepted', 'not_shown', 'hired', 'withdrew' );

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

		$delete_consequences = wp_json_encode(
			array(
				__( 'The candidate record will be permanently removed.', 'ffcertificate' ),
				__( 'Delete is blocked if any classification still references this candidate.', 'ffcertificate' ),
				__( 'This cannot be undone.', 'ffcertificate' ),
			)
		);
		$actions             = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'ffcertificate' ) ),
			'delete' => sprintf(
				'<a href="%s" class="submitdelete" data-ffc-confirm data-ffc-confirm-title="%s" data-ffc-confirm-body="%s" data-ffc-confirm-consequences="%s" data-ffc-confirm-cta="%s" data-ffc-confirm-style="destructive">%s</a>',
				esc_url( $delete_url ),
				esc_attr__( 'Delete this candidate?', 'ffcertificate' ),
				esc_attr__( 'You are about to permanently delete this candidate.', 'ffcertificate' ),
				esc_attr( (string) $delete_consequences ),
				esc_attr__( 'Delete', 'ffcertificate' ),
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$rf = isset( $_REQUEST['rf'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['rf'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$email = isset( $_REQUEST['email'] ) ? sanitize_email( wp_unslash( (string) $_REQUEST['email'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$adjutancy_id = isset( $_REQUEST['adjutancy_id'] ) ? absint( wp_unslash( (string) $_REQUEST['adjutancy_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$status_raw = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['status'] ) ) : '';
		$status     = in_array( $status_raw, self::ALLOWED_STATUSES, true ) ? $status_raw : '';

		// Resolve CPF / RF / email to a candidate-id constraint set.
		// Each is independently optional; when all three are absent the
		// constraint is `null` (no narrowing). Otherwise we intersect
		// the matches — typing CPF + email means "the candidate with
		// this CPF AND this email", not the union. If any of the three
		// resolves to zero matches we short-circuit to an empty result
		// without hitting the main paginated query.
		$id_constraint = self::resolve_id_constraint( $cpf, $rf, $email );

		$per_page     = $this->get_items_per_page( 'ffc_recruitment_candidates_per_page', self::DEFAULT_PER_PAGE );
		$per_page     = min( max( 1, $per_page ), self::MAX_PER_PAGE );
		$current_page = max( 1, $this->get_pagenum() );
		$offset       = ( $current_page - 1 ) * $per_page;

		$total_items = RecruitmentCandidateReader::count_paginated_filtered(
			$search,
			$id_constraint,
			$adjutancy_id,
			$status
		);
		$raw_rows    = RecruitmentCandidateReader::get_paginated_filtered(
			$search,
			$id_constraint,
			$adjutancy_id,
			$status,
			$per_page,
			$offset
		);

		$this->items = array_map( array( self::class, 'convert_row' ), $raw_rows );

		// Warm WordPress's user-object cache for every promoted candidate
		// on the page in a single query, so {@see self::column_user_id()}'s
		// per-row `get_userdata()` calls hit the cache instead of running
		// N separate `SELECT * FROM wp_users WHERE ID = %d` lookups.
		self::prime_user_cache_for_items( $this->items );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / max( 1, $per_page ) ),
			)
		);
	}

	/**
	 * Pre-fetch every promoted candidate's WP_User object in one query
	 * so the per-row `column_user_id()` renderers read from cache.
	 *
	 * Uses WordPress's `_prime_user_caches()` helper when available
	 * (canonical batch-prime entry point); falls back to a direct
	 * `WP_User_Query` otherwise so this works on environments that
	 * don't expose the private helper.
	 *
	 * @since 6.2.0
	 * @param array<int, array<string, mixed>> $items Already-prepared row arrays.
	 * @return void
	 */
	private static function prime_user_cache_for_items( array $items ): void {
		$user_ids = array();
		foreach ( $items as $item ) {
			$uid = isset( $item['user_id'] ) ? (int) $item['user_id'] : 0;
			if ( $uid > 0 ) {
				$user_ids[] = $uid;
			}
		}
		$user_ids = array_values( array_unique( $user_ids ) );
		if ( empty( $user_ids ) ) {
			return;
		}

		if ( function_exists( '_prime_user_caches' ) ) {
			_prime_user_caches( $user_ids );
			return;
		}

		// Fallback path — instantiates a single WP_User_Query whose
		// `include` filter forces a one-shot SELECT instead of N calls.
		new \WP_User_Query(
			array(
				'include' => $user_ids,
				'fields'  => 'all_with_meta',
				'number'  => count( $user_ids ),
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$rf = isset( $_REQUEST['rf'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['rf'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$email = isset( $_REQUEST['email'] ) ? sanitize_email( wp_unslash( (string) $_REQUEST['email'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$adjutancy_id = isset( $_REQUEST['adjutancy_id'] ) ? absint( wp_unslash( (string) $_REQUEST['adjutancy_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$status_raw = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['status'] ) ) : '';
		$status     = in_array( $status_raw, self::ALLOWED_STATUSES, true ) ? $status_raw : '';

		echo '<div class="alignleft actions">';
		echo '<input type="text" name="cpf" value="' . esc_attr( $cpf ) . '" placeholder="' . esc_attr__( 'CPF (digits only)', 'ffcertificate' ) . '" size="15">';
		echo ' <input type="text" name="rf" value="' . esc_attr( $rf ) . '" placeholder="' . esc_attr__( 'RF (digits only)', 'ffcertificate' ) . '" size="10">';
		echo ' <input type="email" name="email" value="' . esc_attr( $email ) . '" placeholder="' . esc_attr__( 'Email (exact)', 'ffcertificate' ) . '" size="22">';

		// Adjutancy dropdown — limits the result set to candidates with at
		// least one classification in the selected adjutancy. Combinable
		// with every other filter via the unified paginated query.
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

		// Status dropdown — limits to candidates with at least one
		// definitive classification in the selected status (§5.2 enum).
		echo ' <select name="status">';
		echo '<option value="">' . esc_html__( 'All statuses', 'ffcertificate' ) . '</option>';
		$status_labels = array(
			'empty'     => __( 'Waiting', 'ffcertificate' ),
			'called'    => __( 'Called', 'ffcertificate' ),
			'accepted'  => __( 'Accepted', 'ffcertificate' ),
			'not_shown' => __( 'Did not show up', 'ffcertificate' ),
			'hired'     => __( 'Hired', 'ffcertificate' ),
			'withdrew'  => __( 'Withdrew', 'ffcertificate' ),
		);
		foreach ( $status_labels as $value => $label ) {
			$is_selected = $value === $status ? ' selected' : '';
			echo '<option value="' . esc_attr( $value ) . '"' . esc_attr( $is_selected ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		echo ' <input type="submit" class="button" value="' . esc_attr__( 'Filter', 'ffcertificate' ) . '">';
		echo '</div>';
	}

	/**
	 * Resolve CPF / RF / email search inputs into a candidate-id
	 * constraint set the paginated query can use as a `c.id IN (...)`
	 * narrowing. The three filters are AND-ed (intersection).
	 *
	 * Returns:
	 *   - `null` when none of the three is set (no narrowing applied).
	 *   - empty `array()` when at least one is set but no candidate
	 *     matches that input (caller should short-circuit to 0 rows).
	 *   - non-empty `list<int>` otherwise.
	 *
	 * @since 6.6.2
	 * @param string $cpf   Operator-typed CPF (digits or formatted).
	 * @param string $rf    Operator-typed RF (digits or formatted).
	 * @param string $email Operator-typed email (post-sanitize_email).
	 * @return list<int>|null
	 */
	private static function resolve_id_constraint( string $cpf, string $rf, string $email ): ?array {
		$sets = array();

		if ( '' !== $cpf ) {
			$sets[] = self::resolve_cpf_or_rf_to_ids( $cpf, 'cpf' );
		}
		if ( '' !== $rf ) {
			$sets[] = self::resolve_cpf_or_rf_to_ids( $rf, 'rf' );
		}
		if ( '' !== $email ) {
			$hash   = (string) Encryption::hash( $email );
			$sets[] = '' === $hash ? array() : RecruitmentCandidateReader::get_ids_by_email_hash( $hash );
		}

		if ( empty( $sets ) ) {
			return null;
		}

		// Intersect every non-null set. Any empty set propagates → no
		// candidate matches all the filters typed by the operator.
		$intersection = array_shift( $sets );
		foreach ( $sets as $next ) {
			$intersection = array_values( array_intersect( $intersection, $next ) );
			if ( empty( $intersection ) ) {
				return array();
			}
		}
		return $intersection;
	}

	/**
	 * Resolve a single CPF or RF input to the matching candidate-id
	 * list (size 0 or 1 — the columns are UNIQUE).
	 *
	 * @param string $value Operator-typed value (digits or formatted).
	 * @param string $kind  Either `'cpf'` or `'rf'`.
	 * @return list<int>
	 */
	private static function resolve_cpf_or_rf_to_ids( string $value, string $kind ): array {
		$digits = \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $value );
		if ( '' === $digits ) {
			return array();
		}
		$hash = (string) Encryption::hash( $digits );
		$row  = 'cpf' === $kind
			? RecruitmentCandidateReader::get_by_cpf_hash( $hash )
			: RecruitmentCandidateReader::get_by_rf_hash( $hash );
		return null === $row ? array() : array( (int) $row->id );
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
