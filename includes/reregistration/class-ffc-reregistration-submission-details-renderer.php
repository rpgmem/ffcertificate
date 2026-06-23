<?php
/**
 * Reregistration Submission Details Renderer
 *
 * Builds the HTML for the "View Details" modal body shown on the
 * reregistration submissions list. Pure rendering: receives a submission row,
 * field definitions, and an already-decrypted values map; returns HTML.
 *
 * @package FreeFormCertificate\Reregistration
 * @since 4.12.14  Extracted from ReregistrationAdmin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reregistration Submission Details Renderer.
 *
 * @phpstan-import-type ReregistrationSubmissionRow from ReregistrationSubmissionReader
 * @phpstan-import-type CustomFieldRow from CustomFieldReader
 */
final class ReregistrationSubmissionDetailsRenderer {

	/**
	 * Build the HTML for the submission details modal body.
	 *
	 * Groups fields by field_group (preserving their declared order), renders
	 * a <fieldset> per group and a label/value pair per field. Sensitive
	 * values arrive already decrypted.
	 *
	 * @param object               $submission       Submission row.
	 * @param array<int, object>   $fields           Field definitions for the audience(s).
	 * @param array<string, mixed> $decrypted_values field_key => plaintext value map.
	 * @phpstan-param ReregistrationSubmissionRow $submission
	 * @phpstan-param list<CustomFieldRow>        $fields
	 * @return string Escaped HTML block.
	 */
	public function build_submission_details_html( object $submission, array $fields, array $decrypted_values ): string {
		$group_labels = array();
		if ( class_exists( '\FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' ) ) {
			$group_labels = ReregistrationStandardFieldsSeeder::get_group_labels();
		}

		// Group fields by field_group, preserving order of first appearance.
		$grouped = array();
		foreach ( $fields as $field ) {
			$group = isset( $field->field_group ) ? (string) $field->field_group : '';
			if ( ! isset( $grouped[ $group ] ) ) {
				$grouped[ $group ] = array();
			}
			$grouped[ $group ][] = $field;
		}

		$submitted_at = '';
		if ( ! empty( $submission->submitted_at ) ) {
			// `submitted_at` is unix UTC int since 6.6.0 (#249 sub-escopo b).
			$submitted_at = \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $submission->submitted_at );
		}

		ob_start();
		?>
		<div class="ffc-submission-details">
			<div class="ffc-submission-meta">
				<p>
					<strong><?php esc_html_e( 'Status:', 'ffcertificate' ); ?></strong>
					<span class="ffc-status-badge ffc-status-<?php echo esc_attr( $submission->status ); ?>">
						<?php echo esc_html( ReregistrationSubmissionReader::get_status_label( $submission->status ) ); ?>
					</span>
				</p>
				<?php if ( $submitted_at ) : ?>
					<p><strong><?php esc_html_e( 'Submitted:', 'ffcertificate' ); ?></strong> <?php echo esc_html( $submitted_at ); ?></p>
				<?php endif; ?>
			</div>

			<?php foreach ( $grouped as $group_key => $group_fields ) : ?>
				<?php
				$legend = '' === $group_key
					? __( 'Other Fields', 'ffcertificate' )
					: ( $group_labels[ $group_key ] ?? ucfirst( str_replace( '_', ' ', $group_key ) ) );
				?>
				<fieldset class="ffc-details-fieldset">
					<legend><?php echo esc_html( $legend ); ?></legend>
					<dl class="ffc-details-list">
						<?php foreach ( $group_fields as $field ) : ?>
							<?php
							$key           = (string) $field->field_key;
							$raw_value     = $decrypted_values[ $key ] ?? '';
							$formatted     = FichaGenerator::format_field_value( $field, $raw_value );
							$is_html_field = ( (string) 'working_hours' === $field->field_type );
							?>
							<dt><?php echo esc_html( (string) $field->field_label ); ?></dt>
							<dd>
								<?php if ( '' === $formatted ) : ?>
									<span class="ffc-details-empty">&mdash;</span>
								<?php elseif ( $is_html_field ) : ?>
									<?php echo wp_kses_post( $formatted ); ?>
								<?php else : ?>
									<?php echo esc_html( $formatted ); ?>
								<?php endif; ?>
							</dd>
						<?php endforeach; ?>
					</dl>
				</fieldset>
			<?php endforeach; ?>

			<?php if ( ! empty( $submission->notes ) ) : ?>
				<fieldset class="ffc-details-fieldset">
					<legend><?php esc_html_e( 'Review Notes', 'ffcertificate' ); ?></legend>
					<p><?php echo esc_html( (string) $submission->notes ); ?></p>
				</fieldset>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
