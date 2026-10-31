<?php
/**
 * Reregistration Admin Renderer
 *
 * Pure view layer for the reregistration admin pages. Holds the HTML-rendering
 * methods extracted from ReregistrationAdmin (the controller). Receives the
 * menu slug and the precomputed can-edit flag as explicit parameters so it
 * never reaches back through controller state.
 *
 * @package FreeFormCertificate\Reregistration
 * @since 6.12.0  Extracted from ReregistrationAdmin (#589 phase-2, Sprint E1)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Audience\AudienceReader;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reregistration Admin Renderer.
 *
 * @phpstan-import-type ReregistrationRow from ReregistrationRepository
 * @phpstan-import-type ReregistrationSubmissionRow from ReregistrationSubmissionReader
 * @phpstan-import-type CustomFieldRow from CustomFieldReader
 * @phpstan-import-type AudienceRow from \FreeFormCertificate\Audience\AudienceReader
 */
final class ReregistrationAdminRenderer {

	/**
	 * Submissions listed per page (#2242). A campaign reaches thousands of
	 * members, and every row carries a masked e-mail and its Reveal button.
	 */
	public const SUBMISSIONS_PER_PAGE = 50;

	// ─────────────────────────────────────────────.
	// LIST VIEW.
	// ─────────────────────────────────────────────.

	/**
	 * Render reregistration campaigns list.
	 *
	 * @param string $menu_slug Admin menu slug.
	 * @param bool   $can_edit  Whether the current user can edit (manage tier).
	 * @return void
	 */
	public static function render_list( string $menu_slug, bool $can_edit ): void {
		$status_filter = \FreeFormCertificate\Core\RequestInput::get_get_string( 'status' );
		if ( '' === $status_filter ) {
			$status_filter = null;
		}
		$audience_filter = RequestInput::get_get_int( 'audience_id' );

		$filters = array();
		if ( $status_filter ) {
			$filters['status'] = $status_filter;
		}
		if ( $audience_filter ) {
			$filters['audience_id'] = $audience_filter;
		}

		$items     = ReregistrationRepository::get_all( $filters );
		$audiences = AudienceReader::get_hierarchical();
		$new_url   = admin_url( 'admin.php?page=' . $menu_slug . '&view=new' );

		include FFC_PLUGIN_DIR . 'templates/admin/reregistration/list.php';
	}

	/**
	 * Render a single list row.
	 *
	 * @param string $menu_slug Admin menu slug.
	 * @param object $item      Reregistration object.
	 * @param bool   $can_edit  Whether the current user can edit (manage tier).
	 * @phpstan-param ReregistrationRow $item
	 * @return void
	 */
	public static function render_list_row( string $menu_slug, object $item, bool $can_edit = true ): void {
		$edit_url   = admin_url( 'admin.php?page=' . $menu_slug . '&view=edit&id=' . $item->id );
		$subs_url   = admin_url( 'admin.php?page=' . $menu_slug . '&view=submissions&id=' . $item->id );
		$title_url  = $can_edit ? $edit_url : $subs_url;
		$delete_url = wp_nonce_url(
			admin_url( 'admin.php?page=' . $menu_slug . '&action=delete&id=' . $item->id ),
			'delete_reregistration_' . $item->id
		);

		$stats     = ReregistrationSubmissionReader::get_statistics( (int) $item->id );
		$start_ts  = strtotime( $item->start_date );
		$end_ts    = strtotime( $item->end_date );
		$start     = \FreeFormCertificate\Core\DateFormatter::format_date( false === $start_ts ? null : $start_ts );
		$end       = \FreeFormCertificate\Core\DateFormatter::format_date( false === $end_ts ? null : $end_ts );
		$audiences = ReregistrationRepository::get_audiences( (int) $item->id );

		include FFC_PLUGIN_DIR . 'templates/admin/reregistration/list-row.php';
	}

	// ─────────────────────────────────────────────.
	// FORM VIEW (Create / Edit)
	// ─────────────────────────────────────────────.

