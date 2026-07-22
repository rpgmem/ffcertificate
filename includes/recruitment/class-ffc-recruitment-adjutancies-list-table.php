<?php
/**
 * Recruitment Adjutancies List Table.
 *
 * `WP_List_Table` subclass that powers the Adjutancies tab. Extends
 * {@see AbstractRecruitmentListTable} for the shared fetch-all / sort /
 * paginate / bulk-delete mechanics; this class supplies only the columns,
 * row shape and domain hooks. Bulk delete is gated per §14 — the
 * repository's `delete()` rejects when the adjutancy is referenced by any
 * notice_adjutancy or classification row.
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
 * Adjutancies list table.
 *
 * @phpstan-import-type AdjutancyRow from RecruitmentAdjutancyReader
 */
class RecruitmentAdjutanciesListTable extends AbstractRecruitmentListTable {

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
			'color'      => __( 'Badge color', 'ffcertificate' ),
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

		$delete_consequences = wp_json_encode(
			array(
				__( 'The adjutancy record will be permanently removed.', 'ffcertificate' ),
				__( 'Delete is blocked if any notice still references this adjutancy.', 'ffcertificate' ),
				__( 'This cannot be undone.', 'ffcertificate' ),
			)
		);
		$actions             = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'ffcertificate' ) ),
			'delete' => $this->delete_row_action_link(
				$delete_url,
				__( 'Delete this adjutancy?', 'ffcertificate' ),
				__( 'You are about to permanently delete this adjutancy.', 'ffcertificate' ),
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
	 * Color column — inline color picker that PATCHes the row via the
	 * REST endpoint on change.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_color( $item ): string {
		$id    = (int) $item['id'];
		$color = (string) ( $item['color'] ?? RecruitmentAdjutancyReader::DEFAULT_COLOR );
		return sprintf(
			'<input type="color" value="%s" data-ffc-color-endpoint="adjutancies" data-ffc-entity-id="%d" class="ffc-adjutancy-color-picker" aria-label="%s"> <code class="ffc-adjutancy-color-hex" data-ffc-color-hex>%s</code>',
			esc_attr( $color ),
			$id,
			esc_attr__( 'Badge color', 'ffcertificate' ),
			esc_html( $color )
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

	// ------------------------------------------------------------------
	// AbstractRecruitmentListTable hooks
	// ------------------------------------------------------------------

	/**
	 * {@inheritDoc}
	 */
	protected function singular(): string {
		return 'adjutancy';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function plural(): string {
		return 'adjutancies';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function ids_request_key(): string {
		return 'adjutancy_ids';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function per_page_option(): string {
		return 'ffc_recruitment_adjutancies_per_page';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return list<string>
	 */
	protected function search_fields(): array {
		return array( 'slug', 'name' );
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
		RecruitmentDeleteService::delete_adjutancy( $id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function fetch_rows(): array {
		return self::convert_rows( RecruitmentAdjutancyReader::get_all() );
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
					'color'      => isset( $row->color ) ? (string) $row->color : RecruitmentAdjutancyReader::DEFAULT_COLOR,
					'created_at' => (string) $row->created_at,
				);
			},
			$rows
		);
	}
}
