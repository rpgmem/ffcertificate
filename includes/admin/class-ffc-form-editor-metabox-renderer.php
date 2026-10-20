<?php
/**
 * Form Editor Metabox Renderer (facade)
 *
 * Handles rendering of all metaboxes for the Form Editor.
 * Extracted from FFC_Form_Editor class to follow Single Responsibility Principle.
 *
 * Since S3 of the god-object refactor (issue #141), this class is a thin
 * facade that delegates each metabox to its own per-metabox renderer class.
 * The public API is preserved so that existing call sites in
 * {@see FormEditor::add_custom_metaboxes()} continue to work unchanged.
 *
 * @since   3.2.0
 * @package FreeFormCertificate\Admin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders all metaboxes for the Form Editor screen.
 *
 * @since 3.2.0
 */
class FormEditorMetaboxRenderer {

	/**
	 * Renderer for the read-only "Shortcode" metabox.
	 *
	 * @var FormEditorShortcodeMetabox
	 */
	private FormEditorShortcodeMetabox $shortcode;

	/**
	 * Renderer for the "Certificate Layout" metabox.
	 *
	 * @var FormEditorLayoutMetabox
	 */
	private FormEditorLayoutMetabox $layout;

	/**
	 * Renderer for the "Form Builder (Fields)" metabox.
	 *
	 * @var FormEditorBuilderMetabox
	 */
	private FormEditorBuilderMetabox $builder;

	/**
	 * Renderer for the "Restriction & Security" metabox.
	 *
	 * @var FormEditorRestrictionMetabox
	 */
	private FormEditorRestrictionMetabox $restriction;

	/**
	 * Renderer for the "Email" metabox.
	 *
	 * @var FormEditorEmailMetabox
	 */
	private FormEditorEmailMetabox $email;

	/**
	 * Renderer for the "Geofence & Date/Time" metabox.
	 *
	 * @var FormEditorGeofenceMetabox
	 */
	private FormEditorGeofenceMetabox $geofence;

	/**
	 * Renderer for the "Quiz / Evaluation" metabox.
	 *
	 * @var FormEditorQuizMetabox
	 */
	private FormEditorQuizMetabox $quiz;

	/**
	 * Renderer for the "Public CSV Download" metabox.
	 *
	 * @var FormEditorPublicCsvDownloadMetabox
	 */
	private FormEditorPublicCsvDownloadMetabox $public_csv_download;

	/**
	 * Renderer for the "Device Fingerprint Limit" metabox.
	 *
	 * @var FormEditorDeviceLimitMetabox
	 */
	private FormEditorDeviceLimitMetabox $device_limit;

	/**
	 * Wire up the per-metabox renderers.
	 */
	public function __construct() {
		$this->shortcode           = new FormEditorShortcodeMetabox();
		$this->layout              = new FormEditorLayoutMetabox();
		$this->builder             = new FormEditorBuilderMetabox();
		$this->email               = new FormEditorEmailMetabox();
		$this->geofence            = new FormEditorGeofenceMetabox();
		$this->quiz                = new FormEditorQuizMetabox();
		$this->public_csv_download = new FormEditorPublicCsvDownloadMetabox();
		// `device_limit` is constructed before `restriction` because
		// the restriction metabox composes the device-limit toggle as
		// its 5th item via constructor injection.
		$this->device_limit = new FormEditorDeviceLimitMetabox();
		$this->restriction  = new FormEditorRestrictionMetabox( $this->device_limit );
	}

