<?php
/**
 * Audience Admin Calendar
 *
 * @package FreeFormCertificate\Audience
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\Utils;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles calendar admin pages (list, form, actions).
 *
 * @phpstan-import-type SchedulePermissionRow from AudienceScheduleRepository
 */
class AudienceAdminCalendar {

	/**
	 * Menu slug prefix.
	 *
	 * @var string
	 */
	private string $menu_slug;

	/**
	 * The Environments screen, rendered as this page's second view (#1761).
	 *
	 * @var AudienceAdminEnvironment|null
	 */
	private ?AudienceAdminEnvironment $environment;

	/**
	 * Constructor.
	 *
	 * @param string                        $menu_slug   The menu slug prefix.
	 * @param AudienceAdminEnvironment|null $environment The Environments view, when the page hosts it.
	 */
	public function __construct( string $menu_slug, ?AudienceAdminEnvironment $environment = null ) {
		$this->menu_slug   = $menu_slug;
		$this->environment = $environment;
	}

	/**
	 * Whether this request shows the Environments view.
	 *
	 * @return bool
	 */
	private function is_environments_view(): bool {
		return null !== $this->environment && $this->environment->is_own_request();
	}

	/**
	 * Print the switch between the page's two views, Calendars and
	 * Environments (#1761).
	 *
	 * WordPress's own view links (`ul.subsubsub`, the idiom the submissions
	 * list prints through `get_views()`), so the switch reads as the screen's
	 * views and the dark theme already paints it.
	 *
	 * @param string $menu_slug The menu slug prefix.
	 * @param string $current   `list` for Calendars, AudienceAdminEnvironment::VIEW for Environments.
	 * @return void
	 */
	public static function render_view_switch( string $menu_slug, string $current ): void {
		$views = array(
			'list'                         => array(
				'label' => __( 'Calendars', 'ffcertificate' ),
				'url'   => admin_url( 'admin.php?page=' . $menu_slug . '-calendars' ),
			),
			AudienceAdminEnvironment::VIEW => array(
				'label' => AudienceScheduleRepository::get_environment_label(),
				'url'   => AudienceAdminEnvironment::view_url( $menu_slug ),
			),
		);
		$last  = array_key_last( $views );
		?>
		<ul class="subsubsub">
			<?php foreach ( $views as $key => $view ) : ?>
				<li>
					<a href="<?php echo esc_url( $view['url'] ); ?>"<?php echo $key === $current ? ' class="current" aria-current="page"' : ''; ?>><?php echo esc_html( $view['label'] ); ?></a><?php echo $key !== $last ? ' |' : ''; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<div class="clear"></div>
		<?php
	}

