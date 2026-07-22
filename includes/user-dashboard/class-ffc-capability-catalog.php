<?php
/**
 * CapabilityCatalog
 *
 * Single source of truth for the *human-facing* metadata of every FFC
 * capability: a stable label, a one-line description, the domain group it
 * belongs to, and whether the group is end-user or admin-level.
 *
 * Why this exists: the cap *slugs* live in {@see CapabilityManager} (the
 * authoritative machine list consumed by `grant_*`, role registration, the
 * REST permission callbacks, etc.). What was missing was a place that maps
 * each slug to a label + description + grouping so the admin UI can render
 * the full set coherently — and so the render path and the save path read
 * from the *same* list (previously the user-edit screen rendered only 10 of
 * the ~26 caps while the save loop iterated all of them, silently calling
 * `remove_cap()` on every cap that had no checkbox).
 *
 * Grouping: end-user (self-service) caps and the admin caps are each split
 * **per module** (certificates, appointments, audiences, reregistration,
 * custom fields, short URLs, settings, system, recruitment) so the editor
 * reads like the plugin's module map rather than one flat tier-ordered list.
 * `level` separates the two visual sections (self-service vs administration)
 * and drives the per-card collapse + warning badge.
 *
 * Invariant: {@see self::all_slugs()} must equal
 * {@see CapabilityManager::get_all_capabilities()} as a set. The
 * `CapabilityCatalogTest` enforces this, so adding a cap to the registry
 * without giving it catalog metadata fails CI.
 *
 * @package FreeFormCertificate\UserDashboard
 * @since   6.9.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UserDashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Human-readable catalog of FFC capabilities, grouped by module.
 *
 * @phpstan-type CapMeta array{label: string, description: string, surface?: string}
 * @phpstan-type CapGroup array{key: string, label: string, level: 'user'|'admin', caps: array<string, CapMeta>}
 */
final class CapabilityCatalog {

	/** Group key: end-user certificate features. */
	public const GROUP_CERTIFICATE = 'certificate';

	/** Group key: end-user appointment features. */
	public const GROUP_APPOINTMENT = 'appointment';

	/** Group key: end-user audience features. */
	public const GROUP_AUDIENCE = 'audience';

	/** Group key: admin certificate management. */
	public const GROUP_ADMIN_CERTIFICATES = 'admin_certificates';

	/** Group key: admin self-scheduling / appointment management. */
	public const GROUP_ADMIN_APPOINTMENTS = 'admin_appointments';

	/** Group key: admin-level certificate-form structure management (#739). */
	public const GROUP_ADMIN_FORMS = 'admin_forms';

	/** Group key: admin-level self-scheduling calendar structure management (#739). */
	public const GROUP_ADMIN_CALENDARS = 'admin_calendars';

	/** Group key: admin audience management. */
	public const GROUP_ADMIN_AUDIENCES = 'admin_audiences';

	/** Group key: admin reregistration management. */
	public const GROUP_ADMIN_REREGISTRATION = 'admin_reregistration';

	/** Group key: admin custom-field management. */
	public const GROUP_ADMIN_CUSTOM_FIELDS = 'admin_custom_fields';

	/** Group key: admin short-URL management. */
	public const GROUP_ADMIN_URL_SHORTENER = 'admin_url_shortener';

	/** Group key: admin plugin settings. */
	public const GROUP_ADMIN_SETTINGS = 'admin_settings';

	/** Group key: cross-cutting system / tooling caps (audit log, impersonation, REST API). */
	public const GROUP_ADMIN_SYSTEM = 'admin_system';

	/** Group key: admin-level recruitment management. */
	public const GROUP_ADMIN_RECRUITMENT = 'admin_recruitment';

