<?php
/**
 * Reregistration Admin
 *
 * Provides the admin interface for managing reregistration campaigns:
 * - List of campaigns with filters
 * - Create/edit campaign form
 * - View submissions per campaign (approve/reject/remind)
 *
 * Action handlers are delegated to focused classes:
 * - ReregistrationSubmissionActions (approve, reject, return, bulk)
 * - ReregistrationCsvExporter (CSV export)
 * - ReregistrationCustomFieldsPage (custom fields submenu)
 *
 * @package FreeFormCertificate\Reregistration
 * @since 4.11.0
 * @since 4.12.13  Extracted action handlers and custom fields page
 * @since 4.12.14  Extracted AJAX callbacks and submission details renderer
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Audience\AudienceRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reregistration Admin.
 *
 * @phpstan-import-type ReregistrationRow from ReregistrationRepository
 * @phpstan-import-type ReregistrationSubmissionRow from ReregistrationSubmissionRepository
 * @phpstan-import-type CustomFieldRow from CustomFieldRepository
 * @phpstan-import-type AudienceRow from \FreeFormCertificate\Audience\AudienceRepository
 */
class ReregistrationAdmin {

	/**
	 * Menu slug.
	 */
	public const MENU_SLUG = 'ffc-reregistration';

	/**
	 * Required capability.
	 */
	private const CAPABILITY = 'ffc_manage_reregistration';

	/**
	 * AJAX handler (lazily created in init()).
	 *
	 * @var ReregistrationAjaxHandler|null
	 */
	private ?ReregistrationAjaxHandler $ajax_handler = null;

	/**
	 * Initialize admin hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->ajax_handler = new ReregistrationAjaxHandler();

		add_action( 'admin_menu', array( $this, 'add_menu' ), 30 );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_ffc_generate_ficha', array( $this->ajax_handler, 'ajax_generate_ficha' ) );
		add_action( 'wp_ajax_ffc_rereg_count_members', array( $this->ajax_handler, 'ajax_count_members' ) );
		add_action( 'wp_ajax_ffc_view_submission_details', array( $this->ajax_handler, 'ajax_view_submission_details' ) );
	}

	/**
	 * Register admin menu pages.
	 *
	 * Creates a top-level "Reregistration" menu with submenus:
	 * - Campaigns (default landing page)
	 * - Custom Fields (per-audience field management)
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Reregistration', 'ffcertificate' ),
			__( 'Reregistration', 'ffcertificate' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-update-alt',
			// Float to keep the FFC block contiguous (26.1 → 26.2 → 26.3);
			// see Audience admin for rationale.
			26.2
		);

		// Rename auto-generated first submenu from "Reregistration" to "Campaigns".
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Campaigns', 'ffcertificate' ),
			__( 'Campaigns', 'ffcertificate' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);

		// Custom Fields submenu.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Custom Fields', 'ffcertificate' ),
			__( 'Custom Fields', 'ffcertificate' ),
			'manage_options',
			'ffc-custom-fields',
			array( ReregistrationCustomFieldsPage::class, 'render' )
		);
	}

	/**
	 * Enqueue admin assets for reregistration pages.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::MENU_SLUG ) === false && strpos( $hook, 'ffc-custom-fields' ) === false ) {
			return;
		}

		$s = \FreeFormCertificate\Core\Utils::asset_suffix();

		// Make sure ffc-common.css is registered + available so the
		// dependency below resolves even when this page isn't matched
		// by AdminAssetsManager (post_type=empty + $_GET['page']
		// already starts with ffc-, but defensive).
		if ( function_exists( 'wp_style_is' ) && ! wp_style_is( 'ffc-common', 'registered' ) ) {
			wp_register_style(
				'ffc-common',
				FFC_PLUGIN_URL . "assets/css/ffc-common{$s}.css",
				array(),
				FFC_VERSION
			);
		}

		wp_enqueue_style(
			'ffc-reregistration-admin',
			FFC_PLUGIN_URL . "assets/css/ffc-reregistration-admin{$s}.css",
			array( 'ffc-common' ),
			FFC_VERSION
		);

		wp_enqueue_script(
			'ffc-reregistration-admin',
			FFC_PLUGIN_URL . "assets/js/ffc-reregistration-admin{$s}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);

		wp_localize_script(
			'ffc-reregistration-admin',
			'ffcReregistrationAdmin',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'adminNonce'       => wp_create_nonce( 'ffc_reregistration_nonce' ),
				'fichaNonce'       => wp_create_nonce( 'ffc_generate_ficha' ),
				'viewDetailsNonce' => wp_create_nonce( 'ffc_view_submission_details' ),
				'strings'          => array(
					'confirmDelete'        => __( 'Are you sure you want to delete this reregistration? This will also delete all submissions.', 'ffcertificate' ),
					'confirmApprove'       => __( 'Approve selected submissions?', 'ffcertificate' ),
					'confirmReturnToDraft' => __( 'Return this submission to draft? The user will be able to edit and resubmit.', 'ffcertificate' ),
					'generatingPdf'        => __( 'Generating PDF...', 'ffcertificate' ),
					'errorGenerating'      => __( 'Error generating ficha.', 'ffcertificate' ),
					'ficha'                => __( 'Record', 'ffcertificate' ),
					'affectedUsers'        => __( 'Affected users:', 'ffcertificate' ),
					'loadingDetails'       => __( 'Loading…', 'ffcertificate' ),
					'errorLoadingDetails'  => __( 'Failed to load submission details.', 'ffcertificate' ),
				),
			)
		);

		// Enqueue PDF libraries on submissions view.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = \FreeFormCertificate\Core\Utils::get_get_string( 'view' );
		if ( 'submissions' === $view ) {
			wp_enqueue_script( 'html2canvas', FFC_PLUGIN_URL . 'libs/js/html2canvas.min.js', array(), FFC_HTML2CANVAS_VERSION, true );
			wp_enqueue_script( 'jspdf', FFC_PLUGIN_URL . 'libs/js/jspdf.umd.min.js', array(), FFC_JSPDF_VERSION, true );
			wp_enqueue_script( 'ffc-pdf-generator', FFC_PLUGIN_URL . 'assets/js/ffc-pdf-generator.min.js', array( 'html2canvas', 'jspdf' ), FFC_VERSION, true );
		}
	}

	/**
	 * Render the current page view.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ffcertificate' ) );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = \FreeFormCertificate\Core\Utils::get_get_string( 'view', 'list' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		echo '<div class="wrap">';

		switch ( $view ) {
			case 'new':
			case 'edit':
				$this->render_form( $id );
				break;
			case 'submissions':
				$this->render_submissions( $id );
				break;
			default:
				$this->render_list();
		}

		echo '</div>';
	}

	// ─────────────────────────────────────────────.
	// LIST VIEW.
	// ─────────────────────────────────────────────.

	/**
	 * Render reregistration campaigns list.
	 *
	 * @return void
	 */
	private function render_list(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status_filter = \FreeFormCertificate\Core\Utils::get_get_string( 'status' );
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
		$audiences = AudienceRepository::get_hierarchical();
		$new_url   = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&view=new' );

		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Reregistration', 'ffcertificate' ); ?></h1>
		<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'ffcertificate' ); ?></a>
		<hr class="wp-header-end">

