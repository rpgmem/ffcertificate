<?php
/**
 * DashboardShortcode
 *
 * Renders the user dashboard via [user_dashboard_personal] shortcode
 *
 * @package FreeFormCertificate\Shortcodes
 * @since 3.1.0
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace
 * @version 4.12.19 - Extracted DashboardAssetManager and DashboardViewMode for SRP compliance.
 */

declare(strict_types=1);

namespace FreeFormCertificate\Shortcodes;

use FreeFormCertificate\Core\Button;
use FreeFormCertificate\Core\Notice;
use FreeFormCertificate\Core\PasswordInvite;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard Shortcode.
 */
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

		// Cloudflare (#921): CF ignores DONOTCACHEPAGE / X-LiteSpeed-Cache-Control
		// — it only honours Cache-Control. `Cloudflare-CDN-Cache-Control` (and the
		// generic `CDN-Cache-Control`) are CF's edge-specific directives and are
		// respected even under Cache Rules that would otherwise override the
		// browser Cache-Control, so this keeps per-user dashboard HTML off the CF
		// edge. Not a substitute for NOT enabling "Cache Everything" on FFC URLs
		// (a forced Page Rule can still override), but closes the common cases.
		header( 'Cloudflare-CDN-Cache-Control: no-store' );
		header( 'CDN-Cache-Control: no-store' );

		// LiteSpeed Cache: programmatic exclusion — hook name is defined by LiteSpeed Cache plugin.
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
			user_can( $user, 'ffc_view_own_certificates' ) ||
			user_can( $user, 'manage_options' )
		);

		$can_view_appointments = $user && (
			user_can( $user, 'ffc_view_own_appointments' ) ||
			user_can( $user, 'manage_options' )
		);

		$can_view_audience_bookings = $user && (
			user_can( $user, 'ffc_view_own_audience_bookings' ) ||
			user_can( $user, 'manage_options' )
		);

		// Only show audience tab if user actually belongs to at least one audience group.
		if ( $can_view_audience_bookings ) {
			$can_view_audience_bookings = DashboardAssetManager::user_has_audience_groups( $user_id );
		}

		// Check if user has any reregistration submissions.
		$can_view_reregistrations = class_exists( '\FreeFormCertificate\Reregistration\ReregistrationSubmissionReader' )
			&& ! empty( \FreeFormCertificate\Reregistration\ReregistrationSubmissionReader::get_all_by_user( $user_id ) );

		// Recruitment tab — visible when the user has at least one
		// classification across recruitment notices (joined via
		// `candidate.user_id`). The actual tab content is the same HTML
		// rendered by the standalone `[ffc_recruitment_my_calls]`
		// shortcode (which itself short-circuits to '' when the visibility
		// gate fails), so we render it once and reuse the result both as
		// the gate signal AND the tab body — avoiding a second pass over
		// the repository layer.
		$recruitment_html = '';
		if ( class_exists( '\FreeFormCertificate\Recruitment\RecruitmentDashboardSection' ) ) {
			// Pass the already-resolved (view-as-aware) $user_id explicitly
			// so the recruitment section renders for the impersonated user
			// when an admin is in view-as mode — not the admin's own row.
			$recruitment_html = \FreeFormCertificate\Recruitment\RecruitmentDashboardSection::render_for_user( (int) $user_id );
		}
		$can_view_recruitment = '' !== $recruitment_html;

		// Get current tab - default to first available tab.
		$default_tab = $can_view_certificates ? 'certificates' : ( $can_view_appointments ? 'appointments' : ( $can_view_audience_bookings ? 'audience' : ( $can_view_reregistrations ? 'reregistrations' : ( $can_view_recruitment ? 'recruitment' : 'profile' ) ) ) );
		$current_tab = \FreeFormCertificate\Core\RequestInput::get_get_string( 'tab', $default_tab );

		// Start output buffering.
		ob_start();

		?>
		<div class="ffc-shortcode ffc-user-dashboard" id="ffc-user-dashboard">

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

			<?php
			// The tab bar holds the tablist and, outside it, the Log Out link,
			// so the link shows on every tab without being read as a tab (#1840).
			?>
			<div class="ffc-dashboard-tabbar">
			<nav class="ffc-dashboard-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Dashboard', 'ffcertificate' ); ?>">
				<?php if ( $can_view_certificates ) : ?>
					<button class="ffc-tab <?php echo esc_attr( 'certificates' === $current_tab ? 'is-active' : '' ); ?>"
							data-tab="certificates"
							role="tab"
							id="ffc-tab-certificates"
							aria-selected="<?php echo esc_attr( 'certificates' === $current_tab ? 'true' : 'false' ); ?>"
							aria-controls="ffc-tabpanel-certificates"
							tabindex="<?php echo esc_attr( 'certificates' === $current_tab ? '0' : '-1' ); ?>">
						<span class="ffc-icon-scroll" aria-hidden="true"></span><?php esc_html_e( 'Certificates', 'ffcertificate' ); ?>
					</button>
				<?php endif; ?>

				<?php if ( $can_view_appointments ) : ?>
					<button class="ffc-tab <?php echo esc_attr( 'appointments' === $current_tab ? 'is-active' : '' ); ?>"
							data-tab="appointments"
							role="tab"
							id="ffc-tab-appointments"
							aria-selected="<?php echo esc_attr( 'appointments' === $current_tab ? 'true' : 'false' ); ?>"
							aria-controls="ffc-tabpanel-appointments"
							tabindex="<?php echo esc_attr( 'appointments' === $current_tab ? '0' : '-1' ); ?>">
						<span class="ffc-icon-calendar" aria-hidden="true"></span><?php esc_html_e( 'Personal Schedule', 'ffcertificate' ); ?>
					</button>
				<?php endif; ?>

				<?php if ( $can_view_audience_bookings ) : ?>
					<button class="ffc-tab <?php echo esc_attr( 'audience' === $current_tab ? 'is-active' : '' ); ?>"
							data-tab="audience"
							role="tab"
							id="ffc-tab-audience"
							aria-selected="<?php echo esc_attr( 'audience' === $current_tab ? 'true' : 'false' ); ?>"
							aria-controls="ffc-tabpanel-audience"
							tabindex="<?php echo esc_attr( 'audience' === $current_tab ? '0' : '-1' ); ?>">
						<span class="ffc-icon-users" aria-hidden="true"></span><?php esc_html_e( 'Group Schedule', 'ffcertificate' ); ?>
					</button>
				<?php endif; ?>

				<?php if ( $can_view_reregistrations ) : ?>
					<button class="ffc-tab <?php echo esc_attr( 'reregistrations' === $current_tab ? 'is-active' : '' ); ?>"
							data-tab="reregistrations"
							role="tab"
							id="ffc-tab-reregistrations"
							aria-selected="<?php echo esc_attr( 'reregistrations' === $current_tab ? 'true' : 'false' ); ?>"
							aria-controls="ffc-tabpanel-reregistrations"
							tabindex="<?php echo esc_attr( 'reregistrations' === $current_tab ? '0' : '-1' ); ?>">
						<span class="ffc-icon-file" aria-hidden="true"></span><?php esc_html_e( 'Reregistration', 'ffcertificate' ); ?>
					</button>
				<?php endif; ?>

				<?php if ( $can_view_recruitment ) : ?>
					<button class="ffc-tab <?php echo esc_attr( 'recruitment' === $current_tab ? 'is-active' : '' ); ?>"
							data-tab="recruitment"
							role="tab"
							id="ffc-tab-recruitment"
							aria-selected="<?php echo esc_attr( 'recruitment' === $current_tab ? 'true' : 'false' ); ?>"
							aria-controls="ffc-tabpanel-recruitment"
							tabindex="<?php echo esc_attr( 'recruitment' === $current_tab ? '0' : '-1' ); ?>">
						<span class="ffc-icon-megaphone" aria-hidden="true"></span><?php esc_html_e( 'My Calls', 'ffcertificate' ); ?>
					</button>
				<?php endif; ?>

				<button class="ffc-tab <?php echo esc_attr( 'profile' === $current_tab ? 'is-active' : '' ); ?>"
						data-tab="profile"
						role="tab"
						id="ffc-tab-profile"
						aria-selected="<?php echo esc_attr( 'profile' === $current_tab ? 'true' : 'false' ); ?>"
						aria-controls="ffc-tabpanel-profile"
						tabindex="<?php echo esc_attr( 'profile' === $current_tab ? '0' : '-1' ); ?>">
					<span class="ffc-icon-user" aria-hidden="true"></span><?php esc_html_e( 'Profile', 'ffcertificate' ); ?>
				</button>
			</nav>

			<a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" class="ffc-logout-link">
				<span class="ffc-icon-logout" aria-hidden="true"></span><?php esc_html_e( 'Log Out', 'ffcertificate' ); ?>
			</a>
			</div>

			<?php if ( $can_view_certificates ) : ?>
				<div class="ffc-tab-content <?php echo esc_attr( 'certificates' === $current_tab ? 'is-active' : '' ); ?>"
					id="ffc-tabpanel-certificates"
					role="tabpanel"
					aria-labelledby="ffc-tab-certificates">
					<div class="ffc-loading" role="status">
						<?php esc_html_e( 'Loading certificates...', 'ffcertificate' ); ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $can_view_appointments ) : ?>
				<div class="ffc-tab-content <?php echo esc_attr( 'appointments' === $current_tab ? 'is-active' : '' ); ?>"
					id="ffc-tabpanel-appointments"
					role="tabpanel"
					aria-labelledby="ffc-tab-appointments">
					<div class="ffc-loading" role="status">
						<?php esc_html_e( 'Loading appointments...', 'ffcertificate' ); ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $can_view_audience_bookings ) : ?>
				<div class="ffc-tab-content <?php echo esc_attr( 'audience' === $current_tab ? 'is-active' : '' ); ?>"
					id="ffc-tabpanel-audience"
					role="tabpanel"
					aria-labelledby="ffc-tab-audience">
					<div class="ffc-loading" role="status">
						<?php esc_html_e( 'Loading scheduled activities...', 'ffcertificate' ); ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $can_view_reregistrations ) : ?>
				<div class="ffc-tab-content <?php echo esc_attr( 'reregistrations' === $current_tab ? 'is-active' : '' ); ?>"
					id="ffc-tabpanel-reregistrations"
					role="tabpanel"
					aria-labelledby="ffc-tab-reregistrations">
					<div class="ffc-loading" role="status">
						<?php esc_html_e( 'Loading reregistrations...', 'ffcertificate' ); ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $can_view_recruitment ) : ?>
				<div class="ffc-tab-content <?php echo esc_attr( 'recruitment' === $current_tab ? 'is-active' : '' ); ?>"
					id="ffc-tabpanel-recruitment"
					role="tabpanel"
					aria-labelledby="ffc-tab-recruitment">
					<?php
					// Server-rendered (the section is built from a few tens
					// of rows at most, no point in lazy-loading via REST).
					echo wp_kses_post( $recruitment_html );
					?>
				</div>
			<?php endif; ?>

			<div class="ffc-tab-content <?php echo esc_attr( 'profile' === $current_tab ? 'is-active' : '' ); ?>"
				id="ffc-tabpanel-profile"
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
		// The invitation's link arrives here, with no session: that is the
		// normal case (#1212). The password-setting screen lives on the
		// dashboard's page on purpose -- no new page, no new option, and
		// therefore nothing for `uninstall.php` and the fresh-install gate.
		if ( PasswordInvite::request_has_link() ) {
			$password_screen = self::render_set_password();
			if ( '' !== $password_screen ) {
				return $password_screen;
			}
		}

		// The notice is a plugin root of its own and loads the dashboard's
		// sheets: before #1832 it loaded none, and sat outside any root.
		DashboardAssetManager::enqueue_styles();

		ob_start();
		?>
		<div class="ffc-shortcode">
			<?php
			$permalink   = get_permalink();
			$notice_html = Notice::render(
				'warning',
				'lock',
				__( 'You must be logged in to view your dashboard.', 'ffcertificate' ),
				'',
				array(
					'role'    => 'note',
					'hook'    => 'login-required',
					'actions' => Button::html(
						array(
							'label'    => __( 'Login', 'ffcertificate' ),
							'category' => 'primary',
							'icon'     => 'login',
							'href'     => wp_login_url( $permalink ? $permalink : '' ),
						)
					),
				)
			);
			echo $notice_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() escapes every value.
			?>
		</div>
		<?php
		$login_required_html = ob_get_clean();
		return $login_required_html ? $login_required_html : '';
	}

	/**
	 * Render the set-password screen for an invited member.
	 *
	 * Returns '' when the key is not valid -- the caller then falls through to
	 * the normal "please log in" notice, which is the right answer for an
	 * expired link: whoever has a password gets in there, whoever does not asks
	 * for a new invitation.
	 *
	 * @return string HTML output, or '' when the link is not usable.
	 */
	private static function render_set_password(): string {
		$pair = PasswordInvite::request_pair();
		$user = PasswordInvite::validate( $pair['key'], $pair['login'] );
		if ( is_wp_error( $user ) ) {
			return '';
		}

		DashboardAssetManager::enqueue_assets( 0 );

		$ffc_error   = RequestInput::get_get_string( 'ffc_password_error' );
		$ffc_min_len = PasswordInvite::MIN_PASSWORD_LENGTH;

		ob_start();
		include FFC_PLUGIN_DIR . 'templates/public/set-password.php';
		$html = ob_get_clean();

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Render redirect message (from wp-admin block)
	 *
	 * @return string HTML output
	 */
	private static function render_redirect_message(): string {
		if ( \FreeFormCertificate\Core\RequestInput::get_get_string( 'ffc_redirect' ) !== 'access_denied' ) {
			return '';
		}

		$settings = get_option( 'ffc_user_access_settings', array() );
		$message  = $settings['redirect_message'] ?? __( 'You were redirected from the admin panel. Use this dashboard to access your certificates.', 'ffcertificate' );

		return Notice::render(
			'info',
			'info',
			(string) $message,
			'',
			array(
				'role' => 'note',
				'hook' => 'admin-redirect',
			)
		);
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

		// Active campaigns, then the expired ones whose approved banner is
		// still inside its window (#1887). The second set is approved-only,
		// so it can only reach the approved branch below.
		$reregistrations = array_merge(
			\FreeFormCertificate\Reregistration\ReregistrationFrontend::get_user_reregistrations( $user_id ),
			\FreeFormCertificate\Reregistration\ReregistrationFrontend::get_recently_expired_approved( $user_id )
		);

		if ( empty( $reregistrations ) ) {
			return '';
		}

		$html = '';
		foreach ( $reregistrations as $rereg ) {
			$title    = (string) $rereg['title'];
			$pdf_link = '';
			if ( ! empty( $rereg['can_download'] ) ) {
				// Built in the browser from the signed-in REST route (#2242),
				// by the same handler as the table's button.
				$pdf_link = Button::html(
					array(
						'label'    => __( 'Download Record', 'ffcertificate' ),
						'category' => 'neutral',
						'icon'     => 'download',
						// Not `ffc-btn-pdf`: that class takes the dashboard's
						// compact table-row size, and a banner action has the
						// family's standard size like its siblings (#1887).
						'class'    => 'ffc-rereg-banner-record ffc-rereg-download-record',
						'attrs'    => array(
							'data-submission-id' => (string) (int) $rereg['submission_id'],
						),
					)
				);
			}

			if ( ! $rereg['can_submit'] ) {
				if ( 'approved' === $rereg['submission_status'] ) {
					if ( self::approved_banner_expired( $rereg ) ) {
						continue;
					}
					$html .= self::reregistration_banner( 'success', 'success', $title, esc_html__( 'Your reregistration has been approved.', 'ffcertificate' ), $pdf_link );
				} elseif ( 'submitted' === $rereg['submission_status'] ) {
					// Retract (#2242): the member takes back a submission nobody
					// reviewed yet, while the window is open. The script asks
					// for confirmation first.
					$retract = empty( $rereg['can_retract'] ) ? '' : Button::html(
						array(
							'label'    => __( 'Retract submission', 'ffcertificate' ),
							'category' => 'caution',
							'icon'     => 'restore',
							'class'    => 'ffc-rereg-retract',
							'attrs'    => array( 'data-reregistration-id' => (string) (int) $rereg['id'] ),
						)
					);
					$html   .= self::reregistration_banner( 'info', 'clock', $title, esc_html__( 'Your reregistration has been submitted and is pending review.', 'ffcertificate' ), $pdf_link . $retract );
				} else {
					/*
					 * Every other non-submittable status (#1125). The two `if`s
					 * above covered `approved` and `submitted`, and the bare
					 * `continue` that used to sit here rendered NOTHING for
					 * anything else — which is how a user left at `expired` by
					 * a campaign that later reopened saw no banner at all, and
					 * had no way to know why.
					 *
					 * `expired` is handled at the source now (reactivation
					 * reopens it) and `no_submission` is submittable, so this
					 * branch should be unreachable today. It exists so that the
					 * *next* status added to the set produces a visible state
					 * instead of a blank screen: a silent `continue` is what
					 * made this defect cost a smoke run to find.
					 */
					$html .= self::reregistration_banner(
						'info',
						'info',
						$title,
						esc_html(
							sprintf(
								/* translators: %s: submission status label. */
								__( 'Status: %s', 'ffcertificate' ),
								\FreeFormCertificate\Reregistration\ReregistrationSubmissionReader::get_status_label( (string) $rereg['submission_status'] )
							)
						)
					);
				}
				continue;
			}

			$end_date  = \FreeFormCertificate\Core\DateFormatter::format_date( $rereg['end_date'] );
			$days_left = max( 0, (int) ( ( strtotime( $rereg['end_date'] ) - time() ) / 86400 ) );

			/* translators: %s: deadline date */
			$deadline = esc_html( sprintf( __( 'Deadline: %s', 'ffcertificate' ), $end_date ) );
			if ( $days_left <= 7 ) {
				/* translators: %d: number of days remaining */
				$deadline .= ' — <strong>' . esc_html( sprintf( _n( '%d day left', '%d days left', $days_left, 'ffcertificate' ), $days_left ) ) . '</strong>';
			}

			if ( 'in_progress' === $rereg['submission_status'] ) {
				$label = __( 'Continue Reregistration', 'ffcertificate' );
			} elseif ( 'rejected' === $rereg['submission_status'] ) {
				$label = __( 'Resubmit Reregistration', 'ffcertificate' );
			} else {
				$label = __( 'Complete Reregistration', 'ffcertificate' );
			}
			$open_button = Button::html(
				array(
					'label'    => $label,
					'category' => 'primary',
					'icon'     => 'edit',
					'class'    => 'ffc-rereg-open-form',
					'attrs'    => array( 'data-reregistration-id' => (string) $rereg['id'] ),
				)
			);

			// The reviewer's reason for a rejection or a return to draft
			// (#2242), on its own line under the deadline.
			$reason = isset( $rereg['review_reason'] ) && is_string( $rereg['review_reason'] ) ? $rereg['review_reason'] : '';
			if ( '' !== $reason ) {
				$deadline .= '<br><strong>' . esc_html__( 'Reviewer\'s note:', 'ffcertificate' ) . '</strong> ' . nl2br( esc_html( $reason ) );
			}

			// Three days or fewer is urgent: the danger tone, where the old
			// banner repainted its warning one by hand.
			$html .= self::reregistration_banner(
				$days_left <= 3 ? 'danger' : 'warning',
				'clock',
				$title,
				$deadline,
				$open_button,
				(string) $rereg['id']
			);
		}

		return $html;
	}

	/**
	 * Whether an approved campaign's banner has outlived its window (#1887).
	 *
	 * The decision is `ReregistrationBannerWindow`'s; this only feeds it the
	 * row, the setting, the site timezone and the clock. The Reregistrations
	 * tab keeps listing the record either way: only the banner goes.
	 *
	 * @param array<string, mixed> $rereg One row of `get_user_reregistrations()`.
	 * @return bool
	 */
	private static function approved_banner_expired( array $rereg ): bool {
		$reviewed_at = isset( $rereg['reviewed_at'] ) && is_numeric( $rereg['reviewed_at'] ) ? (int) $rereg['reviewed_at'] : null;

		return \FreeFormCertificate\Core\ReregistrationBannerWindow::approved_banner_expired(
			isset( $rereg['end_date'] ) && is_scalar( $rereg['end_date'] ) ? (string) $rereg['end_date'] : '',
			$reviewed_at,
			\FreeFormCertificate\Core\ReregistrationBannerWindow::days(),
			time(),
			wp_timezone()
		);
	}

	/**
	 * One reregistration banner of the dashboard: a notice titled with the
	 * campaign, its state, and the action that state allows (#1842).
	 *
	 * @param string $tone      Notice tone.
	 * @param string $icon      Registry icon.
	 * @param string $title     Campaign title, plain text.
	 * @param string $text_html The state line, already escaped.
	 * @param string $actions   The banner's link or button, already escaped.
	 * @param string $rereg_id  The campaign id the open-form script and the
	 *                          post-submit hide look the banner up by; '' for
	 *                          a banner with nothing to open.
	 * @return string
	 */
	private static function reregistration_banner( string $tone, string $icon, string $title, string $text_html, string $actions = '', string $rereg_id = '' ): string {
		$args = array(
			'role'    => 'note',
			'html'    => true,
			'hook'    => 'rereg-banner',
			'actions' => $actions,
		);
		if ( '' !== $rereg_id ) {
			$args['attrs'] = array( 'data-reregistration-id' => $rereg_id );
		}

		return Notice::render( $tone, $icon, $text_html, $title, $args );
	}
}
