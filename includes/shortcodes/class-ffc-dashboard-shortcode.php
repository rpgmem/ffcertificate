<?php
/**
 * DashboardShortcode
 *
 * Renders the user dashboard via [user_dashboard_personal] shortcode
 *
 * @package FreeFormCertificate\Shortcodes
 * @since 3.1.0
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 * @version 4.12.19 - Extracted DashboardAssetManager and DashboardViewMode for SRP compliance.
 */

declare(strict_types=1);

namespace FreeFormCertificate\Shortcodes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DashboardShortcode {

	/**
	 * Register shortcode and cache exclusion hook
	 */
	public static function init(): void {
		add_shortcode( 'user_dashboard_personal', array( __CLASS__, 'render' ) );
		add_action( 'template_redirect', array( __CLASS__, 'send_nocache_headers' ) );
	}

	/**
	 * Prevent page caching on dashboard pages.
	 *
	 * Dashboard pages contain user-specific data (certificates, appointments,
	 * profile) that must never be served from a full-page cache.
	 *
	 * @since 4.12.0
	 */
	public static function send_nocache_headers(): void {
		global $post;

		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		if ( ! has_shortcode( $post->post_content, 'user_dashboard_personal' ) ) {
			return;
		}

		// Universal constant recognised by WP Rocket, W3 Total Cache, WP Super Cache,.
		// Batcache, and most other full-page cache plugins.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		// Standard WordPress no-cache headers.
		nocache_headers();

		// LiteSpeed Cache: programmatic exclusion — hook name is defined by LiteSpeed Cache plugin.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'litespeed_control_set_nocache', 'FFC dashboard page requires user-specific content' );

		// Generic header recognised by LiteSpeed and other reverse proxies.
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
	}