	/**
	 * Ordered list of capability groups with full metadata.
	 *
	 * The order here is the render order. All `user` (self-service) groups
	 * come first, then every `admin` group, one per module. `level` drives
	 * the UI affordance: it separates the two sections and every group starts
	 * collapsed. A per-cap optional `surface` tags the handful of caps whose
	 * execution context isn't obvious from the section (`api` for the REST
	 * cap, `frontend` for the booking-flow bypass).
	 *
	 * @return list<array<string, mixed>>
	 * @phpstan-return list<CapGroup>
	 */
	public static function groups(): array {
		return array(
			// ---------------------------------------------------------------
			// Self-service (end-user, frontend) — one group per module.
			// ---------------------------------------------------------------
			array(
				'key'   => self::GROUP_CERTIFICATE,
				'label' => __( 'Certificates', 'ffcertificate' ),
				'level' => 'user',
				'caps'  => array(
					'ffc_view_own_certificates'        => array(
						'label'       => __( 'View own certificates', 'ffcertificate' ),
						'description' => __( 'Shows the certificates tab in the user dashboard.', 'ffcertificate' ),
					),
					'ffc_download_own_certificates'    => array(
						'label'       => __( 'Download own certificates', 'ffcertificate' ),
						'description' => __( 'Enables the certificate PDF download button.', 'ffcertificate' ),
					),
					'ffc_view_own_certificate_history' => array(
						'label'       => __( 'View certificate history', 'ffcertificate' ),
						'description' => __( "Lists the user's previous certificate emissions.", 'ffcertificate' ),
					),
				),
			),
			array(
				'key'   => self::GROUP_APPOINTMENT,
				'label' => __( 'Appointments', 'ffcertificate' ),
				'level' => 'user',
				'caps'  => array(
					'ffc_book_own_appointments'   => array(
						'label'       => __( 'Book appointments', 'ffcertificate' ),
						'description' => __( 'Allows reserving slots on public calendars.', 'ffcertificate' ),
					),
					'ffc_view_own_appointments'   => array(
						'label'       => __( 'View own appointments', 'ffcertificate' ),
						'description' => __( 'Shows the slots the user has already booked.', 'ffcertificate' ),
					),
					'ffc_cancel_own_appointments' => array(
						'label'       => __( 'Cancel own appointments', 'ffcertificate' ),
						'description' => __( 'Enables cancellation from the dashboard or the e-mail link.', 'ffcertificate' ),
					),
				),
			),
			array(
				'key'   => self::GROUP_AUDIENCE,
				'label' => __( 'Audiences', 'ffcertificate' ),
				'level' => 'user',
				'caps'  => array(
					'ffc_view_own_audience_bookings' => array(
						'label'       => __( 'View audience bookings', 'ffcertificate' ),
						'description' => __( 'Shows group/audience bookings in the dashboard.', 'ffcertificate' ),
					),
				),
			),

			// ---------------------------------------------------------------
			// Administration (wp-admin) — one group per module. Within each
			// group the caps follow the tier progression: view → manage →
			// edit → export → import → delete.
			// ---------------------------------------------------------------
			array(
				'key'   => self::GROUP_ADMIN_CERTIFICATES,
				'label' => __( 'Certificates', 'ffcertificate' ),
				'level' => 'admin',
				'caps'  => array(
					'ffc_view_certificates'   => array(
						'label'       => __( 'View certificates', 'ffcertificate' ),
						'description' => __( 'Read-only access to the submissions list and certificates dashboard.', 'ffcertificate' ),
					),
					'ffc_manage_certificates' => array(
						'label'       => __( 'Manage certificates', 'ffcertificate' ),
						'description' => __( 'Access the certificate administration screens.', 'ffcertificate' ),
					),
					'ffc_edit_certificates'   => array(
						'label'       => __( 'Edit submission data on issued certificates', 'ffcertificate' ),
						'description' => __( 'Fix typos on already-issued certificates without holding manage_options.', 'ffcertificate' ),
					),
					'ffc_export_certificates' => array(
						'label'       => __( 'Export certificates', 'ffcertificate' ),
						'description' => __( 'Download bulk certificate exports.', 'ffcertificate' ),
					),
					'ffc_delete_certificates' => array(
						'label'       => __( 'Delete certificates', 'ffcertificate' ),
						'description' => __( 'Permanently delete certificate submissions (bulk delete).', 'ffcertificate' ),
					),
				),
			),
			array(
				'key'   => self::GROUP_ADMIN_FORMS,
				'label' => __( 'Forms', 'ffcertificate' ),
				'level' => 'admin',
				'caps'  => array(
					'ffc_manage_forms' => array(
						'label'       => __( 'Manage forms', 'ffcertificate' ),
						'description' => __( 'Create and edit certificate forms — PDF layout, fields and options. Replaces the native post-editing capability the form CPT relied on before (#739).', 'ffcertificate' ),
					),
				),
			),
			array(
				'key'   => self::GROUP_ADMIN_APPOINTMENTS,
				'label' => __( 'Appointments', 'ffcertificate' ),
				'level' => 'admin',
				'caps'  => array(
					'ffc_view_appointments'   => array(
						'label'       => __( 'View appointments', 'ffcertificate' ),
						'description' => __( 'Read-only access to all scheduled appointments.', 'ffcertificate' ),
					),
					'ffc_manage_appointments' => array(
						'label'       => __( 'Manage self-scheduling', 'ffcertificate' ),
						'description' => __( 'Configure personal calendars and self-scheduling windows.', 'ffcertificate' ),
					),
					'ffc_bypass_appointments' => array(
						'label'       => __( 'Scheduling bypass', 'ffcertificate' ),
						'description' => __( 'Private calendars, past dates, out-of-hours and blocked dates.', 'ffcertificate' ),
						'surface'     => 'frontend',
					),
					'ffc_export_appointments' => array(
						'label'       => __( 'Export appointments', 'ffcertificate' ),
						'description' => __( 'Download the bulk appointments CSV.', 'ffcertificate' ),
					),
					'ffc_delete_appointments' => array(
						'label'       => __( 'Delete appointments', 'ffcertificate' ),
						'description' => __( 'Permanently delete appointments and calendar cleanup purges.', 'ffcertificate' ),
					),
				),
			),
			array(
				'key'   => self::GROUP_ADMIN_CALENDARS,
				'label' => __( 'Calendars', 'ffcertificate' ),
				'level' => 'admin',
				'caps'  => array(
					'ffc_manage_calendars' => array(
						'label'       => __( 'Manage calendars', 'ffcertificate' ),
						'description' => __( 'Create and edit self-scheduling calendars — structure, working hours and options. Distinct from managing the bookings made against them (#739).', 'ffcertificate' ),
					),
				),
			),
			array(
				'key'   => self::GROUP_ADMIN_AUDIENCES,
				'label' => __( 'Audiences', 'ffcertificate' ),
				'level' => 'admin',
				'caps'  => array(
					'ffc_view_audiences'   => array(
						'label'       => __( 'View audiences', 'ffcertificate' ),
						'description' => __( 'Read-only access to audiences and their members.', 'ffcertificate' ),
					),
					'ffc_manage_audiences' => array(
						'label'       => __( 'Manage audiences', 'ffcertificate' ),
						'description' => __( 'Create audiences and manage their members.', 'ffcertificate' ),
					),
					'ffc_import_audiences' => array(
						'label'       => __( 'Import audiences', 'ffcertificate' ),
						'description' => __( 'Bulk-load members and audiences from CSV.', 'ffcertificate' ),
					),
					'ffc_export_audiences' => array(
						'label'       => __( 'Export audiences', 'ffcertificate' ),
						'description' => __( 'Download the members and audiences CSV exports.', 'ffcertificate' ),
					),
					'ffc_delete_audiences' => array(
						'label'       => __( 'Delete audiences', 'ffcertificate' ),
						'description' => __( 'Delete audiences, calendars, environments and bookings.', 'ffcertificate' ),
					),
				),
			),
			array(
				'key'   => self::GROUP_ADMIN_REREGISTRATION,
				'label' => __( 'Reregistration', 'ffcertificate' ),
				'level' => 'admin',
				'caps'  => array(
					'ffc_view_reregistration'   => array(
						'label'       => __( 'View reregistration campaigns', 'ffcertificate' ),
						'description' => __( 'Read-only access to the Reregistration admin page.', 'ffcertificate' ),
					),
					'ffc_manage_reregistration' => array(
						'label'       => __( 'Manage reregistration campaigns', 'ffcertificate' ),
						'description' => __( 'Access the Reregistration admin page.', 'ffcertificate' ),
					),
					'ffc_export_reregistration' => array(
						'label'       => __( 'Export reregistration submissions', 'ffcertificate' ),
						'description' => __( 'Download a campaign\'s submissions as CSV.', 'ffcertificate' ),
					),
					'ffc_delete_reregistration' => array(
						'label'       => __( 'Delete reregistration campaigns', 'ffcertificate' ),
						'description' => __( 'Delete campaigns and their submissions (cascade).', 'ffcertificate' ),
					),
				),
			),
			array(
				'key'   => self::GROUP_ADMIN_CUSTOM_FIELDS,
				'label' => __( 'Custom fields', 'ffcertificate' ),
				'level' => 'admin',
				'caps'  => array(
					'ffc_view_custom_fields'   => array(
						'label'       => __( 'View custom fields', 'ffcertificate' ),
						'description' => __( 'Read-only access to user/audience custom field definitions.', 'ffcertificate' ),
					),
					'ffc_manage_custom_fields' => array(
						'label'       => __( 'Manage user custom fields', 'ffcertificate' ),
						'description' => __( 'Define extra user/audience fields.', 'ffcertificate' ),
					),
					'ffc_delete_custom_fields' => array(
						'label'       => __( 'Delete custom fields', 'ffcertificate' ),
						'description' => __( 'Delete user/audience custom field definitions.', 'ffcertificate' ),
					),
				),
			),
			array(
				'key'   => self::GROUP_ADMIN_URL_SHORTENER,
				'label' => __( 'Short URLs', 'ffcertificate' ),
				'level' => 'admin',
				'caps'  => array(
					'ffc_view_url_shortener'   => array(
						'label'       => __( 'View short URLs', 'ffcertificate' ),
						'description' => __( 'Read-only access to the Short URLs admin page.', 'ffcertificate' ),
					),
					'ffc_manage_url_shortener' => array(
						'label'       => __( 'Manage short URLs', 'ffcertificate' ),
						'description' => __( 'Create, edit and toggle short URLs (delete is its own cap below).', 'ffcertificate' ),
					),
					'ffc_delete_url_shortener' => array(
						'label'       => __( 'Delete short URLs', 'ffcertificate' ),
						'description' => __( 'Trash, restore, permanently delete and empty the short-URL trash.', 'ffcertificate' ),
					),
				),
			),
			array(
				'key'   => self::GROUP_ADMIN_SETTINGS,
				'label' => __( 'Settings', 'ffcertificate' ),
				'level' => 'admin',
				'caps'  => array(
					'ffc_view_settings'              => array(
						'label'       => __( 'View settings', 'ffcertificate' ),
						'description' => __( 'Read-only access to the plugin Settings page.', 'ffcertificate' ),
					),
					'ffc_manage_settings'            => array(
						'label'       => __( 'Manage settings', 'ffcertificate' ),
						'description' => __( 'Access the plugin Settings page.', 'ffcertificate' ),
					),
					'ffc_manage_settings_smtp'       => array(
						'label'       => __( 'Manage SMTP / email settings', 'ffcertificate' ),
						'description' => __( 'Save the SMTP transport and the Email Model configuration.', 'ffcertificate' ),
					),
					'ffc_manage_settings_dangerzone' => array(
						'label'       => __( 'Run destructive maintenance', 'ffcertificate' ),
						'description' => __( 'Execute the Settings danger-zone actions: data deletion, cleanup and migrations.', 'ffcertificate' ),
					),
				),
			),
			array(
				'key'   => self::GROUP_ADMIN_SYSTEM,
				'label' => __( 'System & tools', 'ffcertificate' ),
				'level' => 'admin',
				'caps'  => array(
					'ffc_view_activity_log'   => array(
						'label'       => __( 'View activity log', 'ffcertificate' ),
						'description' => __( 'Inspect the audit trail.', 'ffcertificate' ),
					),
					'ffc_export_activity_log' => array(
						'label'       => __( 'Export activity log', 'ffcertificate' ),
						'description' => __( 'Download the audit trail as CSV.', 'ffcertificate' ),
					),
					'ffc_view_as_user'        => array(
						'label'       => __( 'View as user', 'ffcertificate' ),
						'description' => __( "Open the dashboard from another user's perspective.", 'ffcertificate' ),
					),
					'ffc_view_forms_api'      => array(
						'label'       => __( 'Read forms via REST API', 'ffcertificate' ),
						'description' => __( 'For external integrators authenticating with Application Passwords.', 'ffcertificate' ),
						'surface'     => 'api',
					),
				),
			),
			array(
				'key'   => self::GROUP_ADMIN_RECRUITMENT,
				'label' => __( 'Recruitment', 'ffcertificate' ),
				'level' => 'admin',
				'caps'  => array(
					'ffc_view_recruitment'            => array(
						'label'       => __( 'View recruitment', 'ffcertificate' ),
						'description' => __( 'Read-only access to notices and classifications.', 'ffcertificate' ),
					),
					'ffc_view_recruitment_reasons'    => array(
						'label'       => __( 'View reasons', 'ffcertificate' ),
						'description' => __( 'Read-only access to the status-reason catalog.', 'ffcertificate' ),
					),
					'ffc_view_recruitment_settings'   => array(
						'label'       => __( 'View recruitment settings', 'ffcertificate' ),
						'description' => __( 'Read-only access to the recruitment Settings tab.', 'ffcertificate' ),
					),
					'ffc_view_recruitment_pii'        => array(
						'label'       => __( 'View sensitive data (PII)', 'ffcertificate' ),
						'description' => __( "Reveal candidates' CPF / RG.", 'ffcertificate' ),
					),
					'ffc_import_recruitment'          => array(
						'label'       => __( 'Import recruitment CSV', 'ffcertificate' ),
						'description' => __( 'Upload candidate lists.', 'ffcertificate' ),
					),
					'ffc_call_recruitment'            => array(
						'label'       => __( 'Call candidates', 'ffcertificate' ),
						'description' => __( 'Issue and cancel convocations.', 'ffcertificate' ),
					),
					'ffc_manage_recruitment'          => array(
						'label'       => __( 'Manage recruitment (umbrella)', 'ffcertificate' ),
						'description' => __( 'Full access to the recruitment module.', 'ffcertificate' ),
					),
					'ffc_manage_recruitment_reasons'  => array(
						'label'       => __( 'Manage reasons', 'ffcertificate' ),
						'description' => __( 'The status-reason catalog.', 'ffcertificate' ),
					),
					'ffc_manage_recruitment_settings' => array(
						'label'       => __( 'Configure recruitment', 'ffcertificate' ),
						'description' => __( "Adjust the recruitment module's settings.", 'ffcertificate' ),
					),
					'ffc_delete_recruitment'          => array(
						'label'       => __( 'Delete recruitment records', 'ffcertificate' ),
						'description' => __( 'Permanently delete notices, candidates, adjutancies and classifications (reason deletion stays under Manage reasons).', 'ffcertificate' ),
					),
				),
			),
		);
	}