		<?php settings_errors( 'ffc_reregistration' ); ?>

		<!-- Filters -->
		<div class="tablenav top">
			<form method="get" class="ffc-rereg-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<select name="status">
					<option value=""><?php esc_html_e( 'All Statuses', 'ffcertificate' ); ?></option>
					<?php foreach ( ReregistrationRepository::STATUSES as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status_filter, $s ); ?>>
							<?php echo esc_html( ReregistrationRepository::get_status_label( $s ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<select name="audience_id">
					<option value=""><?php esc_html_e( 'All Audiences', 'ffcertificate' ); ?></option>
					<?php $this->render_audience_options( $audiences, $audience_filter ); ?>
				</select>
				<?php submit_button( __( 'Filter', 'ffcertificate' ), '', '', false ); ?>
			</form>
		</div>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th class="column-title"><?php esc_html_e( 'Title', 'ffcertificate' ); ?></th>
					<th class="column-audience"><?php esc_html_e( 'Audience', 'ffcertificate' ); ?></th>
					<th class="column-status"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th>
					<th class="column-period"><?php esc_html_e( 'Period', 'ffcertificate' ); ?></th>
					<th class="column-submissions"><?php esc_html_e( 'Submissions', 'ffcertificate' ); ?></th>
					<th class="column-auto"><?php esc_html_e( 'Auto-approve', 'ffcertificate' ); ?></th>
					<th class="column-actions"><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $items ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No reregistrations found.', 'ffcertificate' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $items as $item ) : ?>
						<?php $this->render_list_row( $item ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render a single list row.
	 *
	 * @param object $item Reregistration object.
	 * @phpstan-param ReregistrationRow $item
	 * @return void
	 */
	private function render_list_row( object $item ): void {
		$edit_url   = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&view=edit&id=' . $item->id );
		$subs_url   = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&view=submissions&id=' . $item->id );
		$delete_url = wp_nonce_url(
			admin_url( 'admin.php?page=' . self::MENU_SLUG . '&action=delete&id=' . $item->id ),
			'delete_reregistration_' . $item->id
		);

		$stats     = ReregistrationSubmissionRepository::get_statistics( (int) $item->id );
		$start_ts  = strtotime( $item->start_date );
		$end_ts    = strtotime( $item->end_date );
		$start     = \FreeFormCertificate\Core\DateFormatter::format_date( false === $start_ts ? null : $start_ts );
		$end       = \FreeFormCertificate\Core\DateFormatter::format_date( false === $end_ts ? null : $end_ts );
		$audiences = ReregistrationRepository::get_audiences( (int) $item->id );

		?>
		<tr>
			<td class="column-title">
				<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $item->title ); ?></a></strong>
			</td>
			<td class="column-audience">
				<?php if ( empty( $audiences ) ) : ?>
					—
				<?php else : ?>
					<?php foreach ( $audiences as $aud ) : ?>
						<span class="ffc-audience-badge">
							<span class="ffc-color-dot" style="background:<?php echo esc_attr( $aud->color ); ?>"></span>
							<?php echo esc_html( $aud->name ); ?>
						</span>
					<?php endforeach; ?>
				<?php endif; ?>
			</td>
			<td class="column-status">
				<span class="ffc-status-badge ffc-status-<?php echo esc_attr( $item->status ); ?>">
					<?php echo esc_html( ReregistrationRepository::get_status_label( $item->status ) ); ?>
				</span>
			</td>
			<td class="column-period"><?php echo esc_html( $start . ' — ' . $end ); ?></td>
			<td class="column-submissions">
				<a href="<?php echo esc_url( $subs_url ); ?>">
					<?php
					printf(
						/* translators: 1: approved count 2: total count */
						esc_html__( '%1$d / %2$d', 'ffcertificate' ),
						absint( $stats['approved'] ),
						absint( $stats['total'] )
					);
					?>
				</a>
			</td>
			<td class="column-auto">
				<?php echo $item->auto_approve ? '<span class="dashicons dashicons-yes-alt ffc-rereg-yes"></span>' : '<span class="dashicons dashicons-minus ffc-rereg-muted"></span>'; ?>
			</td>
			<td class="column-actions">
				<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'ffcertificate' ); ?></a> |
				<a href="<?php echo esc_url( $subs_url ); ?>"><?php esc_html_e( 'Submissions', 'ffcertificate' ); ?></a> |
				<a href="<?php echo esc_url( $delete_url ); ?>" class="delete-link"
					onclick="return confirm(ffcReregistrationAdmin?.strings?.confirmDelete || 'Delete?');">
					<?php esc_html_e( 'Delete', 'ffcertificate' ); ?>
				</a>
			</td>
		</tr>
		<?php
	}

	// ─────────────────────────────────────────────.
	// FORM VIEW (Create / Edit)
	// ─────────────────────────────────────────────.

	/**
	 * Render create/edit form.
	 *
	 * @param int $id Reregistration ID (0 for new).
	 * @return void
	 */
	private function render_form( int $id ): void {
		$item  = null;
		$title = __( 'New Reregistration', 'ffcertificate' );

		if ( $id > 0 ) {
			$item = ReregistrationRepository::get_by_id( $id );
			if ( ! $item ) {
				wp_die( esc_html__( 'Reregistration not found.', 'ffcertificate' ) );
			}
			$title = __( 'Edit Reregistration', 'ffcertificate' );
		}

		$audiences    = AudienceRepository::get_hierarchical( 'active' );
		$selected_ids = $id > 0 ? ReregistrationRepository::get_audience_ids( $id ) : array();
		$back_url     = admin_url( 'admin.php?page=' . self::MENU_SLUG );

		?>
		<h1><?php echo esc_html( $title ); ?></h1>
		<a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to Reregistrations', 'ffcertificate' ); ?></a>

		<?php settings_errors( 'ffc_reregistration' ); ?>

		<form method="post" action="" class="ffc-form">
			<?php wp_nonce_field( 'save_reregistration', 'ffc_reregistration_nonce' ); ?>
			<input type="hidden" name="reregistration_id" value="<?php echo esc_attr( (string) $id ); ?>">
			<input type="hidden" name="ffc_action" value="save_reregistration">

			<table class="form-table" role="presentation"><tbody>
				<tr>
					<th scope="row"><label for="rereg_title"><?php esc_html_e( 'Title', 'ffcertificate' ); ?> <span class="required">*</span></label></th>
					<td><input type="text" name="rereg_title" id="rereg_title" class="regular-text" value="<?php echo esc_attr( $item->title ?? '' ); ?>" required></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Audiences', 'ffcertificate' ); ?> <span class="required">*</span></th>
					<td>
						<?php $this->render_audience_transfer_list( $audiences, $selected_ids ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rereg_start"><?php esc_html_e( 'Start Date', 'ffcertificate' ); ?> <span class="required">*</span></label></th>
					<td><input type="datetime-local" name="rereg_start_date" id="rereg_start" value="<?php echo esc_attr( $item ? gmdate( 'Y-m-d\TH:i', (int) strtotime( $item->start_date ) ) : '' ); ?>" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="rereg_end"><?php esc_html_e( 'End Date', 'ffcertificate' ); ?> <span class="required">*</span></label></th>
					<td><input type="datetime-local" name="rereg_end_date" id="rereg_end" value="<?php echo esc_attr( $item ? gmdate( 'Y-m-d\TH:i', (int) strtotime( $item->end_date ) ) : '' ); ?>" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="rereg_status"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></label></th>
					<td>
						<select name="rereg_status" id="rereg_status">
							<?php foreach ( ReregistrationRepository::STATUSES as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $item->status ?? 'draft', $s ); ?>>
									<?php echo esc_html( ReregistrationRepository::get_status_label( $s ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Approval', 'ffcertificate' ); ?></th>
					<td>
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'rereg_auto_approve',
								'checked' => ! empty( $item->auto_approve ),
								'label'   => __( 'Auto-approve submissions (no manual review needed)', 'ffcertificate' ),
							)
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Email Notifications', 'ffcertificate' ); ?></th>
					<td>
						<?php
						// Plain <div> wrapper rather than <fieldset> — WP admin's
						// `.form-table td fieldset label { display: inline-block }`
						// rule was overriding `.ffc-toggle { display: inline-flex }`
						// and rendering the toggle track over the label text.
						?>
						<div class="ffc-rereg-email-toggles">
							<p>
								<?php
								\FreeFormCertificate\Admin\AdminUI::render_toggle(
									array(
										'name'    => 'rereg_email_invitation',
										'checked' => ! empty( $item->email_invitation_enabled ),
										'label'   => __( 'Send invitation email when activated', 'ffcertificate' ),
									)
								);
								?>
							</p>
							<p>
								<?php
								\FreeFormCertificate\Admin\AdminUI::render_toggle(
									array(
										'name'    => 'rereg_email_reminder',
										'checked' => ! empty( $item->email_reminder_enabled ),
										'label'   => __( 'Send reminder email before deadline', 'ffcertificate' ),
									)
								);
								?>
							</p>
							<p>
								<?php
								\FreeFormCertificate\Admin\AdminUI::render_toggle(
									array(
										'name'    => 'rereg_email_confirmation',
										'checked' => ! empty( $item->email_confirmation_enabled ),
										'label'   => __( 'Send confirmation email after submission', 'ffcertificate' ),
									)
								);
								?>
							</p>
							<p class="description"><?php esc_html_e( 'All email notifications are disabled by default.', 'ffcertificate' ); ?></p>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rereg_reminder_days"><?php esc_html_e( 'Reminder Days', 'ffcertificate' ); ?></label></th>
					<td>
						<input type="number" name="rereg_reminder_days" id="rereg_reminder_days" value="<?php echo esc_attr( $item->reminder_days ?? '7' ); ?>" min="1" max="30" class="small-text">
						<p class="description"><?php esc_html_e( 'Send reminder this many days before the end date.', 'ffcertificate' ); ?></p>
					</td>
				</tr>
			</tbody></table>

			<p class="description" id="ffc-affected-users">
				<?php
				if ( $id > 0 ) {
					$affected = ReregistrationRepository::get_affected_user_ids_for_reregistration( $id );
					printf(
						'<strong>%s</strong> %s',
						esc_html__( 'Affected users:', 'ffcertificate' ),
						esc_html( (string) count( $affected ) )
					);
				}
				?>
			</p>

			<?php submit_button( $id > 0 ? __( 'Update Reregistration', 'ffcertificate' ) : __( 'Create Reregistration', 'ffcertificate' ) ); ?>
		</form>
		<?php
	}

	// ─────────────────────────────────────────────.
	// SUBMISSIONS VIEW.
	// ─────────────────────────────────────────────.

	/**
	 * Render submissions list for a reregistration.
	 *
	 * @param int $id Reregistration ID.
	 * @return void
	 */
	private function render_submissions( int $id ): void {
		$rereg = ReregistrationRepository::get_by_id( $id );
		if ( ! $rereg ) {
			wp_die( esc_html__( 'Reregistration not found.', 'ffcertificate' ) );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status_filter = \FreeFormCertificate\Core\Utils::get_get_string( 'sub_status' );
		if ( '' === $status_filter ) {
			$status_filter = null;
		}
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = \FreeFormCertificate\Core\Utils::get_get_string( 's' );
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

		$submissions = ReregistrationSubmissionRepository::get_by_reregistration( $id, $filters );
		$stats       = ReregistrationSubmissionRepository::get_statistics( $id );
		$back_url    = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$export_url  = wp_nonce_url(
			admin_url( 'admin.php?page=' . self::MENU_SLUG . '&action=export_csv&id=' . $id ),
			'export_reregistration_' . $id
		);

		?>
		<h1>
			<?php
			/* translators: %s: reregistration title */
			echo esc_html( sprintf( __( 'Submissions: %s', 'ffcertificate' ), $rereg->title ) );
			?>
		</h1>
		<a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to Reregistrations', 'ffcertificate' ); ?></a>

		<?php settings_errors( 'ffc_reregistration' ); ?>

		<!-- Stats summary -->
		<div class="ffc-rereg-stats">
			<?php foreach ( $stats as $status => $count ) : ?>
				<?php if ( 'total' !== $status ) : ?>
					<span class="ffc-stat-item">
						<span class="ffc-status-badge ffc-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ReregistrationSubmissionRepository::get_status_label( $status ) ); ?></span>
						<strong><?php echo esc_html( (string) $count ); ?></strong>
					</span>
				<?php endif; ?>
			<?php endforeach; ?>
			<span class="ffc-stat-item">
				<?php esc_html_e( 'Total:', 'ffcertificate' ); ?> <strong><?php echo esc_html( (string) $stats['total'] ); ?></strong>
			</span>
		</div>

		<!-- Filters & actions -->
		<div class="tablenav top">
			<form method="get" class="ffc-rereg-filters ffc-rereg-inline">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<input type="hidden" name="view" value="submissions">
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
				<select name="sub_status">
					<option value=""><?php esc_html_e( 'All Statuses', 'ffcertificate' ); ?></option>
					<?php foreach ( ReregistrationSubmissionRepository::STATUSES as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status_filter, $s ); ?>><?php echo esc_html( ReregistrationSubmissionRepository::get_status_label( $s ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Search name or email...', 'ffcertificate' ); ?>">
				<?php submit_button( __( 'Filter', 'ffcertificate' ), '', '', false ); ?>
			</form>
			<a href="<?php echo esc_url( $export_url ); ?>" class="button ffc-rereg-ml-10">
				<?php esc_html_e( 'Export CSV', 'ffcertificate' ); ?>
			</a>
		</div>

		<!-- Bulk actions form -->
		<form method="post" id="ffc-submissions-form">
			<?php wp_nonce_field( 'bulk_submissions_' . $id, 'ffc_bulk_nonce' ); ?>
			<input type="hidden" name="ffc_action" value="bulk_submissions">
			<input type="hidden" name="reregistration_id" value="<?php echo esc_attr( (string) $id ); ?>">

			<div class="tablenav top">
				<select name="bulk_action">
					<option value=""><?php esc_html_e( 'Bulk Actions', 'ffcertificate' ); ?></option>
					<option value="approve"><?php esc_html_e( 'Approve', 'ffcertificate' ); ?></option>
					<option value="return_to_draft"><?php esc_html_e( 'Return to Draft', 'ffcertificate' ); ?></option>
					<option value="send_reminder"><?php esc_html_e( 'Send Reminder', 'ffcertificate' ); ?></option>
				</select>
				<?php submit_button( __( 'Apply', 'ffcertificate' ), 'action', '', false ); ?>
			</div>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" id="cb-select-all"></td>
						<th><?php esc_html_e( 'User', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Email', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Submitted', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Reviewed', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $submissions ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No submissions found.', 'ffcertificate' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $submissions as $sub ) : ?>
							<?php $this->render_submission_row( $sub, $id ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</form>

		<!-- Submission details modal -->
		<div id="ffc-submission-details-modal" class="ffc-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="ffc-submission-details-title">
			<div class="ffc-modal-backdrop"></div>
			<div class="ffc-modal-content">
				<div class="ffc-modal-header">
					<h2 id="ffc-submission-details-title"><?php esc_html_e( 'Submission Details', 'ffcertificate' ); ?></h2>
					<button type="button" class="ffc-modal-close" aria-label="<?php esc_attr_e( 'Close', 'ffcertificate' ); ?>">&times;</button>
				</div>
				<div class="ffc-modal-body">
					<p class="ffc-modal-loading"><?php esc_html_e( 'Loading…', 'ffcertificate' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single submission row.
	 *
	 * @param object $sub         Submission object.
	 * @param int    $rereg_id    Reregistration ID.
	 * @phpstan-param ReregistrationSubmissionRow $sub
	 * @return void
	 */
	private function render_submission_row( object $sub, int $rereg_id ): void {
		$approve_url = wp_nonce_url(
			admin_url( 'admin.php?page=' . self::MENU_SLUG . '&action=approve&sub_id=' . $sub->id . '&id=' . $rereg_id ),
			'approve_submission_' . $sub->id
		);
		$reject_url  = wp_nonce_url(
			admin_url( 'admin.php?page=' . self::MENU_SLUG . '&action=reject&sub_id=' . $sub->id . '&id=' . $rereg_id ),
			'reject_submission_' . $sub->id
		);
		$draft_url   = wp_nonce_url(
			admin_url( 'admin.php?page=' . self::MENU_SLUG . '&action=return_to_draft&sub_id=' . $sub->id . '&id=' . $rereg_id ),
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

		?>
		<tr>
			<th class="check-column">
				<input type="checkbox" name="submission_ids[]" value="<?php echo esc_attr( $sub->id ); ?>">
			</th>
			<td><?php echo esc_html( $sub->user_name ?? '—' ); ?></td>
			<td><?php echo esc_html( $sub->user_email ?? '—' ); ?></td>
			<td>
				<span class="ffc-status-badge ffc-status-<?php echo esc_attr( $sub->status ); ?>">
					<?php echo esc_html( ReregistrationSubmissionRepository::get_status_label( $sub->status ) ); ?>
				</span>
			</td>
			<td><?php echo esc_html( $submitted ); ?></td>
			<td><?php echo esc_html( $reviewed ); ?></td>
			<td>
				<?php if ( 'submitted' === $sub->status ) : ?>
					<a href="<?php echo esc_url( $approve_url ); ?>" class="button button-small"><?php esc_html_e( 'Approve', 'ffcertificate' ); ?></a>
					<a href="<?php echo esc_url( $reject_url ); ?>" class="button button-small button-link-delete"><?php esc_html_e( 'Reject', 'ffcertificate' ); ?></a>
				<?php elseif ( 'pending' === $sub->status ) : ?>
					<span class="description"><?php esc_html_e( 'Awaiting user', 'ffcertificate' ); ?></span>
				<?php elseif ( ! empty( $sub->notes ) ) : ?>
					<span class="description" title="<?php echo esc_attr( $sub->notes ); ?>"><?php esc_html_e( 'See notes', 'ffcertificate' ); ?></span>
				<?php else : ?>
					—
				<?php endif; ?>
				<?php if ( $can_return_to_draft ) : ?>
					<a href="<?php echo esc_url( $draft_url ); ?>" class="button button-small ffc-return-draft-btn" title="<?php esc_attr_e( 'Return to user for revision', 'ffcertificate' ); ?>">
						<span class="dashicons dashicons-edit ffc-rereg-icon"></span>
						<?php esc_html_e( 'Return to Draft', 'ffcertificate' ); ?>
					</a>
				<?php endif; ?>
				<button type="button" class="button button-small ffc-view-details-btn" data-submission-id="<?php echo esc_attr( $sub->id ); ?>">
					<span class="dashicons dashicons-visibility ffc-rereg-icon"></span>
					<?php esc_html_e( 'View Details', 'ffcertificate' ); ?>
				</button>
				<?php if ( in_array( $sub->status, array( 'submitted', 'approved' ), true ) ) : ?>
					<button type="button" class="button button-small ffc-ficha-btn" data-submission-id="<?php echo esc_attr( $sub->id ); ?>">
						<span class="dashicons dashicons-media-document ffc-rereg-icon"></span>
						<?php esc_html_e( 'Ficha', 'ffcertificate' ); ?>
					</button>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	// ─────────────────────────────────────────────.
	// ACTION HANDLERS.
	// ─────────────────────────────────────────────.

	/**
	 * Handle admin actions (save, delete, approve, reject, bulk, export).
	 *
	 * Delegates submission workflow actions to ReregistrationSubmissionActions
	 * and CSV export to ReregistrationCsvExporter.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = \FreeFormCertificate\Core\Utils::get_get_string( 'page' );
		if ( self::MENU_SLUG !== $page ) {
			return;
		}

		// Show redirect messages.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['message'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$msg      = \FreeFormCertificate\Core\Utils::get_get_string( 'message' );
			$messages = array(
				'created'                => __( 'Reregistration created successfully.', 'ffcertificate' ),
				'updated'                => __( 'Reregistration updated successfully.', 'ffcertificate' ),
				'deleted'                => __( 'Reregistration deleted successfully.', 'ffcertificate' ),
				'approved'               => __( 'Submission approved.', 'ffcertificate' ),
				'rejected'               => __( 'Submission rejected.', 'ffcertificate' ),
				'returned_to_draft'      => __( 'Submission returned to draft for revision.', 'ffcertificate' ),
				'bulk_approved'          => __( 'Selected submissions approved.', 'ffcertificate' ),
				'bulk_returned_to_draft' => __( 'Selected submissions returned to draft.', 'ffcertificate' ),
				'reminders_sent'         => __( 'Reminder emails sent.', 'ffcertificate' ),
			);
			if ( isset( $messages[ $msg ] ) ) {
				add_settings_error( 'ffc_reregistration', 'ffc_message', $messages[ $msg ], 'success' );
			}
		}

		// Campaign CRUD.
		$this->handle_save();
		$this->handle_delete();

		// Submission workflow (delegated).
		ReregistrationSubmissionActions::handle_approve();
		ReregistrationSubmissionActions::handle_reject();
		ReregistrationSubmissionActions::handle_return_to_draft();
		ReregistrationSubmissionActions::handle_bulk();

		// CSV export (delegated).
		ReregistrationCsvExporter::handle_export();
	}

	/**
	 * Handle save (create/update) reregistration.
	 *
	 * @return void
	 */
	private function handle_save(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified immediately below.
		if ( ! isset( $_POST['ffc_action'] ) || 'save_reregistration' !== $_POST['ffc_action'] ) {
			return;
		}
		if ( ! wp_verify_nonce( \FreeFormCertificate\Core\Utils::get_post_string( 'ffc_reregistration_nonce' ), 'save_reregistration' ) ) {
			return;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$id          = isset( $_POST['reregistration_id'] ) ? absint( $_POST['reregistration_id'] ) : 0;
		$prev_status = null;

		if ( $id > 0 ) {
			$existing    = ReregistrationRepository::get_by_id( $id );
			$prev_status = $existing ? $existing->status : null;
		}

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above (line 707).
		$data = array(
			'title'                      => \FreeFormCertificate\Core\Utils::get_post_string( 'rereg_title' ),
			'start_date'                 => \FreeFormCertificate\Core\Utils::get_post_string( 'rereg_start_date' ),
			'end_date'                   => \FreeFormCertificate\Core\Utils::get_post_string( 'rereg_end_date' ),
			'auto_approve'               => ! empty( $_POST['rereg_auto_approve'] ) ? 1 : 0,
			'email_invitation_enabled'   => ! empty( $_POST['rereg_email_invitation'] ) ? 1 : 0,
			'email_reminder_enabled'     => ! empty( $_POST['rereg_email_reminder'] ) ? 1 : 0,
			'email_confirmation_enabled' => ! empty( $_POST['rereg_email_confirmation'] ) ? 1 : 0,
			'reminder_days'              => isset( $_POST['rereg_reminder_days'] ) ? absint( $_POST['rereg_reminder_days'] ) : 7,
			'status'                     => \FreeFormCertificate\Core\Utils::get_post_string( 'rereg_status', 'draft' ),
		);

		// Collect audience IDs from transfer list hidden inputs.
		$audience_ids = array();
		if ( isset( $_POST['rereg_audience_ids'] ) && is_array( $_POST['rereg_audience_ids'] ) ) {
			$audience_ids = array_map( 'absint', $_POST['rereg_audience_ids'] );
		}
        // phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $id > 0 ) {
			ReregistrationRepository::update( $id, $data );
			ReregistrationRepository::set_audience_ids( $id, $audience_ids );

			// If transitioning to active, create submissions for members and send invitations.
			if ( 'active' === $data['status'] && 'active' !== $prev_status ) {
				ReregistrationSubmissionRepository::create_for_audience_members( $id, $audience_ids );
				ReregistrationEmailHandler::send_invitations( $id );
			}

			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&view=edit&id=' . $id . '&message=updated' ) );
			exit;
		} else {
			$new_id = ReregistrationRepository::create( $data );
			if ( $new_id ) {
				ReregistrationRepository::set_audience_ids( $new_id, $audience_ids );

				// If creating as active, also create submissions and send invitations.
				if ( 'active' === $data['status'] ) {
					ReregistrationSubmissionRepository::create_for_audience_members( $new_id, $audience_ids );
					ReregistrationEmailHandler::send_invitations( $new_id );
				}

				wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&view=edit&id=' . $new_id . '&message=created' ) );
				exit;
			}
		}
	}

	/**
	 * Handle delete reregistration.
	 *
	 * @return void
	 */
	private function handle_delete(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['action'] ) || 'delete' !== $_GET['action'] || ! isset( $_GET['id'] ) ) {
			return;
		}

		$id = absint( $_GET['id'] );
		if ( ! wp_verify_nonce( \FreeFormCertificate\Core\Utils::get_get_string( '_wpnonce' ), 'delete_reregistration_' . $id ) ) {
			return;
		}

		ReregistrationRepository::delete( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&message=deleted' ) );
		exit;
	}

	/**
	 * Render audience <option> elements with hierarchy (parent → &mdash; child).
	 *
	 * Used by the list-view audience filter dropdown.
	 *
	 * @param array<int, mixed> $audiences Audience tree (objects with optional ->children).
	 * @param int|string        $selected  Currently selected audience ID.
	 * @return void
	 */
	private function render_audience_options( array $audiences, $selected = '' ): void {
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
	private function render_audience_transfer_list( array $audiences, array $selected_ids ): void {
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
		?>
		<?php
			$flat_json     = wp_json_encode( $flat );
			$selected_json = wp_json_encode( array_values( $selected_ids ) );
		?>
		<div class="ffc-transfer-list" data-audiences="<?php echo esc_attr( $flat_json ? $flat_json : '' ); ?>" data-selected="<?php echo esc_attr( $selected_json ? $selected_json : '' ); ?>">
			<div class="ffc-transfer-col ffc-transfer-available">
				<div class="ffc-transfer-header"><?php esc_html_e( 'Available', 'ffcertificate' ); ?></div>
				<input type="text" class="ffc-transfer-search" placeholder="<?php esc_attr_e( 'Filter...', 'ffcertificate' ); ?>">
				<div class="ffc-transfer-items"></div>
			</div>
			<div class="ffc-transfer-actions">
				<button type="button" class="button ffc-transfer-add" title="<?php esc_attr_e( 'Add selected', 'ffcertificate' ); ?>">&rsaquo;</button>
				<button type="button" class="button ffc-transfer-add-all" title="<?php esc_attr_e( 'Add all', 'ffcertificate' ); ?>">&raquo;</button>
				<button type="button" class="button ffc-transfer-remove" title="<?php esc_attr_e( 'Remove selected', 'ffcertificate' ); ?>">&lsaquo;</button>
				<button type="button" class="button ffc-transfer-remove-all" title="<?php esc_attr_e( 'Remove all', 'ffcertificate' ); ?>">&laquo;</button>
			</div>
			<div class="ffc-transfer-col ffc-transfer-selected">
				<div class="ffc-transfer-header"><?php esc_html_e( 'Selected', 'ffcertificate' ); ?></div>
				<div class="ffc-transfer-items"></div>
			</div>
			<div class="ffc-transfer-hidden-inputs"></div>
		</div>
		<p class="description ffc-transfer-member-count"></p>
		<?php
	}

	/**
	 * Return the AJAX handler, creating it on first access.
	 *
	 * Allows the thin delegators below to work when callers (notably tests)
	 * invoke AJAX methods directly on the facade without going through init().
	 *
	 * @return ReregistrationAjaxHandler
	 */
	private function get_ajax_handler(): ReregistrationAjaxHandler {
		if ( null === $this->ajax_handler ) {
			$this->ajax_handler = new ReregistrationAjaxHandler();
		}
		return $this->ajax_handler;
	}

	/**
	 * AJAX: Generate ficha PDF data for a submission.
	 *
	 * Thin delegator to ReregistrationAjaxHandler; preserves the facade's
	 * public surface so existing direct callers keep working.
	 *
	 * @return void
	 */
	public function ajax_generate_ficha(): void {
		$this->get_ajax_handler()->ajax_generate_ficha();
	}

	/**
	 * AJAX: Return HTML with the full submission detail grouped by fieldset.
	 *
	 * Thin delegator to ReregistrationAjaxHandler.
	 *
	 * @return void
	 */
	public function ajax_view_submission_details(): void {
		$this->get_ajax_handler()->ajax_view_submission_details();
	}

	/**
	 * AJAX: Count members for a set of audience IDs.
	 *
	 * Thin delegator to ReregistrationAjaxHandler.
	 *
	 * @return void
	 */
	public function ajax_count_members(): void {
		$this->get_ajax_handler()->ajax_count_members();
	}
}