	/**
	 * Render create/edit form.
	 *
	 * @param string $menu_slug Admin menu slug.
	 * @param int    $id        Reregistration ID (0 for new).
	 * @return void
	 */
	public static function render_form( string $menu_slug, int $id ): void {
		$item  = null;
		$title = __( 'New Reregistration', 'ffcertificate' );

		if ( $id > 0 ) {
			$item = ReregistrationRepository::get_by_id( $id );
			if ( ! $item ) {
				wp_die( esc_html__( 'Reregistration not found.', 'ffcertificate' ) );
			}
			$title = __( 'Edit Reregistration', 'ffcertificate' );
		}

		$selected_ids = $id > 0 ? ReregistrationRepository::get_audience_ids( $id ) : array();
		$back_url     = admin_url( 'admin.php?page=' . $menu_slug );

		// The import panel offers THIS campaign's audiences and no others
		// (#1214): `ingest_job()` refuses one the campaign does not reach, so a
		// wider list would be an error the operator could only discover by
		// uploading a file first. Empty for a campaign being created, which is
		// also when there is nothing to import into.
		$import_audiences = self::audience_choices( $selected_ids );

		include FFC_PLUGIN_DIR . 'templates/admin/reregistration/form.php';
	}

	/**
	 * Name the campaign's audiences, for the import picker and the
	 * submissions filter.
	 *
	 * An id whose audience has since been deleted is dropped rather than shown
	 * with a blank label: the campaign row keeps the link, and offering a
	 * nameless option is how an operator picks the wrong one.
	 *
	 * @param array<int, int|string> $audience_ids Ids linked to the campaign.
	 * @return array<int, string> id => name, in the order given.
	 */
	private static function audience_choices( array $audience_ids ): array {
		$choices = array();

		foreach ( $audience_ids as $audience_id ) {
			$audience = AudienceReader::get_by_id( (int) $audience_id );
			if ( null === $audience ) {
				continue;
			}
			$choices[ (int) $audience_id ] = (string) $audience->name;
		}

		return $choices;
	}

	// ─────────────────────────────────────────────.
	// SUBMISSIONS VIEW.
	// ─────────────────────────────────────────────.

