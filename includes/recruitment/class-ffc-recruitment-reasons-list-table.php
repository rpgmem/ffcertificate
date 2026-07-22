<?php
/**
 * Recruitment Reasons List Table.
 *
 * `WP_List_Table` subclass that powers the Reasons tab — the global
 * catalog of operator-defined labels attached to a preliminary-list
 * classification's `preview_status`. Extends {@see AbstractRecruitmentListTable}
 * for the shared fetch-all / sort / paginate / bulk-delete mechanics; this
 * class adds the read-only (`$can_edit`) rendering variations.
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
 * Reasons list table.
 *
 * @phpstan-import-type ReasonRow from RecruitmentReasonReader
 */
class RecruitmentReasonsListTable extends AbstractRecruitmentListTable {

	/**
	 * Whether the current user may edit reasons (the strict
	 * `ffc_manage_recruitment_reasons` tier — GAP I). When false the table
	 * renders read-only: no Edit/Delete row actions, no bulk-delete control,
	 * and {@see process_bulk_action()} refuses to act even on a crafted POST.
	 *
	 * @var bool
	 */
	private bool $can_edit;

	/**
	 * Constructor.
	 *
	 * @param bool $can_edit Whether the viewer holds the reasons-manage tier.
	 */
	public function __construct( bool $can_edit = true ) {
		$this->can_edit = $can_edit;
		parent::__construct();
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
	 * Bulk actions — read-only viewers (GAP I) get no destructive control.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions() {
		if ( ! $this->can_edit ) {
			return array();
		}
		return parent::get_bulk_actions();
	}

	/**
	 * Slug column with row actions (Delete only — color and applies_to are
	 * edited inline via the picker / checkboxes in their own columns).
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_slug( $item ): string {
		$slug = (string) $item['slug'];
		$id   = (int) $item['id'];

		// Read-only viewers (GAP I) see the slug as plain text — no Edit link
		// (the edit screen is manage-gated and would wp_die) and no row actions.
		if ( ! $this->can_edit ) {
			return sprintf( '<strong><code>%s</code></strong>', esc_html( $slug ) );
		}

		$edit_url   = add_query_arg(
			array(
				'page'      => RecruitmentAdminPage::PAGE_SLUG,
				'tab'       => 'reasons',
				'action'    => 'edit-reason',
				'reason_id' => $id,
			),
			admin_url( 'admin.php' )
		);
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

		$delete_consequences = wp_json_encode(
			array(
				__( 'The reason record will be permanently removed.', 'ffcertificate' ),
				__( 'Delete is blocked when any classification still references this reason.', 'ffcertificate' ),
				__( 'This cannot be undone.', 'ffcertificate' ),
			)
		);
		$actions             = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'ffcertificate' ) ),
			'delete' => $this->delete_row_action_link(
				$delete_url,
				__( 'Delete this reason?', 'ffcertificate' ),
				__( 'You are about to permanently delete this reason.', 'ffcertificate' ),
				(string) $delete_consequences
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
	 * Color column — inline color picker that PATCHes via REST on change.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_color( $item ): string {
		$id    = (int) $item['id'];
		$color = (string) ( $item['color'] ?? RecruitmentReasonReader::DEFAULT_COLOR );
		// Read-only viewers (GAP I) see a static swatch — the inline picker
		// PATCHes via the manage-gated REST route and would 403.
		if ( ! $this->can_edit ) {
			return sprintf(
				'<span class="ffc-reason-color-swatch" style="display:inline-block;width:1em;height:1em;border:1px solid #ccc;vertical-align:middle;background:%s"></span> <code>%s</code>',
				esc_attr( $color ),
				esc_html( $color )
			);
		}
		return sprintf(
			'<input type="color" value="%s" data-ffc-color-endpoint="reasons" data-ffc-entity-id="%d" class="ffc-reason-color-picker" aria-label="%s"> <code class="ffc-reason-color-hex" data-ffc-color-hex>%s</code>',
			esc_attr( $color ),
			$id,
			esc_attr__( 'Badge color', 'ffcertificate' ),
			esc_html( $color )
		);
	}

	/**
	 * Applies-to column — badges showing the preview statuses this reason is
	 * available for. Empty stored value = "applies to every preview status".
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_applies_to( $item ): string {
		$decoded = RecruitmentReasonReader::decode_applies_to( (string) ( $item['applies_to'] ?? '' ) );
		$labels  = array(
			'denied'         => __( 'Denied', 'ffcertificate' ),
			'granted'        => __( 'Granted', 'ffcertificate' ),
			'appeal_denied'  => __( 'Appeal denied', 'ffcertificate' ),
			'appeal_granted' => __( 'Appeal granted', 'ffcertificate' ),
		);
		// When `applies_to` is empty the decoder returns the full set; surface
		// that as a single "All" pill so the "applies to all" state is visually
		// distinct from an explicit four-status pick.
		$is_all = '' === trim( (string) ( $item['applies_to'] ?? '' ) );
		if ( $is_all ) {
			return '<em>' . esc_html__( 'All preview statuses', 'ffcertificate' ) . '</em>';
		}
		$pills = array();
		foreach ( $decoded as $key ) {
			$pills[] = '<span class="ffc-rec-pill">' . esc_html( $labels[ $key ] ?? $key ) . '</span>';
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
		$count = RecruitmentReasonReader::count_references( (int) $item['id'] );
		return esc_html( (string) $count );
	}

	// ------------------------------------------------------------------
	// AbstractRecruitmentListTable hooks
	// ------------------------------------------------------------------

	/**
	 * {@inheritDoc}
	 */
	protected function singular(): string {
		return 'reason';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function plural(): string {
		return 'reasons';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function ids_request_key(): string {
		return 'reason_ids';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function per_page_option(): string {
		return 'ffc_recruitment_reasons_per_page';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return list<string>
	 */
	protected function search_fields(): array {
		return array( 'slug', 'label' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function bulk_delete_capability(): string {
		return 'ffc_manage_recruitment_reasons';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Per-row guard: a reason still referenced by any classification is skipped
	 * (mirrors the repository's own delete gate).
	 */
	protected function delete_one( int $id ): void {
		if ( RecruitmentReasonReader::count_references( $id ) > 0 ) {
			return;
		}
		RecruitmentReasonWriter::delete( $id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function fetch_rows(): array {
		return self::convert_rows( RecruitmentReasonReader::get_all() );
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
					'color'      => isset( $row->color ) ? (string) $row->color : RecruitmentReasonReader::DEFAULT_COLOR,
					'applies_to' => isset( $row->applies_to ) ? (string) $row->applies_to : '',
					'created_at' => (string) $row->created_at,
				);
			},
			$rows
		);
	}
}