	/**
	 * Render the read-only "Shortcode" metabox.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_shortcode_metabox( WP_Post $post ): void {
		$this->shortcode->render( $post );
	}

	/**
	 * Render the "Certificate Layout" metabox.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_box_layout( WP_Post $post ): void {
		$this->layout->render( $post );
	}

	/**
	 * Render the "Form Builder (Fields)" metabox.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_box_builder( WP_Post $post ): void {
		$this->builder->render( $post );
	}

	/**
	 * Render the "Restriction & Security" metabox.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_box_restriction( WP_Post $post ): void {
		// Device Fingerprint composes inside the restriction metabox via
		// constructor injection — its master toggle becomes the 5th item
		// in the Form Restrictions list and its sub-options trail the
		// other conditional rows. Pre-#240 this was a separate metabox
		// (Section 8); see also {@see render_box_device_limit} which
		// stays as a documented no-op for any external consumer that
		// hooks the legacy entry point.
		$this->restriction->render( $post );
	}

	/**
	 * Render the "Email" metabox.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_box_email( WP_Post $post ): void {
		$this->email->render( $post );
	}

	/**
	 * Render the date/time-restriction section ("Time" tab).
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_box_time( WP_Post $post ): void {
		$this->geofence->render_time( $post );
	}

	/**
	 * Render the geolocation-restriction section ("Geolocation" tab).
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_box_geolocation( WP_Post $post ): void {
		$this->geofence->render_geolocation( $post );
	}

	/**
	 * Render the "Quiz / Evaluation" metabox.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_box_quiz( WP_Post $post ): void {
		$this->quiz->render( $post );
	}

	/**
	 * Render the "Public CSV Download" metabox.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_box_public_csv_download( WP_Post $post ): void {
		$this->public_csv_download->render( $post );
	}

	/**
	 * Render the "Device Fingerprint Limit" metabox.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_box_device_limit( WP_Post $post ): void {
		$this->device_limit->render( $post );
	}

	/**
	 * Render a single field row inside the Form Builder metabox.
	 *
	 * @param int|string           $index Field index.
	 * @param array<string, mixed> $field Field data.
	 */
	public function render_field_row( $index, array $field ): void {
		$this->builder->render_field_row( $index, $field );
	}

	/**
	 * Render the content metaboxes as one vertical-tabbed container.
	 *
	 * A WooCommerce "Product data"-style vertical nav on the left and one
	 * `<section role="tabpanel">` per tab on the right, each reusing the
	 * existing `render_box_*` method as its panel body. Every panel stays
	 * in the DOM (inactive ones are only `display:none` once JS marks the
	 * container ready), so the post-save path and the `document`-delegated
	 * form-meta autosave keep working unchanged. With JS disabled the
	 * panels degrade to a stacked layout — the pre-tabs behaviour — so the
	 * screen stays usable if the script fails to load. The markup is
	 * {@see AdminUI::render_vertical_tabs()}, shared with the personal
	 * calendar editor (#1761).
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_tabbed_container( WP_Post $post ): void {
		AdminUI::render_vertical_tabs(
			self::tab_definitions(),
			function ( string $key ) use ( $post ): void {
				$this->render_panel_body( $key, $post );
			}
		);
	}

	/**
	 * Definitions for the content tabs, in display order. Labels are
	 * intentionally terse (paired with an icon in the nav); the longer
	 * descriptive heading is rendered inside each panel as its title.
	 *
	 * `masters` lists the ids of the toggles that switch the tab's feature
	 * on: the nav draws an on/off dot that follows them, so whether a form
	 * uses quiz mode or a time window reads off the nav without opening each
	 * tab. Layout and Fields have no master — every form has both.
	 *
	 * `needs` names fields the tab cannot do without whatever its toggles say:
	 * while every one of them is empty the dot reads as pending (#1755). A
	 * toggle that needs a field says so itself, with `data-ffc-requires`.
	 *
	 * @return array<int, array{key: string, icon: string, label: string, title: string, masters: array<int, string>, needs: string}>
	 */
	private static function tab_definitions(): array {
		$tabs = array(
			array(
				'key'     => 'layout',
				'icon'    => 'ffc-icon-file',
				'label'   => __( 'Layout', 'ffcertificate' ),
				'title'   => __( 'Certificate Layout', 'ffcertificate' ),
				'masters' => array(),
			),
			array(
				'key'     => 'builder',
				'icon'    => 'ffc-icon-layout',
				'label'   => __( 'Fields', 'ffcertificate' ),
				'title'   => __( 'Form Builder (Fields)', 'ffcertificate' ),
				'masters' => array(),
			),
			array(
				'key'     => 'restriction',
				'icon'    => 'ffc-icon-shield',
				'label'   => __( 'Security', 'ffcertificate' ),
				'title'   => __( 'Restriction & Security', 'ffcertificate' ),
				'masters' => array( 'ffc_restriction_password', 'ffc_restriction_allowlist', 'ffc_restriction_denylist', 'ffc_restriction_ticket', 'ffc_device_limit_enabled' ),
			),
			array(
				'key'     => 'email',
				'icon'    => 'ffc-icon-email',
				'label'   => __( 'Email', 'ffcertificate' ),
				'title'   => __( 'Email Configuration', 'ffcertificate' ),
				'masters' => array( 'ffc_config_send_user_email', 'ffc_config_send_admin_email' ),
			),
			array(
				'key'     => 'time',
				'icon'    => 'ffc-icon-clock',
				'label'   => __( 'Time', 'ffcertificate' ),
				'title'   => __( 'Date & Time Restrictions', 'ffcertificate' ),
				'masters' => array( 'ffc_geofence_datetime_enabled', 'ffc_geofence_schedule_exception_enabled' ),
			),
			array(
				'key'     => 'geolocation',
				'icon'    => 'ffc-icon-map-pin',
				'label'   => __( 'Geolocation', 'ffcertificate' ),
				'title'   => __( 'Geolocation Restrictions', 'ffcertificate' ),
				'masters' => array( 'ffc_geofence_geo_enabled' ),
			),
			array(
				'key'     => 'quiz',
				'icon'    => 'ffc-icon-chart',
				'label'   => __( 'Quiz', 'ffcertificate' ),
				'title'   => __( 'Quiz / Evaluation Mode', 'ffcertificate' ),
				'masters' => array( 'ffc_quiz_enabled' ),
			),
			array(
				'key'     => 'operator',
				'icon'    => 'ffc-icon-users',
				'label'   => __( 'Operator', 'ffcertificate' ),
				'title'   => __( 'Public Operator Access', 'ffcertificate' ),
				'masters' => array( 'ffc_csv_public_enabled' ),
			),
			array(
				'key'     => 'bulk',
				'icon'    => 'ffc-icon-upload',
				'label'   => __( 'Bulk issue', 'ffcertificate' ),
				'title'   => __( 'Issue in bulk', 'ffcertificate' ),
				'masters' => array(),
			),
		);

		foreach ( $tabs as $i => $tab ) {
			$tabs[ $i ]['needs'] = 'time' === $tab['key'] ? self::schedule_needs() : '';
		}

		// The Bulk issue tab is listed last, and exists only for users who may import certificates (#1967).
		$last = array_key_last( $tabs );
		if ( ! current_user_can( \FreeFormCertificate\Admin\BulkIssuePage::CAP ) && 'bulk' === $tabs[ $last ]['key'] ) {
			array_pop( $tabs );
		}

		return $tabs;
	}

