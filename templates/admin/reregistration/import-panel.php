<?php
/**
 * Template: Reregistration admin — CSV import panel (#1214, sprint 5).
 *
 * **Outside the campaign's `<form>`, and only on a campaign that exists** — the
 * same two reasons the Invitations box beside it gives. A file input and three
 * buttons inside that form would submit the campaign on the first click, and
 * an import needs an `audience_id` that is one of the campaign's, which a
 * campaign being created does not have yet.
 *
 * The locals come from {@see ReregistrationAdminRenderer::render_form()}:
 * `$id`, and `$import_audiences` — the campaign's own audiences, never all of
 * them, because `ingest_job()` refuses an audience the campaign does not reach
 * and offering one would be an error the operator could only discover by
 * uploading.
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.26.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="postbox ffc-rereg-import-box">
	<h2 class="hndle"><span><?php esc_html_e( 'Import answers from a spreadsheet', 'ffcertificate' ); ?></span></h2>
	<div class="inside">
		<p class="description">
			<?php esc_html_e( 'Loads this campaign\'s answers for people of one audience. The file is checked in full before anything is written: if any row fails, nothing is imported.', 'ffcertificate' ); ?>
		</p>

		<?php if ( array() === $import_audiences ) : ?>
			<p class="ffc-rereg-import-empty">
				<?php esc_html_e( 'This campaign reaches no audience yet. Add one above and save before importing.', 'ffcertificate' ); ?>
			</p>
		<?php else : ?>
			<div class="ffc-rereg-import-controls">
				<p>
					<label for="ffc-rereg-import-audience"><?php esc_html_e( 'Audience', 'ffcertificate' ); ?></label><br>
					<select id="ffc-rereg-import-audience" class="ffc-rereg-import-audience">
						<?php foreach ( $import_audiences as $audience_id => $audience_name ) : ?>
							<option value="<?php echo esc_attr( (string) $audience_id ); ?>"><?php echo esc_html( (string) $audience_name ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="description">
						<?php esc_html_e( 'One import covers one audience — the columns are that audience\'s fields.', 'ffcertificate' ); ?>
					</span>
				</p>

				<p>
					<label for="ffc-rereg-import-file"><?php esc_html_e( 'CSV file', 'ffcertificate' ); ?></label><br>
					<input type="file" id="ffc-rereg-import-file" class="ffc-rereg-import-file" accept=".csv,text/csv,text/plain">
					<span class="description">
						<?php esc_html_e( 'The header names the fields, by key or by label. A column that matches nothing is ignored and reported.', 'ffcertificate' ); ?>
					</span>
				</p>

				<p>
					<button type="button" class="button button-secondary ffc-rereg-import-check" id="ffc-rereg-import-check" data-rereg-id="<?php echo esc_attr( (string) $id ); ?>">
						<?php esc_html_e( 'Check file', 'ffcertificate' ); ?>
					</button>
					<button type="button" class="button button-primary ffc-rereg-import-apply" id="ffc-rereg-import-apply" disabled>
						<?php esc_html_e( 'Import', 'ffcertificate' ); ?>
					</button>
					<span class="ffc-rereg-import-status" aria-live="polite"></span>
				</p>
			</div>

			<div class="ffc-rereg-import-report" hidden>
				<ul class="ffc-rereg-import-counts"></ul>
				<div class="ffc-rereg-import-problems"></div>
			</div>
		<?php endif; ?>
	</div>
</div>