	/**
	 * Render submissions list for a reregistration.
	 *
	 * @param string $menu_slug Admin menu slug.
	 * @param int    $id        Reregistration ID.
	 * @param bool   $can_edit  Whether the current user can edit (manage tier).
	 * @return void
	 */
	public static function render_submissions( string $menu_slug, int $id, bool $can_edit ): void {
		$rereg = ReregistrationRepository::get_by_id( $id );
		if ( ! $rereg ) {
			wp_die( esc_html__( 'Reregistration not found.', 'ffcertificate' ) );
		}

		// One normalised filter set (#2242) feeds the list, its count, the
		// pagination links, the stat-card links and the CSV export, so none of
		// them can read the request differently.
		$filters       = ReregistrationSubmissionReader::normalize_filters(
			RequestInput::get_get_string( 'sub_status' ),
			RequestInput::get_get_string( 's' ),
			RequestInput::get_get_int( 'audience' )
		);
		$status_filter = $filters['status'];
		$search        = $filters['search'];
		$audience_id   = $filters['audience_id'];

		$total        = ReregistrationSubmissionReader::count_filtered( $id, $filters );
		$total_pages  = max( 1, (int) ceil( $total / self::SUBMISSIONS_PER_PAGE ) );
		$current_page = min( max( 1, RequestInput::get_get_int( 'paged' ) ), $total_pages );

		$submissions = ReregistrationSubmissionReader::get_by_reregistration(
			$id,
			$filters + array(
				'limit'  => self::SUBMISSIONS_PER_PAGE,
				'offset' => ( $current_page - 1 ) * self::SUBMISSIONS_PER_PAGE,
			)
		);
		$stats       = ReregistrationSubmissionReader::get_statistics( $id );
		$back_url    = admin_url( 'admin.php?page=' . $menu_slug );
		$audiences   = self::audience_choices( ReregistrationRepository::get_audience_ids( $id ) );

		// The screen's own URL with no filter; the links below add theirs.
		$base_url     = admin_url( 'admin.php?page=' . $menu_slug . '&view=submissions&id=' . $id );
		$filtered_url = add_query_arg(
			array_filter(
				array(
					'sub_status' => $status_filter,
					's'          => $search,
					'audience'   => $audience_id > 0 ? $audience_id : null,
				),
				static fn( $value ): bool => null !== $value
			),
			$base_url
		);
		$pagination   = $total_pages > 1
			? (string) paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%', $filtered_url ),
					'format'    => '',
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
					'total'     => $total_pages,
					'current'   => $current_page,
				)
			)
			: '';

		include FFC_PLUGIN_DIR . 'templates/admin/reregistration/submissions.php';
	}

	/**
	 * The count of listed submissions and the page links, for the top and the
	 * bottom of the list (#2242).
	 *
	 * @param int    $total      Submissions matching the filters.
	 * @param string $pagination `paginate_links()` output, '' for one page.
	 * @return void
	 */
	public static function render_submissions_pagination( int $total, string $pagination ): void {
		echo '<div class="tablenav-pages"><span class="displaying-num">';
		/* translators: %s: number of submissions listed. */
		echo esc_html( sprintf( _n( '%s submission', '%s submissions', $total, 'ffcertificate' ), number_format_i18n( $total ) ) );
		echo '</span>';
		if ( '' !== $pagination ) {
			echo ' <span class="pagination-links">' . wp_kses_post( $pagination ) . '</span>';
		}
		echo '</div>';
	}

	/**
	 * Render a single submission row.
	 *
	 * @param string $menu_slug Admin menu slug.
	 * @param object $sub       Submission object.
	 * @param int    $rereg_id  Reregistration ID.
	 * @param bool   $can_edit  Whether the current user can edit (manage tier).
	 * @phpstan-param ReregistrationSubmissionRow $sub
	 * @return void
	 */
	public static function render_submission_row( string $menu_slug, object $sub, int $rereg_id, bool $can_edit = true ): void {
		$approve_url = wp_nonce_url(
			admin_url( 'admin.php?page=' . $menu_slug . '&action=approve&sub_id=' . $sub->id . '&id=' . $rereg_id ),
			'approve_submission_' . $sub->id
		);

		if ( $sub->submitted_at ) {
			// `submitted_at` is unix UTC int since 6.6.0 (#249 sub-escopo b).
			$submitted_raw = \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $sub->submitted_at );
			$submitted     = $submitted_raw ? $submitted_raw : '—';
		} else {
			$submitted = '—';
		}
		if ( $sub->reviewed_at ) {
			// `reviewed_at` is unix UTC int since 6.6.0 (#249 sub-escopo d).
			$reviewed_raw = \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $sub->reviewed_at );
			$reviewed     = $reviewed_raw ? $reviewed_raw : '—';
		} else {
			$reviewed = '—';
		}

		// Statuses that can be sent back to draft for user revision. Reject and
		// Return open the review modal, which asks for the reason (#2242).
		$can_return_to_draft = in_array( $sub->status, ReregistrationSubmissionWriter::RETURNABLE_STATUSES, true );

		// PII (#1815). The email is masked for every viewer: the clear value is fetched through the audited
		// `ffc_reveal_pii` endpoint, so it never sits in the list's HTML. The
		// same policy decides whether "View Details" is drawn, because the
		// modal it opens shows the decrypted CPF / RF / RG.
		$owner_id       = isset( $sub->user_id ) ? (int) $sub->user_id : 0;
		$pii_tier       = \FreeFormCertificate\Core\PiiAccessPolicy::resolve(
			ReregistrationAjaxHandler::PII_CAPABILITY,
			$owner_id > 0 ? $owner_id : null
		);
		$can_reveal_pii = \FreeFormCertificate\Core\PiiAccessPolicy::TIER_MASKED !== $pii_tier;
		$email_masked   = \FreeFormCertificate\Core\DocumentFormatter::mask_email( (string) ( $sub->user_email ?? '' ) );
		$reveal_nonce   = $can_reveal_pii ? wp_create_nonce( 'ffc_reveal_pii_nonce' ) : '';

		// The Record button asks the endpoint's own question (#1815, #2242).
		$can_generate_record = ReregistrationAjaxHandler::can_generate_record();

		include FFC_PLUGIN_DIR . 'templates/admin/reregistration/submission-row.php';
	}

	// ─────────────────────────────────────────────.
	// AUDIENCE WIDGETS.
	// ─────────────────────────────────────────────.

	/**
	 * Render audience <option> elements with hierarchy (parent → &mdash; child).
	 *
	 * Used by the list-view audience filter dropdown.
	 *
	 * @param array<int, mixed> $audiences Audience tree (objects with optional ->children).
	 * @param int|string        $selected  Currently selected audience ID.
	 * @return void
	 */
	public static function render_audience_options( array $audiences, $selected = '' ): void {
		foreach ( $audiences as $parent ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $parent->id ),
				selected( $selected, $parent->id, false ),
				esc_html( $parent->name )
			);
			if ( ! empty( $parent->children ) ) {
				foreach ( $parent->children as $child ) {
					printf(
						'<option value="%s" %s>&mdash; %s</option>',
						esc_attr( $child->id ),
						selected( $selected, $child->id, false ),
						esc_html( $child->name )
					);
				}
			}
		}
	}
}
