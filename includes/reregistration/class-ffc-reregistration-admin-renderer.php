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
 * @since 6.7.x  Extracted from ReregistrationAdmin (#589 phase-2, Sprint E1)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Audience\AudienceReader;

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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status_filter = \FreeFormCertificate\Core\RequestInput::get_get_string( 'status' );
		if ( '' === $status_filter ) {
			$status_filter = null;
		}
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$audience_filter = isset( $_GET['audience_id'] ) ? absint( $_GET['audience_id'] ) : 0;

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

		$audiences    = AudienceReader::get_hierarchical( 'active' );
		$selected_ids = $id > 0 ? ReregistrationRepository::get_audience_ids( $id ) : array();
		$back_url     = admin_url( 'admin.php?page=' . $menu_slug );

		include FFC_PLUGIN_DIR . 'templates/admin/reregistration/form.php';
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

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status_filter = \FreeFormCertificate\Core\RequestInput::get_get_string( 'sub_status' );
		if ( '' === $status_filter ) {
			$status_filter = null;
		}
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = \FreeFormCertificate\Core\RequestInput::get_get_string( 's' );
		if ( '' === $search ) {
			$search = null;
		}

		$filters = array();
		if ( $status_filter ) {
			$filters['status'] = $status_filter;
		}
		if ( $search ) {
			$filters['search'] = $search;
		}

		$submissions = ReregistrationSubmissionReader::get_by_reregistration( $id, $filters );
		$stats       = ReregistrationSubmissionReader::get_statistics( $id );
		$back_url    = admin_url( 'admin.php?page=' . $menu_slug );
		$export_url  = wp_nonce_url(
			admin_url( 'admin.php?page=' . $menu_slug . '&action=export_csv&id=' . $id ),
			'export_reregistration_' . $id
		);

		include FFC_PLUGIN_DIR . 'templates/admin/reregistration/submissions.php';
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
		$reject_url  = wp_nonce_url(
			admin_url( 'admin.php?page=' . $menu_slug . '&action=reject&sub_id=' . $sub->id . '&id=' . $rereg_id ),
			'reject_submission_' . $sub->id
		);
		$draft_url   = wp_nonce_url(
			admin_url( 'admin.php?page=' . $menu_slug . '&action=return_to_draft&sub_id=' . $sub->id . '&id=' . $rereg_id ),
			'return_to_draft_submission_' . $sub->id
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

		// Statuses that can be sent back to draft for user revision.
		$can_return_to_draft = in_array( $sub->status, array( 'submitted', 'approved', 'rejected' ), true );

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

	/**
	 * Render dual-column audience transfer list.
	 *
	 * @param array<int, mixed> $audiences    Hierarchical audience tree.
	 * @param array<int>        $selected_ids Currently selected audience IDs.
	 * @phpstan-param list<AudienceRow> $audiences
	 * @return void
	 */
	public static function render_audience_transfer_list( array $audiences, array $selected_ids ): void {
		// Flatten hierarchy for data attributes.
		$flat = array();
		foreach ( $audiences as $parent ) {
			$children_ids = array();
			if ( ! empty( $parent->children ) ) {
				foreach ( $parent->children as $child ) {
					$children_ids[] = (int) $child->id;
				}
			}
			$flat[] = array(
				'id'       => (int) $parent->id,
				'name'     => $parent->name,
				'color'    => $parent->color ?? '#ccc',
				'parent'   => 0,
				'children' => $children_ids,
			);
			if ( ! empty( $parent->children ) ) {
				foreach ( $parent->children as $child ) {
					$flat[] = array(
						'id'       => (int) $child->id,
						'name'     => $child->name,
						'color'    => $child->color ?? '#ccc',
						'parent'   => (int) $parent->id,
						'children' => array(),
					);
				}
			}
		}
		include FFC_PLUGIN_DIR . 'templates/admin/reregistration/transfer-list.php';
	}
}