	/**
	 * Flat list of every cataloged slug, in group/render order.
	 *
	 * @return list<string>
	 */
	public static function all_slugs(): array {
		$out = array();
		foreach ( self::groups() as $group ) {
			foreach ( array_keys( $group['caps'] ) as $slug ) {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/**
	 * Metadata for a single capability slug, or null when not cataloged.
	 *
	 * @param string $slug Capability slug.
	 * @return array<string, string>|null
	 * @phpstan-return array{label: string, description: string, group: string}|null
	 */
	public static function get( string $slug ): ?array {
		foreach ( self::groups() as $group ) {
			if ( isset( $group['caps'][ $slug ] ) ) {
				return array(
					'label'       => $group['caps'][ $slug ]['label'],
					'description' => $group['caps'][ $slug ]['description'],
					'group'       => $group['key'],
				);
			}
		}
		return null;
	}

	/**
	 * Localized section header for a group `level`. Drives the visual
	 * Self-service / Administration divider rendered between the two tiers.
	 *
	 * @param string $level Group level (`user` or `admin`).
	 * @return string
	 */
	public static function level_section_label( string $level ): string {
		return 'admin' === $level
			? __( 'Administration', 'ffcertificate' )
			: __( 'Self-service', 'ffcertificate' );
	}

	/**
	 * Localized short badge for a cap's optional `surface` tag. Only the caps
	 * whose execution context is not obvious from their section carry one
	 * (the REST-API cap and the frontend booking-flow bypass); everything else
	 * follows its section (self-service = frontend, administration = wp-admin)
	 * and gets no badge.
	 *
	 * @param string $surface Surface tag (`frontend`, `admin`, `api`).
	 * @return string Empty string for an unknown tag.
	 */
	public static function surface_label( string $surface ): string {
		switch ( $surface ) {
			case 'api':
				return __( 'API', 'ffcertificate' );
			case 'frontend':
				return __( 'frontend', 'ffcertificate' );
			case 'admin':
				return __( 'wp-admin', 'ffcertificate' );
			default:
				return '';
		}
	}

	/**
	 * Pre-escaped badge markup for a cap's optional `surface` tag, or an empty
	 * string when the cap carries none. Shared by both capability editors (the
	 * per-user screen and the role editor) so the badge renders identically.
	 *
	 * @param array<string, mixed> $meta Cap metadata from {@see self::groups()}.
	 * @return string
	 */
	public static function surface_badge_html( array $meta ): string {
		if ( empty( $meta['surface'] ) ) {
			return '';
		}
		$surface = (string) $meta['surface'];
		$label   = self::surface_label( $surface );
		if ( '' === $label ) {
			return '';
		}
		return '<span class="ffc-cap-badge-surface ffc-cap-badge-surface--' . esc_attr( $surface ) . '">'
			. esc_html( $label ) . '</span>';
	}
}
