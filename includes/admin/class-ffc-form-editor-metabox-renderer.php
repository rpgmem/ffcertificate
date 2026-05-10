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
		$this->restriction         = new FormEditorRestrictionMetabox();
		$this->email               = new FormEditorEmailMetabox();
		$this->geofence            = new FormEditorGeofenceMetabox();
		$this->quiz                = new FormEditorQuizMetabox();
		$this->public_csv_download = new FormEditorPublicCsvDownloadMetabox();
		$this->device_limit        = new FormEditorDeviceLimitMetabox();
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
	 * Render the "Geofence & Date/Time" metabox.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_box_geofence( WP_Post $post ): void {
		$this->geofence->render( $post );
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
}
