<?php
/**
 * Form Editor Public CSV Download Metabox Renderer
 *
 * Extracted from FormEditorMetaboxRenderer as part of S3 god-object refactor.
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
 * Form Editor Public CSV Download Metabox Renderer.
 *
 * @since 3.1.1
 */
class FormEditorPublicCsvDownloadMetabox {

	/**
	 * Section 7: Public CSV Download (shortcode-powered frontend export).
	 *
	 * Lets the admin enable a public download link guarded by a revocable
	 * hash + usage quota. The actual download endpoint is handled by
	 * {@see \FreeFormCertificate\Frontend\PublicCsvDownload}.
	 *
	 * @since 5.1.0
	 * @param WP_Post $post The post object.
	 */
	public function render( WP_Post $post ): void {
		$enabled = (string) get_post_meta( $post->ID, '_ffc_csv_public_enabled', true );
		$hash    = (string) get_post_meta( $post->ID, '_ffc_csv_public_hash', true );
		$limit   = (int) get_post_meta( $post->ID, '_ffc_csv_public_limit', true );
		$count   = (int) get_post_meta( $post->ID, '_ffc_csv_public_count', true );

		if ( $limit <= 0 ) {
			$settings      = get_option( 'ffc_settings', array() );
			$default_limit = ( is_array( $settings ) && isset( $settings['public_csv_default_limit'] ) )
				? (int) $settings['public_csv_default_limit']
				: 1;
			$limit         = $default_limit > 0 ? $default_limit : 1;
		}

		// Check that a geofence end date is configured — required for this feature.
		$geofence_config = get_post_meta( $post->ID, '_ffc_geofence_config', true );
		$date_end        = ( is_array( $geofence_config ) && ! empty( $geofence_config['date_end'] ) )
			? (string) $geofence_config['date_end']
			: '';

		// Sub-fields are disabled (read-only-ish) when the master toggle is off.
		// JS mirrors this on change so the UI updates without saving. The save
		// handler also short-circuits when enabled=0 to preserve persisted
		// values that the disabled inputs would not have submitted.
		$sub_disabled = ( '1' !== $enabled );

		// Nonce is emitted by render_box_layout(), which always renders before this metabox.
		?>
		<p class="description">
			<?php esc_html_e( 'Allow a person without WordPress access to download the submissions CSV for this form by providing the Form ID + a hash on a public page that contains the [ffc_csv_download] shortcode.', 'ffcertificate' ); ?>
		</p>

		<input type="hidden" name="ffc_csv_public[present]" value="1">
		<table class="form-table ffc-csv-public-table<?php echo $sub_disabled ? ' ffc-csv-public-disabled' : ''; ?>">
			<tr>
				<th scope="row">
					<label for="ffc_csv_public_enabled">
						<?php esc_html_e( 'Enable Public Download', 'ffcertificate' ); ?>
					</label>
				</th>
				<td>
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'    => 'ffc_csv_public[enabled]',
							'id'      => 'ffc_csv_public_enabled',
							'checked' => '1' === (string) $enabled,
							'label'   => __( 'Allow visitors with the hash to download this form\'s CSV.', 'ffcertificate' ),
						)
					);
					?>

					<?php if ( '' === $date_end ) : ?>
						<p class="description ffc-warning-text">
							<strong><?php esc_html_e( 'Warning:', 'ffcertificate' ); ?></strong>
							<?php esc_html_e( 'This form has no end date configured in the "Geolocation & Date/Time Restrictions" metabox. You must set an end date before public downloads will work — downloads are only released after the form has ended.', 'ffcertificate' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>

			<tr class="ffc-csv-public-sub">
				<th scope="row">
					<label for="ffc_csv_public_limit">
						<?php esc_html_e( 'Download Limit', 'ffcertificate' ); ?>
					</label>
				</th>
				<td>
					<input type="number"
							name="ffc_csv_public[limit]"
							id="ffc_csv_public_limit"
							min="1"
							step="1"
							class="small-text"
							value="<?php echo esc_attr( (string) $limit ); ?>"<?php disabled( $sub_disabled ); ?>>
					<p class="description">
						<?php
						printf(
							/* translators: 1: current count, 2: limit */
							esc_html__( 'Maximum number of CSV downloads allowed via the public page. Current usage: %1$d of %2$d.', 'ffcertificate' ),
							(int) $count,
							(int) $limit
						);
						?>
					</p>
				</td>
			</tr>

			<tr class="ffc-csv-public-sub">
				<th scope="row">
					<label for="ffc_csv_public_hash">
						<?php esc_html_e( 'Access Hash', 'ffcertificate' ); ?>
					</label>
				</th>
				<td>
					<input type="text"
							id="ffc_csv_public_hash"
							value="<?php echo esc_attr( $hash ); ?>"
							readonly
							class="large-text code"
							onclick="this.select();">
					<p class="ffc-mt-10">
						<label>
							<input type="checkbox" name="ffc_csv_public[regenerate_hash]" value="1"<?php disabled( $sub_disabled ); ?>>
							<?php esc_html_e( 'Regenerate hash on save (invalidates the current link).', 'ffcertificate' ); ?>
						</label>
					</p>
					<p>
						<label>
							<input type="checkbox" name="ffc_csv_public[reset_counter]" value="1"<?php disabled( $sub_disabled ); ?>>
							<?php esc_html_e( 'Reset the download counter to zero on save.', 'ffcertificate' ); ?>
						</label>
					</p>
					<p class="description">
						<?php esc_html_e( 'Share the Form ID and this hash with the person who should download the CSV. If no hash exists yet, one will be generated automatically when you enable the feature and save.', 'ffcertificate' ); ?>
					</p>

					<?php if ( '1' === $enabled && '' !== $hash ) : ?>
						<?php
						$ffc_settings          = get_option( 'ffc_settings', array() );
						$csv_download_page_url = $ffc_settings['csv_download_page_url'] ?? '';
						$ffc_query_string      = 'form_id=' . $post->ID . '&hash=' . $hash;
						?>
						<?php if ( '' !== $csv_download_page_url ) : ?>
							<p class="description">
								<?php esc_html_e( 'Download link:', 'ffcertificate' ); ?>
								<br>
								<code><?php echo esc_html( $csv_download_page_url . ( str_contains( $csv_download_page_url, '?' ) ? '&' : '?' ) . $ffc_query_string ); ?></code>
							</p>
						<?php else : ?>
							<p class="description">
								<?php esc_html_e( 'Example pre-filled link (append this as a query string to the page that contains the [ffc_csv_download] shortcode):', 'ffcertificate' ); ?>
								<br>
								<code>?<?php echo esc_html( $ffc_query_string ); ?></code>
							</p>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>

			<?php
			$cpf_mode      = (string) get_post_meta( $post->ID, '_ffc_csv_public_cpf_mode', true );
			$cpf_whitelist = (string) get_post_meta( $post->ID, '_ffc_csv_public_cpf_whitelist', true );
			if ( '' === $cpf_mode ) {
				$cpf_mode = 'none';
			}
			?>
			<tr class="ffc-csv-public-sub">
				<th scope="row">
					<label for="ffc_csv_public_cpf_mode"><?php esc_html_e( 'Require CPF for download', 'ffcertificate' ); ?></label>
				</th>
				<td>
					<select name="ffc_csv_public[cpf_mode]" id="ffc_csv_public_cpf_mode"<?php disabled( $sub_disabled ); ?>>
						<option value="none" <?php selected( $cpf_mode, 'none' ); ?>><?php esc_html_e( 'No — only Form ID + Hash', 'ffcertificate' ); ?></option>
						<option value="audit" <?php selected( $cpf_mode, 'audit' ); ?>><?php esc_html_e( 'Audit — require CPF, but do not match against any list', 'ffcertificate' ); ?></option>
						<option value="participants" <?php selected( $cpf_mode, 'participants' ); ?>><?php esc_html_e( 'Participants — CPF must match a submission', 'ffcertificate' ); ?></option>
						<option value="owner" <?php selected( $cpf_mode, 'owner' ); ?>><?php esc_html_e( 'Owner — CPF must match the form author', 'ffcertificate' ); ?></option>
						<option value="whitelist" <?php selected( $cpf_mode, 'whitelist' ); ?>><?php esc_html_e( 'Whitelist — CPF must be in the list below', 'ffcertificate' ); ?></option>
					</select>
					<p class="description">
						<?php esc_html_e( 'When set to anything other than "No", every download attempt (with success/failure flag and the CPF encrypted at rest) is recorded in an audit log on this form, capped at the most recent 100 attempts. Use the "Download audit log (CSV)" button below to export.', 'ffcertificate' ); ?>
					</p>
				</td>
			</tr>

			<?php
			// Whitelist textarea is rendered only when the persisted mode is
			// 'whitelist'. Switching the dropdown alone does not reveal it —
			// the user must save the form first. This keeps the textarea
			// invisible while the mode is in flux and matches the prompt
			// "só deve aparecer se for selecionada essa opção e salvar".
			if ( 'whitelist' === $cpf_mode ) :
				?>
			<tr class="ffc-csv-public-sub">
				<th scope="row">
					<label for="ffc_csv_public_cpf_whitelist"><?php esc_html_e( 'CPF whitelist', 'ffcertificate' ); ?></label>
				</th>
				<td>
					<textarea name="ffc_csv_public[cpf_whitelist]"
						id="ffc_csv_public_cpf_whitelist"
						rows="4"
						class="large-text code"
						placeholder="000.000.000-00&#10;111.111.111-11"<?php disabled( $sub_disabled ); ?>><?php echo esc_textarea( $cpf_whitelist ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'One CPF per line. Only used when the mode above is set to "Whitelist". Formatting is ignored — only digits matter.', 'ffcertificate' ); ?>
					</p>
				</td>
			</tr>
			<?php elseif ( '1' === $enabled ) : ?>
			<tr class="ffc-csv-public-sub">
				<th scope="row"></th>
				<td>
					<p class="description">
						<em><?php esc_html_e( 'Tip: select "Whitelist" above and save the form to reveal the CPF whitelist textarea.', 'ffcertificate' ); ?></em>
					</p>
				</td>
			</tr>
			<?php endif; ?>

			<?php
			$ffc_audit_summary = \FreeFormCertificate\Frontend\PublicCsvDownload::get_audit_log_summary( $post->ID );
			?>
			<tr class="ffc-csv-public-sub">
				<th scope="row">
					<?php esc_html_e( 'Download audit log', 'ffcertificate' ); ?>
				</th>
				<td>
					<?php if ( $ffc_audit_summary['count'] > 0 ) : ?>
						<div class="ffc-csv-audit-summary" role="group" aria-label="<?php esc_attr_e( 'Download attempts breakdown', 'ffcertificate' ); ?>">
							<div class="ffc-csv-audit-card">
								<span class="ffc-csv-audit-card-label"><?php esc_html_e( 'Total attempts', 'ffcertificate' ); ?></span>
								<span class="ffc-csv-audit-card-value"><?php echo esc_html( (string) $ffc_audit_summary['count'] ); ?></span>
							</div>
							<div class="ffc-csv-audit-card is-success">
								<span class="ffc-csv-audit-card-label"><?php esc_html_e( 'Successful', 'ffcertificate' ); ?></span>
								<span class="ffc-csv-audit-card-value"><?php echo esc_html( (string) $ffc_audit_summary['success'] ); ?></span>
							</div>
							<div class="ffc-csv-audit-card is-fail">
								<span class="ffc-csv-audit-card-label"><?php esc_html_e( 'Failed', 'ffcertificate' ); ?></span>
								<span class="ffc-csv-audit-card-value"><?php echo esc_html( (string) $ffc_audit_summary['fail'] ); ?></span>
							</div>
						</div>
						<?php if ( $ffc_audit_summary['count'] >= \FreeFormCertificate\Frontend\PublicCsvDownload::DOWNLOAD_LOG_MAX ) : ?>
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: maximum entries kept in the log */
										__( 'Limit of %d entries reached — older entries roll off.', 'ffcertificate' ),
										\FreeFormCertificate\Frontend\PublicCsvDownload::DOWNLOAD_LOG_MAX
									)
								);
								?>
							</p>
						<?php endif; ?>
					<?php endif; ?>
					<?php if ( null !== $ffc_audit_summary['url'] ) : ?>
						<p>
							<a href="<?php echo esc_url( $ffc_audit_summary['url'] ); ?>"
								class="button"
								download>
								<?php esc_html_e( 'Download audit log (CSV)', 'ffcertificate' ); ?>
							</a>
						</p>
						<p class="description">
							<?php
							if ( class_exists( '\FreeFormCertificate\Core\Encryption' )
								&& \FreeFormCertificate\Core\Encryption::is_configured() ) {
								esc_html_e( 'CPFs are stored encrypted at-rest and decrypted on the fly when you download the CSV. Each entry includes timestamp, IP, gate mode, CPF, and outcome.', 'ffcertificate' );
							} else {
								esc_html_e( 'Encryption is not configured on this site. The CPF column will be empty for entries written while encryption is off.', 'ffcertificate' );
							}
							?>
						</p>
					<?php else : ?>
						<p class="description">
							<?php esc_html_e( 'No download attempts have been logged yet.', 'ffcertificate' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<?php
		// ──────────────────────────────────────────────────────────────.
		// Start Form Early URL — same hash, different action surface.
		// ──────────────────────────────────────────────────────────────.
		$this->render_start_form_early_block( $post, $enabled, $hash );
		?>
		<?php
	}

	/**
	 * Render the "Start Form Early" sub-block of the Public Operator
	 * Access metabox.
	 *
	 * Reuses the form's CSV-public hash as the credential — operators
	 * who hit the public CSV download page with this URL before the
	 * scheduled start can click "Start Form Now" to flip the form's
	 * `date_start` / `time_start` to "now". After the form's natural
	 * start (or end), the block reports that the action is no longer
	 * available so the admin understands why no URL is shown.
	 *
	 * @since 6.5.6
	 * @param WP_Post $post    The form post.
	 * @param string  $enabled CSV-public toggle ('1' or '').
	 * @param string  $hash    The form's CSV-public hash, if any.
	 */
	private function render_start_form_early_block( WP_Post $post, string $enabled, string $hash ): void {
		// Resolve the public download landing page so we can build a
		// usable absolute URL for copy/QR — settings UI lets the admin
		// configure this; fall back to home_url if unset.
		$settings    = get_option( 'ffc_settings', array() );
		$landing_url = ( is_array( $settings ) && ! empty( $settings['csv_download_page_url'] ) )
			? (string) $settings['csv_download_page_url']
			: home_url( '/' );

		$start_ts   = \FreeFormCertificate\Security\Geofence::get_form_start_timestamp( $post->ID );
		$end_ts     = \FreeFormCertificate\Security\Geofence::get_form_end_timestamp( $post->ID );
		$now        = current_time( 'timestamp' );
		$enabled_ok = '1' === $enabled && '' !== $hash;

		// Status string mirrors the eligibility branches in EarlyOpenAction.
		if ( ! $enabled_ok ) {
			$status_label = __( 'Enable Public Download above to generate a URL.', 'ffcertificate' );
			$status_kind  = 'warning';
			$show_url     = false;
		} elseif ( null === $start_ts ) {
			$status_label = __( 'Set a start date in the Geolocation & Date/Time metabox to enable this action.', 'ffcertificate' );
			$status_kind  = 'warning';
			$show_url     = false;
		} elseif ( null !== $end_ts && $end_ts <= $now ) {
			$status_label = __( 'This form has already ended — early-start no longer applies.', 'ffcertificate' );
			$status_kind  = 'info';
			$show_url     = false;
		} elseif ( $start_ts <= $now ) {
			$status_label = __( 'This form has already started — early-start no longer applies.', 'ffcertificate' );
			$status_kind  = 'info';
			$show_url     = false;
		} else {
			$status_label = __( 'Available — share this URL with a trusted operator (a confirmation modal protects against accidental clicks).', 'ffcertificate' );
			$status_kind  = 'success';
			$show_url     = true;
		}

		$absolute_url = '';
		if ( $show_url ) {
			$absolute_url = add_query_arg(
				array(
					'form_id' => (int) $post->ID,
					'hash'    => $hash,
				),
				$landing_url
			);
		}
		?>
		<h3 class="ffc-section-subtitle"><?php esc_html_e( 'Start Form Early URL', 'ffcertificate' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Operators with this URL can open the form ahead of the scheduled start time. Uses the same hash as the CSV download — regenerating the hash above invalidates both URLs at once. Cancel button is emphasised on the public page to prevent accidental triggers.', 'ffcertificate' ); ?>
		</p>
		<table class="form-table ffc-csv-public-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th>
				<td>
					<span class="ffc-status-pill ffc-status-pill--<?php echo esc_attr( $status_kind ); ?>">
						<?php echo esc_html( $status_label ); ?>
					</span>
				</td>
			</tr>
			<?php if ( $show_url ) : ?>
				<tr>
					<th scope="row">
						<label for="ffc_csv_public_start_form_url"><?php esc_html_e( 'Public URL', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<input type="text"
							id="ffc_csv_public_start_form_url"
							class="regular-text code ffc-csv-public-url"
							value="<?php echo esc_attr( $absolute_url ); ?>"
							readonly
							onclick="this.select();">
						<button type="button"
							class="button ffc-csv-public-copy-url"
							data-target="#ffc_csv_public_start_form_url">
							<?php esc_html_e( 'Copy URL', 'ffcertificate' ); ?>
						</button>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
		// Copy-URL handler — tiny, self-contained, only emitted when
		// at least one URL is present. Uses the navigator.clipboard
		// API with a textarea fallback for older browsers.
		if ( $show_url ) :
			?>
			<script>
			(function () {
				document.querySelectorAll('.ffc-csv-public-copy-url').forEach(function (btn) {
					if (btn.dataset.ffcCopyBound) { return; }
					btn.dataset.ffcCopyBound = '1';
					btn.addEventListener('click', function (e) {
						e.preventDefault();
						var sel = btn.getAttribute('data-target');
						var input = document.querySelector(sel);
						if (!input) { return; }
						var url = input.value;
						var done = function () {
							var orig = btn.textContent;
							btn.textContent = '✓ ' + (window.ffcOperatorAccessL10n && window.ffcOperatorAccessL10n.copied || 'Copied');
							setTimeout(function () { btn.textContent = orig; }, 1800);
						};
						if (navigator.clipboard) {
							navigator.clipboard.writeText(url).then(done, function () {
								input.select(); document.execCommand('copy'); done();
							});
						} else {
							input.select(); document.execCommand('copy'); done();
						}
					});
				});
			})();
			</script>
			<?php
		endif;
	}
}
