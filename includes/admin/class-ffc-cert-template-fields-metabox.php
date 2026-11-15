<?php
/**
 * CertTemplateFieldsMetabox
 *
 * The fields a certificate template declares for the forms that reference it,
 * and what an edit of the template reaches (#2581).
 *
 * @package FreeFormCertificate\Admin
 * @since   6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\CertificateTemplateLink;
use FreeFormCertificate\Core\TemplateFields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template edit screen: the Fields box and the impact notice.
 */
final class CertTemplateFieldsMetabox {

	/** Script handle of the field rows. */
	private const HANDLE = 'ffc-cert-template-fields';

	/** Nonce action of the Fields box. */
	private const NONCE = 'ffc_save_cert_template_fields';

	/**
	 * Hook the box, its save, its script and the impact notice.
	 */
	public function __construct() {
		$pt = CertTemplateCpt::POST_TYPE;
		// After the body box, which registers on the same hook at the default priority.
		add_action( 'add_meta_boxes_' . $pt, array( $this, 'add_metabox' ), 20 );
		add_action( 'save_post_' . $pt, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		// Under the title, where a change about to be saved is still undone by leaving.
		add_action( 'edit_form_after_title', array( $this, 'render_impact_notice' ) );
	}

	/**
	 * Whether a template can declare fields: an editable certificate template.
	 *
	 * @param int $post_id Template post id.
	 */
	private static function declares_fields( int $post_id ): bool {
		return CertTemplateCpt::KIND_CERTIFICATE === CertTemplateReader::get_kind( $post_id )
			&& ! CertTemplateReader::is_default( $post_id );
	}

	/**
	 * Register the Fields box on certificate templates.
	 *
	 * @param \WP_Post $post Template being edited.
	 */
	public function add_metabox( \WP_Post $post ): void {
		if ( ! self::declares_fields( (int) $post->ID ) ) {
			return;
		}
		add_meta_box(
			'ffc_cert_template_fields',
			__( 'Template fields', 'ffcertificate' ),
			array( $this, 'render' ),
			CertTemplateCpt::POST_TYPE,
			'normal',
			'default'
		);
	}

	/**
	 * Say how many forms and issued certificates an edit of this template
	 * reaches. Certificates are regenerated from the current layout (#2241).
	 *
	 * @param \WP_Post $post Post being edited.
	 */
	public function render_impact_notice( \WP_Post $post ): void {
		if ( CertTemplateCpt::POST_TYPE !== $post->post_type ) {
			return;
		}
		$forms = CertificateTemplateLink::forms_using( (int) $post->ID );
		if ( array() === $forms ) {
			return;
		}
		$repository = new \FreeFormCertificate\Repositories\SubmissionRepository();
		$issued     = 0;
		foreach ( $forms as $form_id ) {
			$issued += $repository->countLiveByForm( $form_id );
		}
		\FreeFormCertificate\Core\AdminNotice::callout(
			'warning',
			'<p>' . esc_html(
				sprintf(
					/* translators: 1: number of forms, 2: number of issued certificates */
					_n(
						'%1$s form prints this template, with %2$s issued certificates. A change you save here applies to them the next time they are generated or verified.',
						'%1$s forms print this template, with %2$s issued certificates. A change you save here applies to them the next time they are generated or verified.',
						count( $forms ),
						'ffcertificate'
					),
					number_format_i18n( count( $forms ) ),
					number_format_i18n( $issued )
				)
			) . '</p>',
			array( 'classes' => array( 'ffc-template-impact-notice', 'inline' ) )
		);
	}

	/**
	 * The field rows, and an empty row the script copies.
	 *
	 * @param \WP_Post $post Template being edited.
	 */
	public function render( \WP_Post $post ): void {
		$fields = CertificateTemplateLink::fields( (int) $post->ID );
		wp_nonce_field( self::NONCE, 'ffc_cert_template_fields_nonce' );
		?>
		<p class="description">
			<?php esc_html_e( 'Each form that references this template fills these fields on its Layout tab. Print one in the HTML as {{model:key}}; a date also takes {{model:key:format=d/m/Y}}.', 'ffcertificate' ); ?>
		</p>
		<table class="widefat striped ffc-template-fields-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Key', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Label', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Required', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Default', 'ffcertificate' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></span></th>
				</tr>
			</thead>
			<tbody class="ffc-template-fields-rows">
				<?php
				foreach ( $fields as $index => $field ) {
					self::render_row( (string) $index, $field );
				}
				?>
			</tbody>
		</table>
		<template id="ffc-template-field-row">
			<?php
			self::render_row(
				'__i__',
				array(
					'key'      => '',
					'label'    => '',
					'type'     => 'text',
					'required' => false,
					'default'  => '',
				)
			);
			?>
		</template>
		<p>
			<?php
			AdminUI::render_button(
				array(
					'label'    => __( 'Add field', 'ffcertificate' ),
					'category' => 'create',
					'icon'     => 'plus',
					'class'    => 'ffc-template-field-add',
				)
			);
			?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Removing a field leaves the values forms filled stored and unused. A required field the forms have not filled is reported when each form is saved.', 'ffcertificate' ); ?>
		</p>
		<?php
	}

	/**
	 * One field row.
	 *
	 * @param string                                                                           $index Row index, or `__i__` in the empty row.
	 * @param array{key: string, label: string, type: string, required: bool, default: string} $field Field.
	 */
	private static function render_row( string $index, array $field ): void {
		$name  = 'ffc_template_fields[' . $index . ']';
		$types = array(
			'text'     => __( 'Short text', 'ffcertificate' ),
			'textarea' => __( 'Long text', 'ffcertificate' ),
			'date'     => __( 'Date', 'ffcertificate' ),
		);
		?>
		<tr class="ffc-template-field-row">
			<td><input type="text" name="<?php echo esc_attr( $name ); ?>[key]" value="<?php echo esc_attr( $field['key'] ); ?>" pattern="[a-z0-9_]+" aria-label="<?php esc_attr_e( 'Key', 'ffcertificate' ); ?>" class="ffc-template-field-key"></td>
			<td><input type="text" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $field['label'] ); ?>" aria-label="<?php esc_attr_e( 'Label', 'ffcertificate' ); ?>"></td>
			<td>
				<select name="<?php echo esc_attr( $name ); ?>[type]" aria-label="<?php esc_attr_e( 'Type', 'ffcertificate' ); ?>">
					<?php foreach ( $types as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $field['type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<?php
				AdminUI::render_toggle(
					array(
						'name'       => $name . '[required]',
						'id'         => 'ffc_template_field_required_' . $index,
						'checked'    => $field['required'],
						'aria_label' => __( 'Required', 'ffcertificate' ),
					)
				);
				?>
			</td>
			<td><input type="text" name="<?php echo esc_attr( $name ); ?>[default]" value="<?php echo esc_attr( $field['default'] ); ?>" aria-label="<?php esc_attr_e( 'Default', 'ffcertificate' ); ?>"></td>
			<td>
				<?php
				AdminUI::render_button(
					array(
						'label'     => __( 'Remove field', 'ffcertificate' ),
						'category'  => 'danger',
						'icon'      => 'cross',
						'icon_only' => true,
						'class'     => 'ffc-template-field-remove',
					)
				);
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Store the declaration.
	 *
	 * @param int      $post_id Template post id.
	 * @param \WP_Post $post    Template post.
	 */
	public function save( int $post_id, \WP_Post $post ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || CertTemplateCpt::POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( ! wp_verify_nonce( \FreeFormCertificate\Core\RequestInput::get_post_string( 'ffc_cert_template_fields_nonce' ), self::NONCE ) ) {
			return;
		}
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_forms' ) || ! self::declares_fields( $post_id ) ) {
			return;
		}
		$rows = \FreeFormCertificate\Core\RequestInput::get_post_raw_array( 'ffc_template_fields' );
		unset( $rows['__i__'] );
		update_post_meta( $post_id, CertificateTemplateLink::META_FIELDS, TemplateFields::sanitize( array_values( $rows ) ) );
	}

	/**
	 * The row script, on the edit screen of a template that declares fields.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen || CertTemplateCpt::POST_TYPE !== $screen->post_type ) {
			return;
		}
		$suffix = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_script(
			self::HANDLE,
			FFC_PLUGIN_URL . "assets/js/ffc-cert-template-fields{$suffix}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
	}
}
