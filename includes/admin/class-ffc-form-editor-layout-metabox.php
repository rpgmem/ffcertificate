<?php
/**
 * Form Editor Layout Metabox Renderer
 *
 * Extracted from FormEditorMetaboxRenderer as part of S3 god-object refactor.
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
 * Form Editor Layout Metabox Renderer.
 *
 * @since 3.2.0
 */
class FormEditorLayoutMetabox {

	/**
	 * Section 1: Certificate Layout Editor
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render( WP_Post $post ): void {
		$config   = get_post_meta( $post->ID, '_ffc_form_config', true );
		$layout   = isset( $config['pdf_layout'] ) ? $config['pdf_layout'] : '';
		$bg_image = isset( $config['bg_image'] ) ? $config['bg_image'] : '';
		$bg_color = \FreeFormCertificate\Core\FormBackground::normalize_color( (string) ( $config['bg_color'] ?? '' ) );

		// The Load button opens a modal built from the same DB-backed pool
		// (#865), localized as `ffc_ajax.templates`; it is drawn only when the
		// pool has something to offer. A hidden <select> once sat beside it
		// that no script read (#1625).
		$templates = CertTemplateReader::list_for_editor();

		// The form prints its own copy, or references a template (#2581).
		$linked_id   = \FreeFormCertificate\Core\CertificateTemplateLink::template_id( (int) $post->ID );
		$stored_id   = \FreeFormCertificate\Core\CertificateTemplateLink::stored_template_id( (int) $post->ID );
		$own_hidden  = $linked_id > 0;
		$link_values = \FreeFormCertificate\Core\CertificateTemplateLink::values( (int) $post->ID );

		wp_nonce_field( 'ffc_save_form_data', 'ffc_form_nonce' );
		?>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Layout source', 'ffcertificate' ); ?></th>
				<td>
					<?php
					AdminUI::render_segmented(
						array(
							'name'         => 'ffc_layout_source',
							'id'           => 'ffc_layout_source',
							'legend'       => __( 'Layout source', 'ffcertificate' ),
							'options'      => array(
								'own'      => __( 'Own layout', 'ffcertificate' ),
								'template' => __( 'Template', 'ffcertificate' ),
							),
							'value'        => $linked_id > 0 ? 'template' : 'own',
							'descriptions' => array(
								'own'      => __( 'The form keeps its own copy of the HTML; editing a template does not change it.', 'ffcertificate' ),
								'template' => __( 'The form prints a template from the pool; editing the template changes this form and its certificates.', 'ffcertificate' ),
							),
						)
					);
					if ( $stored_id > 0 && 0 === $linked_id ) {
						\FreeFormCertificate\Core\AdminNotice::callout(
							'warning',
							'<p>' . esc_html__( 'The template this form referenced is no longer available, so it prints its own layout. Choose another template or keep the own layout.', 'ffcertificate' ) . '</p>',
							array( 'classes' => array( 'inline' ) )
						);
					}
					$this->render_template_link( $linked_id, $link_values );
					?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Signers', 'ffcertificate' ); ?></th>
				<td><?php $this->render_signer_selects( (int) $post->ID ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'QR code', 'ffcertificate' ); ?></th>
				<td><?php $this->render_qr_mode( (int) $post->ID ); ?></td>
			</tr>
			<tr class="ffc-layout-own"<?php echo $own_hidden ? ' hidden' : ''; ?>>
				<th><label><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></label></th>
				<td>
					<div class="ffc-admin-flex-row ffc-flex-wrap">
						<div class="ffc-action-group">
							<input type="file" id="ffc_import_html_file" accept=".html,.txt" class="ffc-hidden">
							<?php
							\FreeFormCertificate\Admin\AdminUI::render_button(
								array(
									'label' => __( 'Import HTML', 'ffcertificate' ),
									'icon'  => 'upload',
									'id'    => 'ffc_btn_import_html',
								)
							);
							\FreeFormCertificate\Admin\AdminUI::render_button(
								array(
									'label' => __( 'Background Image', 'ffcertificate' ),
									'icon'  => 'image',
									'id'    => 'ffc_btn_media_lib',
								)
							);
							\FreeFormCertificate\Admin\AdminUI::render_button(
								array(
									'label'    => __( 'Insert Image', 'ffcertificate' ),
									'category' => 'create',
									'icon'     => 'image-plus',
									'id'       => 'ffc_btn_insert_image',
								)
							);
							\FreeFormCertificate\Admin\AdminUI::render_button(
								array(
									'label' => __( 'Preview', 'ffcertificate' ),
									'icon'  => 'eye',
									'id'    => 'ffc_btn_preview',
								)
							);
							\FreeFormCertificate\Admin\AdminUI::render_button(
								array(
									'label'    => __( 'Save as model', 'ffcertificate' ),
									'category' => 'create',
									'icon'     => 'template-save',
									'id'       => 'ffc_save_as_model_btn',
								)
							);
							?>
						</div>

						<?php if ( $templates ) : ?>
						<div class="ffc-template-loader">
							<?php
							\FreeFormCertificate\Admin\AdminUI::render_button(
								array(
									'label' => __( 'Load', 'ffcertificate' ),
									'icon'  => 'template-load',
									'id'    => 'ffc_load_template_btn',
								)
							);
							?>
						</div>
						<?php endif; ?>
					</div>
				</td>
			</tr>
			<tr class="ffc-layout-own"<?php echo $own_hidden ? ' hidden' : ''; ?>>
				<td colspan="2">
					<label class="ffc-block-label"><strong><?php esc_html_e( 'Certificate HTML Editor', 'ffcertificate' ); ?></strong></label>
					<?php
					// Certificates are regenerated from the current layout, so
					// an edit reaches the ones already issued (#2241).
					$issued = 'auto-draft' === $post->post_status ? 0 : ( new \FreeFormCertificate\Repositories\SubmissionRepository() )->countLiveByForm( (int) $post->ID );
					if ( $issued > 0 ) {
						\FreeFormCertificate\Core\AdminNotice::callout(
							'warning',
							'<p>' . esc_html(
								sprintf(
									/* translators: %s: number of certificates the form has issued */
									_n(
										'This form has %s issued certificate; layout changes apply to it the next time it is generated or verified.',
										'This form has %s issued certificates; layout changes apply to them the next time they are generated or verified.',
										$issued,
										'ffcertificate'
									),
									number_format_i18n( $issued )
								)
							) . '</p>',
							array( 'classes' => array( 'ffc-layout-issued-notice', 'inline' ) )
						);
					}
					// The fonts a template can name, or how to install one (#2569).
					\FreeFormCertificate\Core\CertificateFonts::render_notice();
					\FreeFormCertificate\Core\CertificateFonts::render_missing_notice( (string) $layout );
					// A copy of an earlier default model is offered the current one (#2577).
					DefaultLayoutVersions::render_notice( (string) $layout );
					?>
					<div class="ffc-code-editor-wrapper">
						<textarea name="ffc_config[pdf_layout]" id="ffc_pdf_layout" class="ffc-w100" rows="12"><?php echo esc_textarea( $layout ); ?></textarea>
					</div>
					<p class="description">
						<?php echo \FreeFormCertificate\Admin\AdminUI::get_required_tags_line(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built. ?>
					</p>

					<div class="ffc-input-group ffc-mt-xl">
						<label class="ffc-block-label"><strong><?php esc_html_e( 'Background Image URL:', 'ffcertificate' ); ?></strong></label>
						<input type="text" name="ffc_config[bg_image]" id="ffc_bg_image_input" value="<?php echo esc_url( $bg_image ); ?>" class="ffc-w100">
						<?php // Filled in by `ffc-admin-pdf.js` when the "Background Image" button picks an image; empty until then. ?>
						<div id="ffc_bg_image_preview"></div>
					</div>

					<div class="ffc-input-group ffc-mt-xl">
						<label class="ffc-block-label" for="ffc_bg_color_input"><strong><?php esc_html_e( 'Background Color:', 'ffcertificate' ); ?></strong></label>
						<input type="color" name="ffc_config[bg_color]" id="ffc_bg_color_input" value="<?php echo esc_attr( $bg_color ); ?>">
						<p class="description"><?php esc_html_e( 'Painted behind the background image, and alone when there is no image.', 'ffcertificate' ); ?></p>
					</div>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * The template picker, and the inputs for each template's declared fields
	 * (#2581). Every template's inputs are printed and only the chosen one's
	 * show, so switching needs no request; the save reads only the chosen one.
	 *
	 * @param int                   $linked_id The template the form references, or 0.
	 * @param array<string, string> $values    The values the form filled.
	 */
	private function render_template_link( int $linked_id, array $values ): void {
		$templates = CertTemplateReader::list_for_editor();
		$ids       = array_map( 'intval', array_column( $templates, 'id' ) );
		if ( $linked_id > 0 && ! in_array( $linked_id, $ids, true ) ) {
			// A hidden template the form already references stays choosable.
			$templates[] = array(
				'id'    => $linked_id,
				'label' => get_the_title( $linked_id ),
			);
		}
		?>
		<div class="ffc-layout-template ffc-mt-md"<?php echo 0 === $linked_id ? ' hidden' : ''; ?>>
			<?php if ( array() === $templates ) : ?>
				<p class="description"><?php esc_html_e( 'The template pool has no certificate template to reference.', 'ffcertificate' ); ?></p>
			<?php else : ?>
				<p>
					<label for="ffc_layout_template_id" class="ffc-block-label"><strong><?php esc_html_e( 'Template', 'ffcertificate' ); ?></strong></label>
					<select name="ffc_layout_template_id" id="ffc_layout_template_id">
						<option value="0"><?php esc_html_e( '— Choose a template —', 'ffcertificate' ); ?></option>
						<?php foreach ( $templates as $template ) : ?>
							<option value="<?php echo esc_attr( (string) $template['id'] ); ?>" <?php selected( (int) $template['id'], $linked_id ); ?>><?php echo esc_html( (string) $template['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<?php
				foreach ( $templates as $template ) {
					$this->render_template_panel( (int) $template['id'], (int) $template['id'] === $linked_id, $values );
				}
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The form's QR mode (#2639): Link only, Signed compact or Signed full.
	 *
	 * Each option's description says what the QR carries and, for the signed
	 * modes, what stands in the way on this form: no signing key yet, a
	 * template that prints the QR smaller than the mode needs, or a global QR
	 * design with a logo, which the signed modes draw without. The control
	 * shows only the chosen option's description, so the warnings follow the
	 * choice with no script.
	 *
	 * @param int $form_id Form post id.
	 */
	private function render_qr_mode( int $form_id ): void {
		$signer = \FreeFormCertificate\Submissions\CertificateSigner::class;
		$mode   = $signer::mode( $form_id );

		$descriptions = array(
			$signer::MODE_LINK    => __( 'The QR code carries the verification link only, as before. Checking the certificate needs the site.', 'ffcertificate' ),
			$signer::MODE_COMPACT => __( 'The QR code carries the link and a signature over the code, the issue date and a digest of the certificate. Without internet it confirms the code and the date.', 'ffcertificate' ),
			$signer::MODE_FULL    => __( 'The QR code carries the link and a signature over the code, the date, the name, the course and the workload. Without internet it shows them to compare with the paper; a name or course too long for the template\'s QR falls back to compact.', 'ffcertificate' ),
		);

		$notes = array();
		// Said before the save, on every option: a form-level edit flags
		// every signed certificate of the form, whatever the mode now (#2696).
		$signed_warning = FormResignNotice::editing_warning( $form_id );
		if ( '' !== $signed_warning ) {
			foreach ( array_keys( $descriptions ) as $option ) {
				$descriptions[ $option ] .= ' ' . $signed_warning;
			}
		}
		if ( ! \FreeFormCertificate\Core\SigningKeys::has_active() ) {
			$notes[] = __( 'No signing key exists yet: until one is generated under Settings → Certificates, this form prints the link only.', 'ffcertificate' );
		}
		if ( \FreeFormCertificate\Settings\SettingsReader::get_int( 'qr_design_logo_id', 0 ) > 0 ) {
			$notes[] = __( 'The global QR design has a logo, which the signed modes do not print: it would force a much larger code.', 'ffcertificate' );
		}
		$placement = $this->qr_placements( $form_id )[0] ?? null;
		$link      = null === $placement ? '' : \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( str_repeat( 'a', 32 ) );
		if ( null !== $placement ) {
			// The link mode draws the design's logo; the signed modes never do.
			$logo       = \FreeFormCertificate\Generators\QrDesign::applies_to( 'certificate' )
				&& \FreeFormCertificate\Settings\SettingsReader::get_int( 'qr_design_logo_id', 0 ) > 0;
			$level_note = $this->qr_level_note( $link, $placement['error_level'], $logo );
			if ( '' !== $level_note ) {
				$descriptions[ $signer::MODE_LINK ] .= ' ' . $level_note;
			}
		}
		foreach ( array( $signer::MODE_COMPACT, $signer::MODE_FULL ) as $signed ) {
			$size_note = $this->qr_size_note( $form_id, $signed );
			// The level is only worth saying of a code the layout can print.
			$level_note = '' === $size_note && null !== $placement
				? $this->qr_level_note( \FreeFormCertificate\Generators\SignedQrSize::sample( $signed, $link ), $placement['error_level'], false )
				: '';
			$extra      = array_filter( array_merge( array( $size_note, $level_note ), $notes ) );
			if ( array() !== $extra ) {
				$descriptions[ $signed ] .= ' ' . implode( ' ', $extra );
			}
		}

		AdminUI::render_segmented(
			array(
				'name'         => 'ffc_config[' . $signer::CONFIG_KEY . ']',
				'id'           => 'ffc_qr_mode',
				'legend'       => __( 'QR code', 'ffcertificate' ),
				'options'      => array(
					$signer::MODE_LINK    => __( 'Link only', 'ffcertificate' ),
					$signer::MODE_COMPACT => __( 'Signed, compact', 'ffcertificate' ),
					$signer::MODE_FULL    => __( 'Signed, full', 'ffcertificate' ),
				),
				'value'        => $mode,
				'descriptions' => $descriptions,
			)
		);
	}

	/**
	 * What the form's layout says about the QR size a signed mode needs, ''
	 * when every QR it prints is large enough.
	 *
	 * @param int    $form_id Form post id.
	 * @param string $mode    A signed mode.
	 */
	private function qr_size_note( int $form_id, string $mode ): string {
		$placements = $this->qr_placements( $form_id );
		if ( array() === $placements ) {
			return '' === trim( $this->qr_layout( $form_id ) ) ? '' : __( 'The layout prints no QR code, so the signature has nowhere to go.', 'ffcertificate' );
		}
		$size    = \FreeFormCertificate\Generators\SignedQrSize::class;
		$modules = $size::modules( $size::sample( $mode ) );
		foreach ( $placements as $placement ) {
			// A placement's size includes its own quiet zone.
			$needed = $size::px_for( $modules, $placement['margin'] );
			if ( $placement['size'] < $needed ) {
				return sprintf(
					/* translators: 1: QR size the layout prints, in px; 2: minimum size, in px */
					__( 'The layout prints the QR code at %1$d px; this mode needs at least %2$d px to be read reliably. Enlarge it in the template.', 'ffcertificate' ),
					$placement['size'],
					$needed
				);
			}
		}
		return '';
	}

	/**
	 * The layout the form prints: its template's, or its own.
	 *
	 * @param int $form_id Form post id.
	 */
	private function qr_layout( int $form_id ): string {
		$link        = \FreeFormCertificate\Core\CertificateTemplateLink::class;
		$template_id = $link::template_id( $form_id );
		if ( $template_id > 0 ) {
			return $link::layout( $template_id, $link::values( $form_id ) );
		}
		$config = get_post_meta( $form_id, '_ffc_form_config', true );
		return is_array( $config ) && is_string( $config['pdf_layout'] ?? null ) ? $config['pdf_layout'] : '';
	}

	/**
	 * The QR codes the form's layout prints, each with its size, margin and
	 * error level.
	 *
	 * @param int $form_id Form post id.
	 * @return list<array{size: int, margin: int, error_level: string}>
	 */
	private function qr_placements( int $form_id ): array {
		return ( new \FreeFormCertificate\Generators\QRCodeGenerator() )->placements( $this->qr_layout( $form_id ) );
	}

	/**
	 * What the editor says when a certificate's QR code is drawn at a level
	 * other than the one asked for (#2664), '' when it is the same.
	 *
	 * Generation raises the level where the grid has room and lowers it when
	 * the link does not fit ({@see QRCodeGenerator::plan()}); without this
	 * line both happen out of sight. The link measured has the length of the
	 * real one: this site's verification page and a token.
	 *
	 * @param string $link      The link the code carries.
	 * @param string $requested The level the layout asks for.
	 * @param bool   $logo      Whether the design's logo applies.
	 */
	private function qr_level_note( string $link, string $requested, bool $logo ): string {
		$plan = \FreeFormCertificate\Generators\QRCodeGenerator::plan( $link, $requested, $logo );
		if ( null === $plan['level'] ) {
			return __( 'The link does not fit in a QR code at any error correction: the certificate prints it as text instead.', 'ffcertificate' );
		}
		$notes = array();
		if ( $logo && ! $plan['logo'] ) {
			$notes[] = __( 'The logo is left out: the link does not fit at error correction H, which the logo needs.', 'ffcertificate' );
		}
		$asked = $plan['logo'] ? 'H' : $requested;
		$order = array_flip( array( 'L', 'M', 'Q', 'H' ) );
		if ( ( $order[ $plan['level'] ] ?? 0 ) > ( $order[ $asked ] ?? 0 ) ) {
			/* translators: 1: error correction level printed, 2: level asked for (L, M, Q or H) */
			$notes[] = sprintf( __( 'The QR code is printed at error correction %1$s, above the %2$s asked for: the link leaves room, so it survives more damage at the same size.', 'ffcertificate' ), $plan['level'], $asked );
		} elseif ( ( $order[ $plan['level'] ] ?? 0 ) < ( $order[ $asked ] ?? 0 ) ) {
			/* translators: 1: error correction level printed, 2: level asked for (L, M, Q or H) */
			$notes[] = sprintf( __( 'The QR code is printed at error correction %1$s, below the %2$s asked for: the link does not fit at %2$s.', 'ffcertificate' ), $plan['level'], $asked );
		}
		return implode( ' ', $notes );
	}

	/**
	 * The signers the form prints (#2582, #2620), one select per position:
	 * the active signers, the position's own one kept even when it was
	 * deactivated, and a signer whose account has no RF any more shown
	 * disabled with the reason.
	 *
	 * @param int $form_id Form post id.
	 */
	private function render_signer_selects( int $form_id ): void {
		$signers   = \FreeFormCertificate\Core\CertificateSigners::all();
		$positions = \FreeFormCertificate\Core\CertificateSigners::positions_for_form( $form_id );
		?>
		<div class="ffc-signer-selects">
			<?php
			// One field per position, named as the save handler reads them.
			$fields = array(
				1 => array( 'id' => 'ffc_signer_id' ),
				2 => array( 'id' => 'ffc_signer_id_2' ),
				3 => array( 'id' => 'ffc_signer_id_3' ),
			);
			foreach ( $positions as $position => $current ) {
				$field = $fields[ $position ]['id'];
				?>
				<p>
					<label for="<?php echo esc_attr( $field ); ?>">
						<?php
						/* translators: %d: signer position, 1 to 3 */
						echo esc_html( sprintf( __( 'Signer %d', 'ffcertificate' ), $position ) );
						?>
					</label><br>
					<?php $this->render_signer_select( $field, $signers, null !== $current ? $current['id'] : 0 ); ?>
				</p>
				<?php
			}
			?>
		</div>
		<p class="description" id="ffc_signer_id_help">
			<?php esc_html_e( 'Signer 1 prints as {{signer1:name}}, {{signer1:rf}}, {{signer1:role}} and {{signer1:signature}}, and signers 2 and 3 as {{signer2:…}} and {{signer3:…}}. {{signer:…}} is the same as {{signer1:…}}. Name and RF come from the person\'s WordPress profile when the certificate is generated; the RF prints in full on the certificate only.', 'ffcertificate' ); ?>
		</p>
		<?php
	}

	/**
	 * One signer select.
	 *
	 * @param string                                                                                                                                                                             $field   Field name and id.
	 * @param array<int, array{id: int, user_id: int, role: string, signature_id: int, active: bool, kept_name: string, kept_rf: string, kept_email_hash: string, added_at: int, added_by: int}> $signers Every signer.
	 * @param int                                                                                                                                                                                $chosen  The position's signer id, or 0.
	 */
	private function render_signer_select( string $field, array $signers, int $chosen ): void {
		?>
		<select name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( $field ); ?>" class="ffc-signer-select" aria-describedby="ffc_signer_id_help">
			<option value="0"><?php esc_html_e( '— No signer —', 'ffcertificate' ); ?></option>
			<?php
			foreach ( $signers as $signer ) {
				if ( ! $signer['active'] && $signer['id'] !== $chosen ) {
					continue;
				}
				$name  = \FreeFormCertificate\Core\CertificateSigners::name( $signer );
				$label = '' !== $signer['role'] ? $name . ' — ' . $signer['role'] : $name;
				$no_rf = $signer['user_id'] > 0 && '' === \FreeFormCertificate\Core\CertificateSigners::user_rf( $signer['user_id'] );
				if ( $no_rf ) {
					/* translators: %s: signer name and role */
					$label = sprintf( __( '%s (no RF on the profile)', 'ffcertificate' ), $label );
				} elseif ( ! $signer['active'] ) {
					/* translators: %s: signer name and role */
					$label = sprintf( __( '%s (inactive)', 'ffcertificate' ), $label );
				}
				printf(
					'<option value="%1$s"%2$s%3$s>%4$s</option>',
					esc_attr( (string) $signer['id'] ),
					selected( $signer['id'], $chosen, false ),
					disabled( $no_rf && $signer['id'] !== $chosen, true, false ),
					esc_html( $label )
				);
			}
			?>
		</select>
		<?php
	}

	/**
	 * One template's panel: who else uses it, its links and its fields.
	 *
	 * @param int                   $template_id Template post id.
	 * @param bool                  $shown       Whether it is the chosen one.
	 * @param array<string, string> $values      The values the form filled.
	 */
	private function render_template_panel( int $template_id, bool $shown, array $values ): void {
		$fields = \FreeFormCertificate\Core\CertificateTemplateLink::fields( $template_id );
		$forms  = count( \FreeFormCertificate\Core\CertificateTemplateLink::forms_using( $template_id ) );
		$edit   = get_edit_post_link( $template_id, 'raw' );
		?>
		<div class="ffc-layout-template-panel" data-template-id="<?php echo esc_attr( (string) $template_id ); ?>"<?php echo $shown ? '' : ' hidden'; ?>>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: number of forms */
						_n( 'Used by %s form.', 'Used by %s forms.', $forms, 'ffcertificate' ),
						number_format_i18n( $forms )
					)
				);
				?>
			</p>
			<div class="ffc-action-group">
				<?php
				AdminUI::render_button(
					array(
						'label' => __( 'Preview', 'ffcertificate' ),
						'icon'  => 'eye',
						'class' => 'ffc-btn-preview-template',
					)
				);
				if ( is_string( $edit ) && '' !== $edit && current_user_can( 'edit_post', $template_id ) ) {
					AdminUI::render_button(
						array(
							'label' => __( 'Edit template', 'ffcertificate' ),
							'icon'  => 'edit',
							'href'  => $edit,
						)
					);
				}
				?>
			</div>
			<?php if ( array() === $fields ) : ?>
				<p class="description"><?php esc_html_e( 'This template declares no fields for the form to fill.', 'ffcertificate' ); ?></p>
			<?php else : ?>
				<h4><?php esc_html_e( 'Template fields', 'ffcertificate' ); ?></h4>
				<?php
				foreach ( $fields as $field ) {
					$id    = 'ffc_template_value_' . $template_id . '_' . $field['key'];
					$name  = 'ffc_template_values[' . $template_id . '][' . $field['key'] . ']';
					$value = $values[ $field['key'] ] ?? '';
					?>
					<p class="ffc-input-group">
						<label for="<?php echo esc_attr( $id ); ?>" class="ffc-block-label">
							<strong><?php echo esc_html( $field['label'] ); ?></strong>
							<?php if ( $field['required'] ) : ?>
								<?php esc_html_e( '(required)', 'ffcertificate' ); ?>
							<?php endif; ?>
							<code>{{model:<?php echo esc_html( $field['key'] ); ?>}}</code>
						</label>
						<?php if ( 'textarea' === $field['type'] ) : ?>
							<textarea name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $id ); ?>" rows="3" class="ffc-w100" placeholder="<?php echo esc_attr( $field['default'] ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
						<?php else : ?>
							<input type="<?php echo 'date' === $field['type'] ? 'date' : 'text'; ?>" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $value ); ?>" class="<?php echo 'date' === $field['type'] ? '' : 'ffc-w100'; ?>" placeholder="<?php echo esc_attr( $field['default'] ); ?>">
						<?php endif; ?>
					</p>
					<?php
				}
				?>
				<p class="description"><?php esc_html_e( 'An empty field prints the template’s default, shown in grey.', 'ffcertificate' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
