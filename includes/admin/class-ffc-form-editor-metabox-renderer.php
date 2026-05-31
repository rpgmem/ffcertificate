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
 * @since   3.1.1
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
 * @since 3.1.1
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
	 * Render the "Geofence & Date/Time" metabox (both sections stacked).
	 *
	 * Back-compat entry point. The tabbed container splits this into the
	 * "Time" and "Geolocation" tabs via {@see render_box_time()} and
	 * {@see render_box_geolocation()}.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_box_geofence( WP_Post $post ): void {
		$this->geofence->render( $post );
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
	 * screen stays usable if the script fails to load.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_tabbed_container( WP_Post $post ): void {
		$tabs = self::tab_definitions();

		echo '<div class="ffc-form-tabs" data-ffc-form-tabs>';

		echo '<ul class="ffc-form-tabs__nav" role="tablist" aria-orientation="vertical">';
		$first = true;
		foreach ( $tabs as $tab ) {
			printf(
				'<li class="ffc-form-tabs__nav-item" role="presentation"><a href="#ffc-tab-%1$s" id="ffc-tabnav-%1$s" class="ffc-form-tabs__tab%2$s" role="tab" aria-controls="ffc-tabpanel-%1$s" aria-selected="%3$s" tabindex="%4$s"><span class="dashicons dashicons-%5$s" aria-hidden="true"></span><span class="ffc-form-tabs__label">%6$s</span></a></li>',
				esc_attr( $tab['key'] ),
				$first ? ' is-active' : '',
				$first ? 'true' : 'false',
				$first ? '0' : '-1',
				esc_attr( $tab['icon'] ),
				esc_html( $tab['label'] )
			);
			$first = false;
		}
		echo '</ul>';

		echo '<div class="ffc-form-tabs__panels">';
		$first = true;
		foreach ( $tabs as $tab ) {
			printf(
				'<section id="ffc-tabpanel-%1$s" class="ffc-form-tabs__panel%2$s" role="tabpanel" aria-labelledby="ffc-tabnav-%1$s" tabindex="0">',
				esc_attr( $tab['key'] ),
				$first ? ' is-active' : ''
			);
			printf(
				'<h2 class="ffc-form-tabs__panel-title"><span class="dashicons dashicons-%1$s" aria-hidden="true"></span>%2$s</h2>',
				esc_attr( $tab['icon'] ),
				esc_html( $tab['title'] )
			);
			$this->render_panel_body( $tab['key'], $post );
			echo '</section>';
			$first = false;
		}
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Definitions for the content tabs, in display order. Labels are
	 * intentionally terse (paired with a dashicon in the nav); the longer
	 * descriptive heading is rendered inside each panel as its title.
	 *
	 * @return array<int, array{key: string, icon: string, label: string, title: string}>
	 */
	private static function tab_definitions(): array {
		return array(
			array(
				'key'   => 'layout',
				'icon'  => 'media-document',
				'label' => __( 'Layout', 'ffcertificate' ),
				'title' => __( 'Certificate Layout', 'ffcertificate' ),
			),
			array(
				'key'   => 'builder',
				'icon'  => 'forms',
				'label' => __( 'Fields', 'ffcertificate' ),
				'title' => __( 'Form Builder (Fields)', 'ffcertificate' ),
			),
			array(
				'key'   => 'restriction',
				'icon'  => 'shield',
				'label' => __( 'Security', 'ffcertificate' ),
				'title' => __( 'Restriction & Security', 'ffcertificate' ),
			),
			array(
				'key'   => 'email',
				'icon'  => 'email',
				'label' => __( 'Email', 'ffcertificate' ),
				'title' => __( 'Email Configuration', 'ffcertificate' ),
			),
			array(
				'key'   => 'time',
				'icon'  => 'clock',
				'label' => __( 'Time', 'ffcertificate' ),
				'title' => __( 'Date & Time Restrictions', 'ffcertificate' ),
			),
			array(
				'key'   => 'geolocation',
				'icon'  => 'location-alt',
				'label' => __( 'Geolocation', 'ffcertificate' ),
				'title' => __( 'Geolocation Restrictions', 'ffcertificate' ),
			),
			array(
				'key'   => 'quiz',
				'icon'  => 'welcome-learn-more',
				'label' => __( 'Quiz', 'ffcertificate' ),
				'title' => __( 'Quiz / Evaluation Mode', 'ffcertificate' ),
			),
			array(
				'key'   => 'operator',
				'icon'  => 'groups',
				'label' => __( 'Operator', 'ffcertificate' ),
				'title' => __( 'Public Operator Access', 'ffcertificate' ),
			),
		);
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
		}
	}
}
