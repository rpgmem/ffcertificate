<?php
/**
 * Recruitment Reasons List Table.
 *
 * `WP_List_Table` subclass that powers the Reasons tab — the global
 * catalog of operator-defined labels attached to a preliminary-list
 * classification's `preview_status`. Mirrors the patterns in
 * {@see RecruitmentAdjutanciesListTable}.
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
 * Reasons list table.
 *
 * @phpstan-import-type ReasonRow from RecruitmentReasonRepository
 */
class RecruitmentReasonsListTable extends \WP_List_Table {

	private const DEFAULT_PER_PAGE = 20;
	private const MAX_PER_PAGE     = 100;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'reason',
				'plural'   => 'reasons',
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
			'label'      => __( 'Label', 'ffcertificate' ),
			'color'      => __( 'Badge color', 'ffcertificate' ),
			'applies_to' => __( 'Applies to', 'ffcertificate' ),
			'usage'      => __( 'In use', 'ffcertificate' ),
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
			'label'      => array( 'label', false ),
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
			'<input type="checkbox" name="reason_ids[]" value="%d" />',
			(int) $item['id']
		);
	}

	/**
	 * Slug column with row actions (Delete only — there's no edit screen
	 * in this sprint; color and applies_to are edited inline via the
	 * picker / checkboxes rendered in their own columns).
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_slug( $item ): string {
		$slug = (string) $item['slug'];
		$id   = (int) $item['id'];

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'      => RecruitmentAdminPage::PAGE_SLUG,
					'tab'       => 'reasons',
					'action'    => 'delete-reason',
					'reason_id' => $id,
				),
				admin_url( 'admin.php' )
			),
			'ffc_recruitment_delete_reason_' . $id
		);

		$actions = array(
			'delete' => sprintf(
				'<a href="%s" onclick="return confirm(\'%s\');" class="submitdelete">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Delete this reason? Blocked when any classification still references it.', 'ffcertificate' ) ),
				esc_html__( 'Delete', 'ffcertificate' )
			),
		);

		return sprintf(
			'<strong><code>%s</code></strong>%s',
			esc_html( $slug ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Color column — inline color picker that PATCHes via REST on
	 * change. Mirrors the inline picker pattern from the Adjutancies
	 * list table.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_color( $item ): string {
		$id    = (int) $item['id'];
		$color = (string) ( $item['color'] ?? RecruitmentReasonRepository::DEFAULT_COLOR );
		return sprintf(
			'<input type="color" value="%s" data-ffc-reason-id="%d" class="ffc-reason-color-picker" aria-label="%s"> <code class="ffc-reason-color-hex">%s</code>',
			esc_attr( $color ),
			$id,
			esc_attr__( 'Badge color', 'ffcertificate' ),
			esc_html( $color )
		);
	}

	/**
	 * Applies-to column — small list of badges showing the preview
	 * statuses this reason is available for. Empty stored value =
	 * "applies to every preview status".
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_applies_to( $item ): string {
		$decoded = RecruitmentReasonRepository::decode_applies_to( (string) ( $item['applies_to'] ?? '' ) );
		$labels  = array(
			'denied'         => __( 'Denied', 'ffcertificate' ),
			'granted'        => __( 'Granted', 'ffcertificate' ),
			'appeal_denied'  => __( 'Appeal denied', 'ffcertificate' ),
			'appeal_granted' => __( 'Appeal granted', 'ffcertificate' ),
		);
		// When `applies_to` is empty the decoder returns the full set;
		// surface that as a single "All" pill so the "applies to all"
		// state is visually distinct from an explicit four-status pick.
		$is_all = '' === trim( (string) ( $item['applies_to'] ?? '' ) );
		if ( $is_all ) {
			return '<em>' . esc_html__( 'All preview statuses', 'ffcertificate' ) . '</em>';
		}
		$pills = array();
		foreach ( $decoded as $key ) {
			$pills[] = '<span style="display:inline-block;padding:1px 8px;margin-right:4px;border-radius:10px;background:#f0f0f1;font-size:11px;">' . esc_html( $labels[ $key ] ?? $key ) . '</span>';
		}
		return implode( '', $pills );
	}

	/**
	 * Usage column — count of classifications referencing this reason.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_usage( $item ): string {
		$count = RecruitmentReasonRepository::count_references( (int) $item['id'] );
		return esc_html( (string) $count );
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

		$rows = self::convert_rows( RecruitmentReasonRepository::get_all() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : '';
		if ( '' !== $search ) {
			$needle = strtolower( $search );
			$rows   = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $needle ): bool {
						return false !== strpos( strtolower( (string) $row['slug'] ), $needle )
							|| false !== strpos( strtolower( (string) $row['label'] ), $needle );
					}
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort.
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( (string) $_REQUEST['orderby'] ) : 'created_at';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = isset( $_REQUEST['order'] ) && 'asc' === strtolower( (string) $_REQUEST['order'] ) ? 'asc' : 'desc';
		$rows  = self::sort_rows( $rows, $orderby, $order );

		$per_page     = $this->get_items_per_page( 'ffc_recruitment_reasons_per_page', self::DEFAULT_PER_PAGE );
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
	 * Honor the `bulk-delete` action — gated by the deletion-references
	 * count, mirroring the Adjutancies tab.
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
		$ids = isset( $_REQUEST['reason_ids'] ) && is_array( $_REQUEST['reason_ids'] )
			? array_map( 'absint', wp_unslash( $_REQUEST['reason_ids'] ) )
			: array();

		foreach ( $ids as $id ) {
			if ( $id <= 0 ) {
				continue;
			}
			if ( RecruitmentReasonRepository::count_references( $id ) > 0 ) {
				continue;
			}
			RecruitmentReasonRepository::delete( $id );
		}
	}

	/**
	 * Coerce repository row stdClass objects into the array shape the
	 * table speaks.
	 *
	 * @param array<int, object> $rows Repository rows.
	 * @phpstan-param list<ReasonRow> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private static function convert_rows( array $rows ): array {
		return array_map(
			static function ( $row ): array {
				return array(
					'id'         => (int) $row->id,
					'slug'       => (string) $row->slug,
					'label'      => (string) $row->label,
					'color'      => isset( $row->color ) ? (string) $row->color : RecruitmentReasonRepository::DEFAULT_COLOR,
					'applies_to' => isset( $row->applies_to ) ? (string) $row->applies_to : '',
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
		$allowed = array( 'slug', 'label', 'created_at' );
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
