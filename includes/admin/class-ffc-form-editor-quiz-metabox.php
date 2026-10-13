<?php
/**
 * Form Editor Quiz Metabox Renderer
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
 * Form Editor Quiz Metabox Renderer.
 *
 * @since 3.2.0
 */
class FormEditorQuizMetabox {

	/**
	 * Section 6: Quiz / Evaluation Mode
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render( WP_Post $post ): void {
		$config = get_post_meta( $post->ID, '_ffc_form_config', true );
		if ( ! is_array( $config ) ) {
			$config = array();
		}

		$quiz_enabled       = ( $config['quiz_enabled'] ?? '0' ) === '1';
		$quiz_passing_score = $config['quiz_passing_score'] ?? '70';
		$quiz_max_attempts  = $config['quiz_max_attempts'] ?? '0';
		$quiz_show_score    = ( $config['quiz_show_score'] ?? '1' ) === '1';
		$quiz_show_correct  = ( $config['quiz_show_correct'] ?? '0' ) === '1';
		$quiz_reset_leave   = ( $config['quiz_reset_on_leave'] ?? '0' ) === '1';
		?>
		<table class="form-table">
			<tr>
				<th><label><?php esc_html_e( 'Enable Quiz Mode', 'ffcertificate' ); ?></label></th>
				<td>
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'    => 'ffc_config[quiz_enabled]',
							'id'      => 'ffc_quiz_enabled',
							'checked' => (bool) $quiz_enabled,
							'label'   => __( 'Turn this form into a quiz/evaluation', 'ffcertificate' ),
							'data'    => array( 'ffc-autosave-form-key' => 'quiz_enabled' ),
						)
					);
					?>
					<p class="description"><?php esc_html_e( 'When enabled, each field on the Fields tab can be scored: by its correct option(s), by points per option, when answered, or by a correct range. The form is scored on submission.', 'ffcertificate' ); ?></p>
				</td>
			</tr>
			<tr class="ffc-quiz-setting<?php echo $quiz_enabled ? '' : ' ffc-hidden'; ?>">
				<th><label><?php esc_html_e( 'Passing Score (%)', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="number" name="ffc_config[quiz_passing_score]" value="<?php echo esc_attr( $quiz_passing_score ); ?>" min="0" max="100" step="1" class="small-text" required>
					<p class="description"><?php esc_html_e( 'Minimum percentage to pass. Set 0 for no minimum.', 'ffcertificate' ); ?></p>
				</td>
			</tr>
			<tr class="ffc-quiz-setting<?php echo $quiz_enabled ? '' : ' ffc-hidden'; ?>">
				<th><label><?php esc_html_e( 'Max Attempts', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="number" name="ffc_config[quiz_max_attempts]" value="<?php echo esc_attr( $quiz_max_attempts ); ?>" min="0" step="1" class="small-text" required>
					<p class="description"><?php esc_html_e( 'Max retries per CPF/RF. 0 = unlimited.', 'ffcertificate' ); ?></p>
				</td>
			</tr>
			<tr class="ffc-quiz-setting<?php echo $quiz_enabled ? '' : ' ffc-hidden'; ?>">
				<th><label><?php esc_html_e( 'Display Options', 'ffcertificate' ); ?></label></th>
				<td>
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'    => 'ffc_config[quiz_show_score]',
							'checked' => (bool) $quiz_show_score,
							'label'   => __( 'Show score after submission', 'ffcertificate' ),
						)
					);
					?>
					<br>
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'    => 'ffc_config[quiz_show_correct]',
							'checked' => (bool) $quiz_show_correct,
							'label'   => __( 'Show which answers were correct/incorrect', 'ffcertificate' ),
						)
					);
					?>
				</td>
			</tr>
			<tr class="ffc-quiz-setting<?php echo $quiz_enabled ? '' : ' ffc-hidden'; ?>">
				<th><label><?php esc_html_e( 'Leaving the Window', 'ffcertificate' ); ?></label></th>
				<td>
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'    => 'ffc_config[quiz_reset_on_leave]',
							'checked' => (bool) $quiz_reset_leave,
							'label'   => __( 'Reset the quiz when the participant leaves the window', 'ffcertificate' ),
						)
					);
					?>
					<p class="description"><?php esc_html_e( 'Switching tabs or minimising the browser clears the answers; the participant is warned before starting and told why on return. Leaving does not count as an attempt, and how many times it happened is recorded on the submission. A deterrent, not a lock: another device or a browser without JavaScript is not stopped.', 'ffcertificate' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
		// Quiz toggle logic in ffc-admin.js.
	}
}