	/**
	 * The Event Schedule fields, when Settings → Certificates requires the
	 * `{{schedule}}` tag: a certificate printed without them leaves the tag
	 * empty, so the Time tab reads as pending until one is filled.
	 *
	 * @return string A selector list, or an empty string when the tag is not required.
	 */
	private static function schedule_needs(): string {
		if ( ! in_array( '{{schedule}}', \FreeFormCertificate\Settings\SettingsReader::required_certificate_tags(), true ) ) {
			return '';
		}
		return '[name="ffc_geofence[class_time_start]"], [name="ffc_geofence[class_time_end]"]';
	}

	/**
	 * Dispatch a tab key to its panel-body renderer.
	 *
	 * @param string  $key  Tab key from {@see tab_definitions()}.
	 * @param WP_Post $post Post being edited.
	 */
	private function render_panel_body( string $key, WP_Post $post ): void {
		switch ( $key ) {
			case 'layout':
				$this->render_box_layout( $post );
				break;
			case 'builder':
				$this->render_box_builder( $post );
				break;
			case 'restriction':
				$this->render_box_restriction( $post );
				break;
			case 'email':
				$this->render_box_email( $post );
				break;
			case 'time':
				$this->render_box_time( $post );
				break;
			case 'geolocation':
				$this->render_box_geolocation( $post );
				break;
			case 'quiz':
				$this->render_box_quiz( $post );
				break;
			case 'operator':
				$this->render_box_public_csv_download( $post );
				break;
			case 'bulk':
				\FreeFormCertificate\Admin\BulkIssuePage::render_panel( $post );
				break;
		}
	}
}
