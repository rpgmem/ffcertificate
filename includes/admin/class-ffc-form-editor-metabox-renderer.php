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

	private FormEditorShortcodeMetabox $shortcode;
	private FormEditorLayoutMetabox $layout;
	private FormEditorBuilderMetabox $builder;
	private FormEditorRestrictionMetabox $restriction;
	private FormEditorEmailMetabox $email;
	private FormEditorGeofenceMetabox $geofence;
	private FormEditorQuizMetabox $quiz;
	private FormEditorPublicCsvDownloadMetabox $public_csv_download;
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

	public function render_shortcode_metabox( WP_Post $post ): void {
		$this->shortcode->render( $post );
	}

	public function render_box_layout( WP_Post $post ): void {
		$this->layout->render( $post );
	}

	public function render_box_builder( WP_Post $post ): void {
		$this->builder->render( $post );
	}

	public function render_box_restriction( WP_Post $post ): void {
		$this->restriction->render( $post );
	}

	public function render_box_email( WP_Post $post ): void {
		$this->email->render( $post );
	}

	public function render_box_geofence( WP_Post $post ): void {
		$this->geofence->render( $post );
	}

	public function render_box_quiz( WP_Post $post ): void {
		$this->quiz->render( $post );
	}

	public function render_box_public_csv_download( WP_Post $post ): void {
		$this->public_csv_download->render( $post );
	}

	public function render_box_device_limit( WP_Post $post ): void {
		$this->device_limit->render( $post );
	}

	/**
	 * @param int|string           $index Field index.
	 * @param array<string, mixed> $field Field data.
	 */
	public function render_field_row( $index, array $field ): void {
		$this->builder->render_field_row( $index, $field );
	}
}