	/**
	 * Render dashboard
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * @return string HTML output
	 */
	public static function render( array $atts = array() ): string {
		// Check if user is logged in.
		if ( ! is_user_logged_in() ) {
			return self::render_login_required();
		}

		// Check for admin view-as mode — delegated to DashboardViewMode.
		$view_as_user_id  = DashboardViewMode::get_view_as_user_id();
		$is_admin_viewing = $view_as_user_id && get_current_user_id() !== $view_as_user_id;

		// Enqueue assets — delegated to DashboardAssetManager.
		DashboardAssetManager::enqueue_assets( $view_as_user_id );

		// Check user permissions.
		$user_id = $view_as_user_id ? $view_as_user_id : get_current_user_id();
		$user    = get_user_by( 'id', $user_id );

		// Check if user has FFC permissions (based on capabilities, not just role).
		$can_view_certificates = $user && (
			user_can( $user, 'view_own_certificates' ) ||
			user_can( $user, 'manage_options' )
		);

		$can_view_appointments = $user && (
			user_can( $user, 'ffc_view_self_scheduling' ) ||
			user_can( $user, 'manage_options' )
		);

		$can_view_audience_bookings = $user && (
			user_can( $user, 'ffc_view_audience_bookings' ) ||
			user_can( $user, 'manage_options' )
		);

		// Only show audience tab if user actually belongs to at least one audience group.
		if ( $can_view_audience_bookings ) {
			$can_view_audience_bookings = DashboardAssetManager::user_has_audience_groups( $user_id );
		}

		// Check if user has any reregistration submissions.
		$can_view_reregistrations = class_exists( '\FreeFormCertificate\Reregistration\ReregistrationSubmissionRepository' )
			&& ! empty( \FreeFormCertificate\Reregistration\ReregistrationSubmissionRepository::get_all_by_user( $user_id ) );

		// Get current tab - default to first available tab.
		$default_tab = $can_view_certificates ? 'certificates' : ( $can_view_appointments ? 'appointments' : ( $can_view_audience_bookings ? 'audience' : ( $can_view_reregistrations ? 'reregistrations' : 'profile' ) ) );
		$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : $default_tab; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab parameter for display only.

		// Start output buffering.
		ob_start();

		?>
		<div class="ffc-user-dashboard" id="ffc-user-dashboard">

			<?php
			if ( $is_admin_viewing ) {
				echo wp_kses_post( DashboardViewMode::render_admin_viewing_banner( (int) $view_as_user_id ) );
			}
			echo wp_kses_post( self::render_redirect_message() );
			echo wp_kses_post( self::render_reregistration_banners( $user_id ) );

			// Form panel for reregistration editing — always present when reregistrations tab is visible.
			// Previously rendered inside render_reregistration_banners(), which could omit it.
			// when the banner query returned empty while the tab's REST API still showed editable items.
			if ( $can_view_reregistrations ) :
				?>
			<div id="ffc-rereg-form-panel" style="display:none;"></div>
			<?php endif; ?>

			<div class="ffc-dashboard-summary" id="ffc-dashboard-summary"></div>

			<nav class="ffc-dashboard-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Dashboard', 'ffcertificate' ); ?>">
				<?php if ( $can_view_certificates ) : ?>
					<button class="ffc-tab <?php echo esc_attr( 'certificates' === $current_tab ? 'active' : '' ); ?>"
							data-tab="certificates"
							role="tab"
							id="ffc-tab-certificates"
							aria-selected="<?php echo esc_attr( 'certificates' === $current_tab ? 'true' : 'false' ); ?>"
							aria-controls="tab-certificates"
							tabindex="<?php echo esc_attr( 'certificates' === $current_tab ? '0' : '-1' ); ?>">
						<span class="ffc-icon-scroll" aria-hidden="true"></span> <?php esc_html_e( 'Certificates', 'ffcertificate' ); ?>
					</button>
				<?php endif; ?>

				<?php if ( $can_view_appointments ) : ?>
					<button class="ffc-tab <?php echo esc_attr( 'appointments' === $current_tab ? 'active' : '' ); ?>"
							data-tab="appointments"
							role="tab"
							id="ffc-tab-appointments"
							aria-selected="<?php echo esc_attr( 'appointments' === $current_tab ? 'true' : 'false' ); ?>"
							aria-controls="tab-appointments"
							tabindex="<?php echo esc_attr( 'appointments' === $current_tab ? '0' : '-1' ); ?>">
						<span class="ffc-icon-calendar" aria-hidden="true"></span> <?php esc_html_e( 'Personal Schedule', 'ffcertificate' ); ?>
					</button>
				<?php endif; ?>

				<?php if ( $can_view_audience_bookings ) : ?>
					<button class="ffc-tab <?php echo esc_attr( 'audience' === $current_tab ? 'active' : '' ); ?>"
							data-tab="audience"
							role="tab"
							id="ffc-tab-audience"
							aria-selected="<?php echo esc_attr( 'audience' === $current_tab ? 'true' : 'false' ); ?>"
							aria-controls="tab-audience"
							tabindex="<?php echo esc_attr( 'audience' === $current_tab ? '0' : '-1' ); ?>">
						<span class="ffc-icon-users" aria-hidden="true"></span> <?php esc_html_e( 'Group Schedule', 'ffcertificate' ); ?>
					</button>
				<?php endif; ?>

				<?php if ( $can_view_reregistrations ) : ?>
					<button class="ffc-tab <?php echo esc_attr( 'reregistrations' === $current_tab ? 'active' : '' ); ?>"
							data-tab="reregistrations"
							role="tab"
							id="ffc-tab-reregistrations"
							aria-selected="<?php echo esc_attr( 'reregistrations' === $current_tab ? 'true' : 'false' ); ?>"
							aria-controls="tab-reregistrations"
							tabindex="<?php echo esc_attr( 'reregistrations' === $current_tab ? '0' : '-1' ); ?>">
						<span class="ffc-icon-file" aria-hidden="true"></span> <?php esc_html_e( 'Reregistration', 'ffcertificate' ); ?>
					</button>
				<?php endif; ?>

				<button class="ffc-tab <?php echo esc_attr( 'profile' === $current_tab ? 'active' : '' ); ?>"
						data-tab="profile"
						role="tab"
						id="ffc-tab-profile"
						aria-selected="<?php echo esc_attr( 'profile' === $current_tab ? 'true' : 'false' ); ?>"
						aria-controls="tab-profile"
						tabindex="<?php echo esc_attr( 'profile' === $current_tab ? '0' : '-1' ); ?>">
					<span aria-hidden="true">👤</span> <?php esc_html_e( 'Profile', 'ffcertificate' ); ?>
				</button>
			</nav>

			<?php if ( $can_view_certificates ) : ?>
				<div class="ffc-tab-content <?php echo esc_attr( 'certificates' === $current_tab ? 'active' : '' ); ?>"
					id="tab-certificates"
					role="tabpanel"
					aria-labelledby="ffc-tab-certificates">
					<div class="ffc-loading" role="status">
						<?php esc_html_e( 'Loading certificates...', 'ffcertificate' ); ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $can_view_appointments ) : ?>
				<div class="ffc-tab-content <?php echo esc_attr( 'appointments' === $current_tab ? 'active' : '' ); ?>"
					id="tab-appointments"
					role="tabpanel"
					aria-labelledby="ffc-tab-appointments">
					<div class="ffc-loading" role="status">
						<?php esc_html_e( 'Loading appointments...', 'ffcertificate' ); ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $can_view_audience_bookings ) : ?>
				<div class="ffc-tab-content <?php echo esc_attr( 'audience' === $current_tab ? 'active' : '' ); ?>"
					id="tab-audience"
					role="tabpanel"
					aria-labelledby="ffc-tab-audience">
					<div class="ffc-loading" role="status">
						<?php esc_html_e( 'Loading scheduled activities...', 'ffcertificate' ); ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $can_view_reregistrations ) : ?>
				<div class="ffc-tab-content <?php echo esc_attr( 'reregistrations' === $current_tab ? 'active' : '' ); ?>"
					id="tab-reregistrations"
					role="tabpanel"
					aria-labelledby="ffc-tab-reregistrations">
					<div class="ffc-loading" role="status">
						<?php esc_html_e( 'Loading reregistrations...', 'ffcertificate' ); ?>
					</div>
				</div>
			<?php endif; ?>

			<div class="ffc-tab-content <?php echo esc_attr( 'profile' === $current_tab ? 'active' : '' ); ?>"
				id="tab-profile"
				role="tabpanel"
				aria-labelledby="ffc-tab-profile">
				<div class="ffc-loading" role="status">
					<?php esc_html_e( 'Loading profile...', 'ffcertificate' ); ?>
				</div>
			</div>
		</div>
		<?php

		$dashboard_html = ob_get_clean();
		return $dashboard_html ? $dashboard_html : '';
	}

	/**
	 * Render login required message
	 *
	 * @return string HTML output
	 */
	private static function render_login_required(): string {
		ob_start();
		?>
		<div class="ffc-dashboard-notice ffc-notice-warning">
			<p><?php esc_html_e( 'You must be logged in to view your dashboard.', 'ffcertificate' ); ?></p>
			<p>
				<?php $permalink = get_permalink(); ?>
				<a href="<?php echo esc_url( wp_login_url( $permalink ? $permalink : '' ) ); ?>" class="button">
					<?php esc_html_e( 'Login', 'ffcertificate' ); ?>
				</a>
			</p>
		</div>
		<?php
		$login_required_html = ob_get_clean();
		return $login_required_html ? $login_required_html : '';
	}

	/**
	 * Render redirect message (from wp-admin block)
	 *
	 * @return string HTML output
	 */
	private static function render_redirect_message(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only parameter for redirect message.
		if ( ! isset( $_GET['ffc_redirect'] ) || sanitize_text_field( wp_unslash( $_GET['ffc_redirect'] ) ) !== 'access_denied' ) {
			return '';
		}

		$settings = get_option( 'ffc_user_access_settings', array() );
		$message  = $settings['redirect_message'] ?? __( 'You were redirected from the admin panel. Use this dashboard to access your certificates.', 'ffcertificate' );

		ob_start();
		?>
		<div class="ffc-dashboard-notice ffc-notice-info">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
		$redirect_html = ob_get_clean();
		return $redirect_html ? $redirect_html : '';
	}

	/**
	 * Render reregistration banners for active campaigns.
	 *
	 * @since 4.11.0
	 * @param int $user_id User ID.
	 * @return string HTML output.
	 */
	private static function render_reregistration_banners( int $user_id ): string {
		if ( ! class_exists( '\FreeFormCertificate\Reregistration\ReregistrationFrontend' ) ) {
			return '';
		}

		$reregistrations = \FreeFormCertificate\Reregistration\ReregistrationFrontend::get_user_reregistrations( $user_id );

		if ( empty( $reregistrations ) ) {
			return '';
		}

		ob_start();
		foreach ( $reregistrations as $rereg ) {
			if ( ! $rereg['can_submit'] ) {
				// Show completed status.
				if ( 'approved' === $rereg['submission_status'] ) {
					?>
					<div class="ffc-dashboard-notice ffc-notice-info ffc-rereg-banner ffc-rereg-completed">
						<div class="ffc-dashboard-header">
							<div>
								<strong><?php echo esc_html( $rereg['title'] ); ?></strong>
								<p class="ffc-m-5-0"><?php esc_html_e( 'Your reregistration has been approved.', 'ffcertificate' ); ?></p>
							</div>
							<?php if ( ! empty( $rereg['magic_link'] ) ) : ?>
							<div>
								<a href="<?php echo esc_url( $rereg['magic_link'] ); ?>" class="button ffc-btn-pdf" target="_blank" rel="noopener">
									<?php esc_html_e( 'Download Ficha', 'ffcertificate' ); ?>
								</a>
							</div>
							<?php endif; ?>
						</div>
					</div>
					<?php
				} elseif ( 'submitted' === $rereg['submission_status'] ) {
					?>
					<div class="ffc-dashboard-notice ffc-notice-info ffc-rereg-banner ffc-rereg-pending-review">
						<div class="ffc-dashboard-header">
							<div>
								<strong><?php echo esc_html( $rereg['title'] ); ?></strong>
								<p class="ffc-m-5-0"><?php esc_html_e( 'Your reregistration has been submitted and is pending review.', 'ffcertificate' ); ?></p>
							</div>
							<?php if ( ! empty( $rereg['magic_link'] ) ) : ?>
							<div>
								<a href="<?php echo esc_url( $rereg['magic_link'] ); ?>" class="button ffc-btn-pdf" target="_blank" rel="noopener">
									<?php esc_html_e( 'Download Ficha', 'ffcertificate' ); ?>
								</a>
							</div>
							<?php endif; ?>
						</div>
					</div>
					<?php
				}
				continue;
			}

			$end_date  = wp_date( get_option( 'date_format' ), strtotime( $rereg['end_date'] ) );
			$days_left = max( 0, (int) ( ( strtotime( $rereg['end_date'] ) - time() ) / 86400 ) );
			$urgency   = $days_left <= 3 ? 'ffc-rereg-urgent' : '';
			?>
			<div class="ffc-dashboard-notice ffc-notice-warning ffc-rereg-banner <?php echo esc_attr( $urgency ); ?>"
				data-reregistration-id="<?php echo esc_attr( $rereg['id'] ); ?>">
				<div class="ffc-dashboard-header">
					<div>
						<strong><?php echo esc_html( $rereg['title'] ); ?></strong>
						<p class="ffc-m-5-0">
							<?php
							/* translators: %s: deadline date */
							echo esc_html( sprintf( __( 'Deadline: %s', 'ffcertificate' ), $end_date ) );
							if ( $days_left <= 7 ) {
								echo ' — ';
								/* translators: %d: number of days remaining */
								echo '<strong>' . esc_html( sprintf( _n( '%d day left', '%d days left', $days_left, 'ffcertificate' ), $days_left ) ) . '</strong>';
							}
							?>
						</p>
					</div>
					<div>
						<button type="button" class="button button-primary ffc-rereg-open-form"
								data-reregistration-id="<?php echo esc_attr( $rereg['id'] ); ?>">
							<?php
							if ( 'in_progress' === $rereg['submission_status'] ) {
								esc_html_e( 'Continue Reregistration', 'ffcertificate' );
							} elseif ( 'rejected' === $rereg['submission_status'] ) {
								esc_html_e( 'Resubmit Reregistration', 'ffcertificate' );
							} else {
								esc_html_e( 'Complete Reregistration', 'ffcertificate' );
							}
							?>
						</button>
					</div>
				</div>
			</div>
			<?php
		}
		$rereg_banner_html = ob_get_clean();
		return $rereg_banner_html ? $rereg_banner_html : '';
	}
}