	/**
	 * Render calendars page
	 *
	 * @return void
	 */
	public function render_page(): void {
		$action = RequestInput::get_get_string( 'action', 'list' );
		$id     = RequestInput::get_get_int( 'id' );

		// The add/edit form is a screen of boxed sections (`.ffc-boxed`); the
		// list keeps the plain list-table screen.
		$boxed = in_array( $action, array( 'new', 'edit' ), true );
		?>
		<div class="wrap ffc-admin-page ffc-page-scheduling-calendars <?php echo $boxed ? 'ffc-boxed' : ''; ?>">
			<?php
			if ( null !== $this->environment && $this->is_environments_view() ) {
				// The second view of this page (#1761); its add/edit form is
				// boxed by the same `action` rule as the calendar's.
				$this->environment->render_view();
			} else {
				switch ( $action ) {
					case 'new':
					case 'edit':
						$this->render_form( $id );
						break;
					default:
						$this->render_list();
				}
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render calendars list
	 *
	 * @return void
	 */
	private function render_list(): void {
		$schedules = AudienceScheduleRepository::get_all( array( 'orderby' => 'name' ) );
		$add_url   = admin_url( 'admin.php?page=' . $this->menu_slug . '-calendars&action=new' );
		// Row actions render only for the cap handle_actions() checks (#1769).
		$can_manage = \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' );
		$can_delete = \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_delete_audiences' );

		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Calendars', 'ffcertificate' ); ?></h1>
		<?php if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' ) ) : ?>
		<a href="<?php echo esc_url( $add_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'ffcertificate' ); ?></a>
		<?php endif; ?>
		<hr class="wp-header-end">

		<?php settings_errors( 'ffc_audience' ); ?>

		<?php
		if ( null !== $this->environment ) {
			self::render_view_switch( $this->menu_slug, 'list' );
		}
		?>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col" class="column-name"><?php esc_html_e( 'Name', 'ffcertificate' ); ?></th>
					<th scope="col" class="column-visibility"><?php esc_html_e( 'Visibility', 'ffcertificate' ); ?></th>
					<th scope="col" class="column-environments"><?php echo esc_html( AudienceScheduleRepository::get_environment_label() ); ?></th>
					<th scope="col" class="column-status"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th>
					<th scope="col" class="column-actions"><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $schedules ) ) : ?>
					<tr>
						<td colspan="5"><?php esc_html_e( 'No calendars found.', 'ffcertificate' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $schedules as $schedule ) : ?>
						<?php
						$env_count = AudienceEnvironmentRepository::count( array( 'schedule_id' => $schedule->id ) );
						$edit_url  = admin_url( 'admin.php?page=' . $this->menu_slug . '-calendars&action=edit&id=' . $schedule->id );
						$is_active = ( 'active' === $schedule->status );

						$deactivate_url = '';
						$delete_url     = '';
						if ( $is_active ) {
							$deactivate_url = wp_nonce_url(
								admin_url( 'admin.php?page=' . $this->menu_slug . '-calendars&action=deactivate&id=' . $schedule->id ),
								'deactivate_schedule_' . $schedule->id
							);
						} else {
							$delete_url = wp_nonce_url(
								admin_url( 'admin.php?page=' . $this->menu_slug . '-calendars&action=delete&id=' . $schedule->id ),
								'delete_schedule_' . $schedule->id
							);
						}
						?>
						<tr>
							<td class="column-name">
								<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $schedule->name ); ?></a></strong>
								<?php if ( $schedule->description ) : ?>
									<p class="description"><?php echo esc_html( wp_trim_words( $schedule->description, 15 ) ); ?></p>
								<?php endif; ?>
							</td>
							<td class="column-visibility">
								<?php echo 'public' === $schedule->visibility ? esc_html__( 'Public', 'ffcertificate' ) : esc_html__( 'Private', 'ffcertificate' ); ?>
							</td>
							<td class="column-environments">
								<?php // The count opens this calendar's environments (#1761). ?>
								<a href="<?php echo esc_url( AudienceAdminEnvironment::view_url( $this->menu_slug, '&schedule_id=' . $schedule->id ) ); ?>"><?php echo esc_html( (string) $env_count ); ?></a>
							</td>
							<td class="column-status">
								<span class="ffc-audience-status ffc-audience-status-<?php echo esc_attr( $schedule->status ); ?>">
									<?php echo $is_active ? esc_html__( 'Active', 'ffcertificate' ) : esc_html__( 'Inactive', 'ffcertificate' ); ?>
								</span>
							</td>
							<td class="column-actions">
								<?php if ( $can_manage ) : ?>
									<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'ffcertificate' ); ?></a>
								<?php endif; ?>
								<?php if ( $is_active && $can_manage ) : ?>
									| <a href="<?php echo esc_url( $deactivate_url ); ?>" class="ffc-delete-link" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to deactivate this calendar?', 'ffcertificate' ); ?>');">
										<?php esc_html_e( 'Deactivate', 'ffcertificate' ); ?>
									</a>
								<?php elseif ( ! $is_active && $can_delete ) : ?>
									<?php echo $can_manage ? '|' : ''; ?>
									<a href="<?php echo esc_url( $delete_url ); ?>" class="ffc-delete-link" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to permanently delete this calendar?', 'ffcertificate' ); ?>');">
										<?php esc_html_e( 'Delete', 'ffcertificate' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<!-- Styles in ffc-audience-admin.css -->
		<?php
	}

	/**
	 * Render calendar form
	 *
	 * @param int $id Schedule ID (0 for new).
	 * @return void
	 */
	private function render_form( int $id ): void {
		$schedule   = null;
		$page_title = __( 'Add New Calendar', 'ffcertificate' );

		if ( $id > 0 ) {
			$schedule = AudienceScheduleRepository::get_by_id( $id );
			if ( ! $schedule ) {
				wp_die( esc_html__( 'Calendar not found.', 'ffcertificate' ) );
			}
			$page_title = __( 'Edit Calendar', 'ffcertificate' );
		}

		$back_url = admin_url( 'admin.php?page=' . $this->menu_slug . '-calendars' );
		// 3-state (#1777): the screen opens on `ffc_view_audiences`, and every
		// write on it — the save, the permission AJAX, the holidays — is gated
		// on `ffc_manage_audiences`. Without it the screen renders read-only.
		$can_manage = \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' );

		?>
		<h1><?php echo esc_html( $page_title ); ?></h1>
		<a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to Calendars', 'ffcertificate' ); ?></a>

		<?php settings_errors( 'ffc_audience' ); ?>
		<?php if ( ! $can_manage ) : ?>
			<p class="description"><em><?php esc_html_e( 'Read-only — you can view this page but do not have permission to change it.', 'ffcertificate' ); ?></em></p>
		<?php endif; ?>

		<div class="card">
		<h2 class="ffc-icon-calendar"><?php esc_html_e( 'Calendar', 'ffcertificate' ); ?></h2>
		<form method="post" action="" class="ffc-form">
			<?php wp_nonce_field( 'save_schedule', 'ffc_schedule_nonce' ); ?>
			<input type="hidden" name="schedule_id" value="<?php echo esc_attr( (string) $id ); ?>">
			<input type="hidden" name="ffc_action" value="save_schedule">

			<?php if ( ! $can_manage ) : ?>
				<?php // A disabled fieldset disables every control inside it, the autosave toggles included. ?>
				<fieldset disabled class="ffc-readonly-lock">
			<?php endif; ?>
			<table class="form-table" role="presentation"><tbody>
				<?php if ( $id > 0 ) : ?>
				<tr>
					<th scope="row">
						<label><?php esc_html_e( 'Calendar ID', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<code><?php echo esc_html( (string) $id ); ?></code>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label><?php esc_html_e( 'Shortcode', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<code>[ffc_audience schedule_id="<?php echo esc_attr( (string) $id ); ?>"]</code>
						<p class="description">
							<?php esc_html_e( 'Use this shortcode to display the calendar on any page or post.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>
				<?php endif; ?>
				<tr>
					<th scope="row">
						<label for="schedule_name"><?php esc_html_e( 'Name', 'ffcertificate' ); ?> <span class="ffc-required">*</span></label>
					</th>
					<td>
						<input type="text" name="schedule_name" id="schedule_name" class="regular-text"
								value="<?php echo esc_attr( $schedule->name ?? '' ); ?>" required>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="schedule_description"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<textarea name="schedule_description" id="schedule_description" rows="3" class="large-text"><?php echo esc_textarea( $schedule->description ?? '' ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="schedule_environment_label"><?php esc_html_e( 'Environment Label', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<input type="text" name="schedule_environment_label" id="schedule_environment_label" class="regular-text"
								value="<?php echo esc_attr( $schedule->environment_label ?? '' ); ?>"
								placeholder="<?php esc_attr_e( 'Environments', 'ffcertificate' ); ?>">
						<p class="description">
							<?php esc_html_e( 'Custom label for the environments of this calendar (e.g. "Rooms", "Categories", "Services"). Leave empty to use the default "Environments".', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="schedule_visibility"><?php esc_html_e( 'Visibility', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<input type="hidden" name="schedule_visibility" value="private">
						<?php
						// New calendars start public (#1757); a stored value is left as it is.
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'schedule_visibility',
								'id'      => 'schedule_visibility',
								'value'   => 'public',
								'checked' => 'public' === (string) ( $schedule->visibility ?? 'public' ),
								'label'   => __( 'Public', 'ffcertificate' ),
								'data'    => \FreeFormCertificate\Core\RecordAutosave::attrs( 'audience_schedule', $id, 'visibility' ),
							)
						);
						?>
						<p class="description">
							<?php esc_html_e( 'Public: visible to everyone. Private: only visible to logged-in users who belong to an audience group. Scheduling is always restricted to authorized members.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="schedule_future_days"><?php esc_html_e( 'Future Days Limit', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<input type="number" name="schedule_future_days" id="schedule_future_days" class="small-text"
								value="<?php echo esc_attr( $schedule->future_days_limit ?? '' ); ?>" min="1" max="365">
						<p class="description">
							<?php esc_html_e( 'Maximum days in advance that non-admin users can book. Leave empty for no limit.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Notifications', 'ffcertificate' ); ?></th>
					<td>
						<?php \FreeFormCertificate\Core\EmailDisabledNotice::render(); ?>
						<div>
							<p>
								<?php
								\FreeFormCertificate\Admin\AdminUI::render_toggle(
									array(
										'name'    => 'schedule_notify_booking',
										'checked' => 1 === (int) ( $schedule->notify_on_booking ?? 1 ),
										'label'   => __( 'Send email on new booking', 'ffcertificate' ),
										'data'    => \FreeFormCertificate\Core\RecordAutosave::attrs( 'audience_schedule', $id, 'notify_on_booking' ),
									)
								);
								?>
							</p>
							<p>
								<?php
								\FreeFormCertificate\Admin\AdminUI::render_toggle(
									array(
										'name'    => 'schedule_notify_cancel',
										'checked' => 1 === (int) ( $schedule->notify_on_cancellation ?? 1 ),
										'label'   => __( 'Send email on cancellation', 'ffcertificate' ),
										'data'    => \FreeFormCertificate\Core\RecordAutosave::attrs( 'audience_schedule', $id, 'notify_on_cancellation' ),
									)
								);
								?>
							</p>
							<p>
								<?php
								\FreeFormCertificate\Admin\AdminUI::render_toggle(
									array(
										'name'    => 'schedule_include_ics',
										'checked' => 1 === (int) ( $schedule->include_ics ?? 0 ),
										'label'   => __( 'Include .ics calendar file in emails', 'ffcertificate' ),
										'data'    => \FreeFormCertificate\Core\RecordAutosave::attrs( 'audience_schedule', $id, 'include_ics' ),
									)
								);
								?>
							</p>
							<p>
								<?php
								\FreeFormCertificate\Admin\AdminUI::render_toggle(
									array(
										'name'    => 'schedule_notify_admin_booking',
										'checked' => 1 === (int) ( $schedule->notify_admin_on_booking ?? 0 ),
										'label'   => __( 'Also notify admin on new booking', 'ffcertificate' ),
										'data'    => \FreeFormCertificate\Core\RecordAutosave::attrs( 'audience_schedule', $id, 'notify_admin_on_booking' ),
									)
								);
								?>
							</p>
							<p>
								<?php
								\FreeFormCertificate\Admin\AdminUI::render_toggle(
									array(
										'name'    => 'schedule_notify_admin_cancel',
										'checked' => 1 === (int) ( $schedule->notify_admin_on_cancellation ?? 0 ),
										'label'   => __( 'Also notify admin on cancellation', 'ffcertificate' ),
										'data'    => \FreeFormCertificate\Core\RecordAutosave::attrs( 'audience_schedule', $id, 'notify_admin_on_cancellation' ),
									)
								);
								?>
							</p>
							<p>
								<label for="schedule_admin_emails"><?php esc_html_e( 'Admin recipient(s)', 'ffcertificate' ); ?></label><br>
								<input type="text" id="schedule_admin_emails" name="schedule_admin_emails" class="regular-text" value="<?php echo esc_attr( $schedule->admin_notification_emails ?? '' ); ?>">
								<span class="description"><?php esc_html_e( 'Comma-separated email addresses for the admin notifications above. Leave empty to use the site admin email.', 'ffcertificate' ); ?></span>
							</p>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Event List', 'ffcertificate' ); ?></th>
					<td>
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'schedule_show_event_list',
								'checked' => 1 === (int) ( $schedule->show_event_list ?? 0 ),
								'label'   => __( 'Show event list alongside the calendar', 'ffcertificate' ),
								'data'    => \FreeFormCertificate\Core\RecordAutosave::attrs( 'audience_schedule', $id, 'show_event_list' ),
							)
						);
						?>
						<br>
						<label for="schedule_event_list_position"><?php esc_html_e( 'Position', 'ffcertificate' ); ?></label>
						<select name="schedule_event_list_position" id="schedule_event_list_position">
							<option value="side" <?php selected( $schedule->event_list_position ?? 'side', 'side' ); ?>>
								<?php esc_html_e( 'Side (responsive)', 'ffcertificate' ); ?>
							</option>
							<option value="below" <?php selected( $schedule->event_list_position ?? '', 'below' ); ?>>
								<?php esc_html_e( 'Below', 'ffcertificate' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Side: appears next to the calendar on desktop, moves below on mobile. Below: always below the calendar.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="schedule_booking_label_singular"><?php esc_html_e( 'Booking Badge Label', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<div class="ffc-aud-flex-center">
							<input type="text" name="schedule_booking_label_singular" id="schedule_booking_label_singular" class="regular-text ffc-aud-maxw-160"
									value="<?php echo esc_attr( $schedule->booking_label_singular ?? '' ); ?>"
									placeholder="<?php echo esc_attr__( 'booking', 'ffcertificate' ); ?>">
							<span>/</span>
							<input type="text" name="schedule_booking_label_plural" id="schedule_booking_label_plural" class="regular-text ffc-aud-maxw-160"
									value="<?php echo esc_attr( $schedule->booking_label_plural ?? '' ); ?>"
									placeholder="<?php echo esc_attr__( 'bookings', 'ffcertificate' ); ?>">
						</div>
						<p class="description">
							<?php esc_html_e( 'Custom singular/plural label for the booking count shown in calendar day cells (e.g. "session" / "sessions"). Leave empty to use default.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="schedule_audience_badge_format"><?php esc_html_e( 'Audience Badge Format', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<select name="schedule_audience_badge_format" id="schedule_audience_badge_format">
							<option value="name" <?php selected( $schedule->audience_badge_format ?? 'name', 'name' ); ?>>
								<?php esc_html_e( 'Name only', 'ffcertificate' ); ?>
							</option>
							<option value="parent_name" <?php selected( $schedule->audience_badge_format ?? '', 'parent_name' ); ?>>
								<?php esc_html_e( 'Parent: Name', 'ffcertificate' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'How audience badges are displayed. "Name only" shows just the audience name. "Parent: Name" prefixes with the parent category when available.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Isolated Calendar', 'ffcertificate' ); ?></th>
					<td>
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'schedule_is_isolated',
								'checked' => 1 === (int) ( $schedule->is_isolated ?? 0 ),
								'label'   => __( 'Ignore conflicts from other calendars', 'ffcertificate' ),
								'data'    => \FreeFormCertificate\Core\RecordAutosave::attrs( 'audience_schedule', $id, 'is_isolated' ),
							)
						);
						?>
						<p class="description">
							<?php esc_html_e( 'When enabled, audience same-day and user overlap checks only consider bookings within this calendar. Environment conflicts are always per-environment regardless of this setting.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="schedule_status"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<input type="hidden" name="schedule_status" value="inactive">
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'schedule_status',
								'id'      => 'schedule_status',
								'value'   => 'active',
								'checked' => 'active' === (string) ( $schedule->status ?? 'active' ),
								'label'   => __( 'Active', 'ffcertificate' ),
								'data'    => \FreeFormCertificate\Core\RecordAutosave::attrs( 'audience_schedule', $id, 'status' ),
							)
						);
						?>
					</td>
				</tr>
			</tbody></table>
			<?php if ( ! $can_manage ) : ?>
				</fieldset>
			<?php else : ?>
				<?php submit_button( $id > 0 ? __( 'Update Calendar', 'ffcertificate' ) : __( 'Create Calendar', 'ffcertificate' ) ); ?>
			<?php endif; ?>
		</form>
		</div>

		<?php if ( $id > 0 ) : ?>
			<div class="card">
			<h2 class="ffc-icon-users"><?php esc_html_e( 'User Access & Permissions', 'ffcertificate' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Manage which users have access to this calendar and what they can do. For private calendars, only listed users can see and book. For public calendars, these permissions control who can book and cancel.', 'ffcertificate' ); ?>
			</p>

			<?php if ( $can_manage ) : ?>
			<div class="ffc-user-access-add ffc-aud-panel">
				<div class="ffc-aud-flex-end">
					<div class="ffc-aud-flex-250">
						<label for="ffc-user-search"><strong><?php esc_html_e( 'Add User', 'ffcertificate' ); ?></strong></label><br>
						<input type="text" id="ffc-user-search" class="regular-text ffc-aud-w-full" placeholder="<?php esc_attr_e( 'Search by name or email...', 'ffcertificate' ); ?>" autocomplete="off">
						<div id="ffc-user-search-results" class="ffc-aud-user-dropdown"></div>
					</div>
					<div>
						<button type="button" id="ffc-add-user-btn" class="button button-secondary" disabled>
							<?php esc_html_e( 'Add User', 'ffcertificate' ); ?>
						</button>
					</div>
				</div>
				<input type="hidden" id="ffc-selected-user-id" value="">
			</div>
			<?php endif; ?>

			<?php
			$permissions = AudienceScheduleRepository::get_all_permissions( $id );
			if ( ! $can_manage ) :
				self::render_permissions_read_only( $permissions );
			else :
				?>
			<table class="wp-list-table widefat fixed striped" id="ffc-permissions-table" data-schedule-id="<?php echo esc_attr( (string) $id ); ?>">
				<thead>
					<tr>
						<th class="ffc-aud-w-30pct"><?php esc_html_e( 'User', 'ffcertificate' ); ?></th>
						<th class="ffc-aud-w-15pct"><?php esc_html_e( 'Can Book', 'ffcertificate' ); ?></th>
						<th class="ffc-aud-w-15pct"><?php esc_html_e( 'Cancel Others', 'ffcertificate' ); ?></th>
						<th class="ffc-aud-w-15pct"><?php esc_html_e( 'Override Conflicts', 'ffcertificate' ); ?></th>
						<th class="ffc-aud-w-10pct"><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $permissions ) ) : ?>
						<tr id="ffc-no-permissions-row">
							<td colspan="5"><em><?php esc_html_e( 'No users have been granted access yet.', 'ffcertificate' ); ?></em></td>
						</tr>
					<?php else : ?>
						<?php
						foreach ( $permissions as $perm ) :
							$user = get_userdata( (int) $perm->user_id );
							if ( ! $user ) {
								continue;
							}
							?>
							<?php self::render_permission_row( $perm, $user ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<?php // Calendar permissions logic in ffc-audience-admin.js. ?>
			</div>

			<div class="card">
			<h2 class="ffc-icon-lock"><?php esc_html_e( 'Holidays / Closed Dates', 'ffcertificate' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Add specific dates when the calendar will be closed (holidays, maintenance, etc.).', 'ffcertificate' ); ?></p>

			<?php if ( $can_manage ) : ?>
			<form method="post" action="" class="ffc-holiday-form ffc-aud-panel">
				<?php wp_nonce_field( 'add_holiday', 'ffc_holiday_nonce' ); ?>
				<input type="hidden" name="schedule_id" value="<?php echo esc_attr( (string) $id ); ?>">
				<input type="hidden" name="ffc_action" value="add_holiday">

				<div class="ffc-aud-flex-end">
					<div>
						<label for="holiday_date"><strong><?php esc_html_e( 'Date', 'ffcertificate' ); ?></strong></label><br>
						<input type="date" name="holiday_date" id="holiday_date" required class="ffc-aud-w-180">
					</div>
					<div class="ffc-aud-flex-200">
						<label for="holiday_description"><strong><?php esc_html_e( 'Description (optional)', 'ffcertificate' ); ?></strong></label><br>
						<input type="text" name="holiday_description" id="holiday_description" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., Christmas Day', 'ffcertificate' ); ?>">
					</div>
					<div>
						<?php submit_button( __( 'Add Holiday', 'ffcertificate' ), 'secondary', 'submit', false ); ?>
					</div>
				</div>
			</form>
			<?php endif; ?>

			<?php
			$holidays = AudienceEnvironmentRepository::get_holidays( $id );
			if ( ! empty( $holidays ) ) :
				?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th class="ffc-aud-w-150"><?php esc_html_e( 'Date', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
						<?php if ( $can_manage ) : ?>
						<th class="ffc-aud-w-100"><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $holidays as $holiday ) : ?>
						<tr>
							<td><?php echo esc_html( \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( (string) $holiday->holiday_date ) ); ?></td>
							<td><?php echo esc_html( $holiday->description ? $holiday->description : '—' ); ?></td>
							<?php if ( $can_manage ) : ?>
							<td>
								<?php
								$delete_url = wp_nonce_url(
									admin_url( 'admin.php?page=' . $this->menu_slug . '-calendars&action=edit&id=' . $id . '&delete_holiday=' . $holiday->id ),
									'delete_holiday_' . $holiday->id
								);
								?>
								<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small" onclick="return confirm('<?php esc_attr_e( 'Delete this holiday?', 'ffcertificate' ); ?>');">
									<?php esc_html_e( 'Delete', 'ffcertificate' ); ?>
								</a>
							</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php else : ?>
				<p><em><?php esc_html_e( 'No holidays defined yet.', 'ffcertificate' ); ?></em></p>
			<?php endif; ?>
			</div>
		<?php endif; ?>

		<!-- Styles in ffc-audience-admin.css -->
		<?php
	}

	/**
	 * Print one row of the "User Access & Permissions" table.
	 *
	 * The single renderer for both the initial page render and the row the
	 * `ffc_audience_add_user_permission` AJAX handler returns, so a row added
	 * without a reload draws the same toggles (#1771).
	 *
	 * @param object $perm Permission row: `user_id`, `can_book`, `can_cancel_others`, `can_override_conflicts`.
	 * @param object $user The WordPress user (`display_name`, `user_email`).
	 * @phpstan-param object{user_id: int|numeric-string, can_book: int|numeric-string, can_cancel_others: int|numeric-string, can_override_conflicts: int|numeric-string} $perm
	 * @phpstan-param object{display_name: string, user_email: string} $user
	 * @return void
	 */
	public static function render_permission_row( object $perm, object $user ): void {
		$user_id = (string) $perm->user_id;
		$columns = array(
			'can_book'               => $perm->can_book,
			'can_cancel_others'      => $perm->can_cancel_others,
			'can_override_conflicts' => $perm->can_override_conflicts,
		);
		?>
		<tr data-user-id="<?php echo esc_attr( $user_id ); ?>">
			<td>
				<strong><?php echo esc_html( $user->display_name ); ?></strong>
				<br><span class="description"><?php echo esc_html( $user->user_email ); ?></span>
			</td>
			<?php foreach ( $columns as $column => $granted ) : ?>
				<td>
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'        => 'ffc_perm_' . $user_id . '_' . $column,
							'input_class' => 'ffc-perm-toggle',
							'data'        => array( 'perm' => $column ),
							'checked'     => ! empty( $granted ),
						)
					);
					?>
				</td>
			<?php endforeach; ?>
			<td>
				<button type="button" class="button button-small button-link-delete ffc-remove-user-btn"><?php esc_html_e( 'Remove', 'ffcertificate' ); ?></button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Handle calendar actions (save, delete)
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' ) ) {
			return;
		}

		// The coordinator calls this on every `ffc-scheduling*` screen, and
		// `?message=` / `action=delete` mean something else on the siblings
		// (#1771): act only on the calendars screen. The Environments view
		// shares this page (#1761), and its `message`, `action=deactivate`
		// and `action=delete` are AudienceAdminEnvironment's.
		if ( RequestInput::get_get_string( 'page' ) !== $this->menu_slug . '-calendars' || $this->is_environments_view() ) {
			return;
		}

		// Show feedback for redirect-based actions.
		if ( isset( $_GET['message'] ) ) {
			$msg      = RequestInput::get_get_string( 'message' );
			$messages = array(
				'created'         => __( 'Calendar created successfully.', 'ffcertificate' ),
				'deactivated'     => __( 'Calendar deactivated successfully.', 'ffcertificate' ),
				'deleted'         => __( 'Calendar deleted successfully.', 'ffcertificate' ),
				'holiday_deleted' => __( 'Holiday deleted successfully.', 'ffcertificate' ),
			);
			if ( isset( $messages[ $msg ] ) ) {
				add_settings_error( 'ffc_audience', 'ffc_message', $messages[ $msg ], 'success' );
			}
		}

		// Handle save.
		if ( isset( $_POST['ffc_action'] ) && 'save_schedule' === $_POST['ffc_action'] ) {
			if ( ! wp_verify_nonce( RequestInput::get_post_string( 'ffc_schedule_nonce' ), 'save_schedule' ) ) {
				return;
			}

			$id   = isset( $_POST['schedule_id'] ) ? absint( $_POST['schedule_id'] ) : 0;
			$data = array(
				'name'                         => RequestInput::get_post_string( 'schedule_name' ),
				'description'                  => isset( $_POST['schedule_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['schedule_description'] ) ) : '',
				'environment_label'            => isset( $_POST['schedule_environment_label'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_environment_label'] ) ) : null,
				'visibility'                   => 'public' === RequestInput::get_post_string( 'schedule_visibility', 'private' ) ? 'public' : 'private',
				'future_days_limit'            => isset( $_POST['schedule_future_days'] ) && '' !== $_POST['schedule_future_days'] ? absint( $_POST['schedule_future_days'] ) : null,
				'notify_on_booking'            => isset( $_POST['schedule_notify_booking'] ) ? 1 : 0,
				'notify_on_cancellation'       => isset( $_POST['schedule_notify_cancel'] ) ? 1 : 0,
				'notify_admin_on_booking'      => isset( $_POST['schedule_notify_admin_booking'] ) ? 1 : 0,
				'notify_admin_on_cancellation' => isset( $_POST['schedule_notify_admin_cancel'] ) ? 1 : 0,
				'admin_notification_emails'    => isset( $_POST['schedule_admin_emails'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_admin_emails'] ) ) : null,
				'include_ics'                  => isset( $_POST['schedule_include_ics'] ) ? 1 : 0,
				'show_event_list'              => isset( $_POST['schedule_show_event_list'] ) ? 1 : 0,
				'event_list_position'          => isset( $_POST['schedule_event_list_position'] ) && in_array( $_POST['schedule_event_list_position'], array( 'side', 'below' ), true )
					? sanitize_text_field( wp_unslash( $_POST['schedule_event_list_position'] ) )
					: 'side',
				'audience_badge_format'        => isset( $_POST['schedule_audience_badge_format'] ) && in_array( $_POST['schedule_audience_badge_format'], array( 'name', 'parent_name' ), true )
					? sanitize_text_field( wp_unslash( $_POST['schedule_audience_badge_format'] ) )
					: 'name',
				'booking_label_singular'       => ! empty( $_POST['schedule_booking_label_singular'] )
					? sanitize_text_field( wp_unslash( $_POST['schedule_booking_label_singular'] ) )
					: null,
				'booking_label_plural'         => ! empty( $_POST['schedule_booking_label_plural'] )
					? sanitize_text_field( wp_unslash( $_POST['schedule_booking_label_plural'] ) )
					: null,
				'is_isolated'                  => isset( $_POST['schedule_is_isolated'] ) ? 1 : 0,
				'status'                       => RequestInput::get_post_string( 'schedule_status', 'active' ),
			);

			if ( $id > 0 ) {
				AudienceScheduleRepository::update( $id, $data );
				add_settings_error( 'ffc_audience', 'ffc_message', __( 'Calendar updated successfully.', 'ffcertificate' ), 'success' );
			} else {
				$new_id = AudienceScheduleRepository::create( $data );
				if ( $new_id ) {
					wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_slug . '-calendars&action=edit&id=' . $new_id . '&message=created' ) );
					exit;
				}
			}
		}

		// Handle deactivate (active items get deactivated instead of deleted).
		if ( isset( $_GET['action'] ) && 'deactivate' === $_GET['action'] && isset( $_GET['id'] ) ) {
			$id = absint( $_GET['id'] );
			if ( wp_verify_nonce( RequestInput::get_get_string( '_wpnonce' ), 'deactivate_schedule_' . $id ) ) {
				AudienceScheduleRepository::update( $id, array( 'status' => 'inactive' ) );
				wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_slug . '-calendars&message=deactivated' ) );
				exit;
			}
		}

		// Handle delete (only inactive items can be permanently deleted).
		if ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] && isset( $_GET['id'] ) ) {
			if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_delete_audiences' ) ) {
				wp_die( esc_html__( 'You do not have permission to delete calendars.', 'ffcertificate' ) );
			}
			$id = absint( $_GET['id'] );
			if ( wp_verify_nonce( RequestInput::get_get_string( '_wpnonce' ), 'delete_schedule_' . $id ) ) {
				$schedule = AudienceScheduleRepository::get_by_id( $id );
				if ( $schedule && 'active' !== $schedule->status ) {
					AudienceScheduleRepository::delete( $id );
					wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_slug . '-calendars&message=deleted' ) );
					exit;
				}
			}
		}

		// Handle add holiday.
		if ( isset( $_POST['ffc_action'] ) && 'add_holiday' === $_POST['ffc_action'] ) {
			if ( ! wp_verify_nonce( RequestInput::get_post_string( 'ffc_holiday_nonce' ), 'add_holiday' ) ) {
				return;
			}

			$schedule_id  = isset( $_POST['schedule_id'] ) ? absint( $_POST['schedule_id'] ) : 0;
			$holiday_date = RequestInput::get_post_string( 'holiday_date' );
			$description  = RequestInput::get_post_string( 'holiday_description' );

			if ( $schedule_id > 0 && $holiday_date ) {
				AudienceEnvironmentRepository::add_holiday( $schedule_id, $holiday_date, $description );
				add_settings_error( 'ffc_audience', 'ffc_message', __( 'Holiday added successfully.', 'ffcertificate' ), 'success' );
			}
		}

		// Handle delete holiday.
		if ( isset( $_GET['delete_holiday'] ) && isset( $_GET['id'] ) ) {
			$holiday_id  = absint( $_GET['delete_holiday'] );
			$schedule_id = absint( $_GET['id'] );
			if ( wp_verify_nonce( RequestInput::get_get_string( '_wpnonce' ), 'delete_holiday_' . $holiday_id ) ) {
				AudienceEnvironmentRepository::remove_holiday( $holiday_id );
				wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_slug . '-calendars&action=edit&id=' . $schedule_id . '&message=holiday_deleted' ) );
				exit;
			}
		}
	}

	/**
	 * Print the "User Access & Permissions" table for a viewer without
	 * `ffc_manage_audiences` (#1777): the same grants, as disabled toggles,
	 * with no Remove column. The table carries no `ffc-permissions-table` id,
	 * so the permission script never binds to it.
	 *
	 * @param array<int, object> $permissions Rows from AudienceScheduleRepository::get_all_permissions().
	 * @phpstan-param list<SchedulePermissionRow> $permissions
	 * @return void
	 */
	private static function render_permissions_read_only( array $permissions ): void {
		$columns = array(
			'can_book'               => __( 'Can Book', 'ffcertificate' ),
			'can_cancel_others'      => __( 'Cancel Others', 'ffcertificate' ),
			'can_override_conflicts' => __( 'Override Conflicts', 'ffcertificate' ),
		);
		?>
		<table class="wp-list-table widefat fixed striped ffc-permissions-read-only">
			<thead>
				<tr>
					<th class="ffc-aud-w-30pct"><?php esc_html_e( 'User', 'ffcertificate' ); ?></th>
					<?php foreach ( $columns as $label ) : ?>
						<th class="ffc-aud-w-15pct"><?php echo esc_html( $label ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $permissions ) ) : ?>
					<tr>
						<td colspan="4"><em><?php esc_html_e( 'No users have been granted access yet.', 'ffcertificate' ); ?></em></td>
					</tr>
				<?php endif; ?>
				<?php
				foreach ( $permissions as $perm ) :
					$user = get_userdata( (int) $perm->user_id );
					if ( ! $user ) {
						continue;
					}
					$granted = array(
						'can_book'               => $perm->can_book,
						'can_cancel_others'      => $perm->can_cancel_others,
						'can_override_conflicts' => $perm->can_override_conflicts,
					);
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $user->display_name ); ?></strong>
							<br><span class="description"><?php echo esc_html( $user->user_email ); ?></span>
						</td>
						<?php foreach ( $columns as $column => $label ) : ?>
							<td>
								<?php
								\FreeFormCertificate\Admin\AdminUI::render_toggle(
									array(
										'name'       => 'ffc_perm_' . (string) $perm->user_id . '_' . $column,
										'checked'    => ! empty( $granted[ $column ] ),
										'disabled'   => true,
										'aria_label' => $label,
									)
								);
								?>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
