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
			<?php esc_html_e( 'Lets a trusted operator without a WordPress login interact with this form via the [ffc_csv_download] shortcode. Three sub-features ride on the same hash credential: downloading the submissions CSV, opening the form ahead of its scheduled start (Start Form Early), and pushing the close time later (Postpone Close). Formerly named "Public CSV Download".', 'ffcertificate' ); ?>
		</p>

		<input type="hidden" name="ffc_csv_public[present]" value="1">
		<table class="form-table ffc-csv-public-table">
			<tr>
				<th scope="row">
					<label for="ffc_csv_public_enabled">
						<?php esc_html_e( 'Enable Public Operator Access', 'ffcertificate' ); ?>
					</label>
				</th>
				<td>
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'    => 'ffc_csv_public[enabled]',
							'id'      => 'ffc_csv_public_enabled',
							'checked' => '1' === (string) $enabled,
							'label'   => __( 'Allow operators with the hash to download the CSV and trigger Start Form Early / Postpone Close.', 'ffcertificate' ),
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
		</table>

		<h3 class="ffc-section-subtitle"><?php esc_html_e( 'CSV Download', 'ffcertificate' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Configure the public CSV download that an operator can fetch once the form has ended.', 'ffcertificate' ); ?>
		</p>
		<div class="ffc-collapsed-target<?php echo $sub_disabled ? ' ffc-collapsed' : ''; ?>"
			data-ffc-master="ffc_csv_public_enabled"
			aria-hidden="<?php echo $sub_disabled ? 'true' : 'false'; ?>">
		<table class="form-table ffc-csv-public-table">

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
							value="<?php echo esc_attr( (string) $limit ); ?>">
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
							<input type="checkbox" name="ffc_csv_public[regenerate_hash]" value="1">
							<?php esc_html_e( 'Regenerate hash on save (invalidates the current link).', 'ffcertificate' ); ?>
						</label>
					</p>
					<p>
						<label>
							<input type="checkbox" name="ffc_csv_public[reset_counter]" value="1">
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
					<select name="ffc_csv_public[cpf_mode]" id="ffc_csv_public_cpf_mode">
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
			// Whitelist row is always rendered, then hidden via JS unless
			// the select equals 'whitelist'. Pre-#238 we only rendered this
			// row server-side when the persisted mode was 'whitelist',
			// requiring the admin to save the form first to even see it
			// (Sprint 3 / #238).
			$cpf_whitelist_collapsed = ( 'whitelist' !== $cpf_mode );
			?>
			<tr class="ffc-csv-public-sub ffc-collapsed-target<?php echo $cpf_whitelist_collapsed ? ' ffc-collapsed' : ''; ?>"
				data-ffc-master="ffc_csv_public_cpf_mode"
				data-ffc-master-value="whitelist"
				aria-hidden="<?php echo $cpf_whitelist_collapsed ? 'true' : 'false'; ?>">
				<th scope="row">
					<label for="ffc_csv_public_cpf_whitelist"><?php esc_html_e( 'CPF whitelist', 'ffcertificate' ); ?></label>
				</th>
				<td>
					<textarea name="ffc_csv_public[cpf_whitelist]"
						id="ffc_csv_public_cpf_whitelist"
						rows="4"
						class="large-text code"
						placeholder="000.000.000-00&#10;111.111.111-11"><?php echo esc_textarea( $cpf_whitelist ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'One CPF per line. Only used when the mode above is set to "Whitelist". Formatting is ignored — only digits matter.', 'ffcertificate' ); ?>
					</p>
				</td>
			</tr>

			<?php
			$ffc_audit_summary = \FreeFormCertificate\Frontend\PublicCsvDownload::get_audit_log_summary( $post->ID );
			?>
			<tr class="ffc-csv-public-sub">
				<th scope="row">
					<?php esc_html_e( 'Download audit log', 'ffcertificate' ); ?>
				</th>
				<td>
					<?php if ( $ffc_audit_summary['count'] > 0 || $ffc_audit_summary['download_success'] > 0 ) : ?>
						<div class="ffc-csv-audit-summary" role="group" aria-label="<?php esc_attr_e( 'Audit summary', 'ffcertificate' ); ?>">
							<div class="ffc-csv-audit-card is-success">
								<span class="ffc-csv-audit-card-label"><?php esc_html_e( 'Successful accesses', 'ffcertificate' ); ?></span>
								<span class="ffc-csv-audit-card-value"><?php echo esc_html( (string) $ffc_audit_summary['access_success'] ); ?></span>
								<span class="ffc-csv-audit-card-hint"><?php esc_html_e( 'CPF + CAPTCHA validated.', 'ffcertificate' ); ?></span>
							</div>
							<div class="ffc-csv-audit-card is-success">
								<span class="ffc-csv-audit-card-label"><?php esc_html_e( 'Successful downloads', 'ffcertificate' ); ?></span>
								<span class="ffc-csv-audit-card-value"><?php echo esc_html( (string) $ffc_audit_summary['download_success'] ); ?></span>
								<span class="ffc-csv-audit-card-hint"><?php esc_html_e( 'CSV files actually delivered.', 'ffcertificate' ); ?></span>
							</div>
							<div class="ffc-csv-audit-card is-fail">
								<span class="ffc-csv-audit-card-label"><?php esc_html_e( 'Failed accesses', 'ffcertificate' ); ?></span>
								<span class="ffc-csv-audit-card-value"><?php echo esc_html( (string) $ffc_audit_summary['failed_access'] ); ?></span>
								<span class="ffc-csv-audit-card-hint"><?php esc_html_e( 'Wrong CPF + wrong CAPTCHA + other errors.', 'ffcertificate' ); ?></span>
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
		</div><!-- /.ffc-collapsed-target (CSV Download subsection) -->

		<?php
		// ──────────────────────────────────────────────────────────────.
		// Start Form Early URL — same hash, different action surface.
		// ──────────────────────────────────────────────────────────────.
		$this->render_start_form_early_block( $post, $enabled, $hash );

		// ──────────────────────────────────────────────────────────────.
		// Postergar fim — sibling action, same hash, same modal pattern.
		// ──────────────────────────────────────────────────────────────.
		$this->render_extend_end_block( $post, $enabled, $hash );
		?>
		<?php
	}

	/**
	 * Render the "Start Form Early" status sub-block of the Public
	 * Operator Access metabox.
	 *
	 * Pure status: there is no separate URL for early-open — the
	 * "Start Form Now" button appears on the existing public CSV
	 * download page (the URL surfaced above) whenever the form is
	 * eligible. This block mirrors EarlyOpenAction::is_eligible() so
	 * admins can see why the button is / isn't visible to operators.
	 *
	 * @since 6.5.6
	 * @param WP_Post $post    The form post.
	 * @param string  $enabled CSV-public toggle ('1' or '').
	 * @param string  $hash    The form's CSV-public hash, if any.
	 */
	private function render_start_form_early_block( WP_Post $post, string $enabled, string $hash ): void {
		$start_ts   = \FreeFormCertificate\Security\Geofence::get_form_start_timestamp( $post->ID );
		$end_ts     = \FreeFormCertificate\Security\Geofence::get_form_end_timestamp( $post->ID );
		$now        = time();
		$enabled_ok = '1' === $enabled && '' !== $hash;
		// Per-form opt-out for the early-open action. Defaults to '1'
		// (enabled) on existing forms so the feature doesn't regress for
		// installs already using it via the CSV-public toggle.
		$start_early_raw     = get_post_meta( $post->ID, '_ffc_csv_public_start_early_enabled', true );
		$start_early_enabled = '' === (string) $start_early_raw ? '1' : (string) $start_early_raw;
		$sub_disabled        = ! $enabled_ok;

		// Status string mirrors the eligibility branches in EarlyOpenAction.
		if ( ! $enabled_ok ) {
			$status_label = __( 'Enable Public Operator Access above to expose the early-start button to operators.', 'ffcertificate' );
			$status_kind  = 'warning';
		} elseif ( '1' !== $start_early_enabled ) {
			$status_label = __( 'Early-start is disabled for this form — toggle it on to expose the button.', 'ffcertificate' );
			$status_kind  = 'info';
		} elseif ( null === $start_ts ) {
			$status_label = __( 'Set a start date in the Geolocation & Date/Time metabox to enable this action.', 'ffcertificate' );
			$status_kind  = 'warning';
		} elseif ( null !== $end_ts && $end_ts <= $now ) {
			$status_label = __( 'This form has already ended — early-start no longer applies.', 'ffcertificate' );
			$status_kind  = 'info';
		} elseif ( $start_ts <= $now ) {
			$status_label = __( 'This form has already started — early-start no longer applies.', 'ffcertificate' );
			$status_kind  = 'info';
		} else {
			// Same-day guard mirrors EarlyOpenAction::is_eligible() —
			// the early-open surface only exists when the configured
			// start date is "today" in the site timezone (the action
			// only rewrites time_start, never date_start).
			$geofence_config = get_post_meta( $post->ID, '_ffc_geofence_config', true );
			$date_start      = is_array( $geofence_config ) ? (string) ( $geofence_config['date_start'] ?? '' ) : '';
			$today           = current_time( 'Y-m-d' );
			if ( $date_start !== $today ) {
				$status_label = __( 'Available only on the scheduled start day — the early-start button surfaces to operators on the day the form is configured to begin.', 'ffcertificate' );
				$status_kind  = 'info';
			} else {
				$status_label = __( 'Available — operators visiting the public download page (URL above) will see a "Start Form Now" button. A confirmation modal protects against accidental clicks.', 'ffcertificate' );
				$status_kind  = 'success';
			}
		}
		?>
		<h3 class="ffc-section-subtitle"><?php esc_html_e( 'Start Form Early', 'ffcertificate' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'When eligible, the "Start Form Now" button appears on the public download page above and lets a trusted operator flip the form\'s start time to "now" — no separate URL is generated. Regenerating the hash above invalidates this action along with the CSV download.', 'ffcertificate' ); ?>
		</p>
		<table class="form-table ffc-csv-public-table">
			<tr>
				<th scope="row">
					<label for="ffc_csv_public_start_early_enabled"><?php esc_html_e( 'Start Form Early', 'ffcertificate' ); ?></label>
				</th>
				<td>
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'     => 'ffc_csv_public[start_early_enabled]',
							'id'       => 'ffc_csv_public_start_early_enabled',
							'checked'  => '1' === $start_early_enabled,
							'disabled' => $sub_disabled,
							'label'    => __( 'Allow operators to start the form before the scheduled time.', 'ffcertificate' ),
						)
					);
					?>
					<p class="description">
						<?php esc_html_e( 'Independent of the master toggle: you can keep Public Operator Access on (CSV download remains available) while disabling the early-start action — handy for forms where the public page is read-only.', 'ffcertificate' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th>
				<td>
					<span class="ffc-status-pill ffc-status-pill--<?php echo esc_attr( $status_kind ); ?>">
						<?php echo esc_html( $status_label ); ?>
					</span>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render the "Postergar fim" status sub-block of the Public Operator
	 * Access metabox.
	 *
	 * Sibling of {@see render_start_form_early_block()} for the close
	 * boundary. The operator can postpone `time_end` once per form,
	 * within the same day as the configured `date_end`. Defaults to
	 * opt-IN (conservative): admin must explicitly toggle on.
	 *
	 * @since 6.5.12
	 * @param WP_Post $post    The form post.
	 * @param string  $enabled CSV-public toggle ('1' or '').
	 * @param string  $hash    The form's CSV-public hash, if any.
	 */
	private function render_extend_end_block( WP_Post $post, string $enabled, string $hash ): void {
		$start_ts      = \FreeFormCertificate\Security\Geofence::get_form_start_timestamp( $post->ID );
		$end_ts        = \FreeFormCertificate\Security\Geofence::get_form_end_timestamp( $post->ID );
		$now           = time();
		$enabled_ok    = '1' === $enabled && '' !== $hash;
		$extend_raw    = (string) get_post_meta( $post->ID, '_ffc_csv_public_extend_end_enabled', true );
		$extend_on     = '1' === $extend_raw;
		$postponed_at  = (string) get_post_meta( $post->ID, \FreeFormCertificate\Frontend\ExtendEndAction::META_POSTPONED_AT, true );
		$postponed_frm = (string) get_post_meta( $post->ID, \FreeFormCertificate\Frontend\ExtendEndAction::META_POSTPONED_FROM, true );
		$sub_disabled  = ! $enabled_ok;

		// Status string mirrors ExtendEndAction::is_eligible() branches.
		if ( ! $enabled_ok ) {
			$status_label = __( 'Enable Public Operator Access above to expose the postpone-close button to operators.', 'ffcertificate' );
			$status_kind  = 'warning';
		} elseif ( ! $extend_on ) {
			$status_label = __( 'Postponing the close is disabled for this form — toggle it on to expose the button.', 'ffcertificate' );
			$status_kind  = 'info';
		} elseif ( null === $end_ts ) {
			$status_label = __( 'Set an end date in the Geolocation & Date/Time metabox to enable this action.', 'ffcertificate' );
			$status_kind  = 'warning';
		} elseif ( '' !== $postponed_at ) {
			$status_label = sprintf(
				/* translators: %s: original time_end value (HH:MM). */
				__( 'Already postponed once for this form (was %s). Edit time_end manually below if you need to extend further.', 'ffcertificate' ),
				'' !== $postponed_frm ? $postponed_frm : '—'
			);
			$status_kind = 'info';
		} elseif ( $end_ts <= $now ) {
			$status_label = __( 'This form has already ended — postponing no longer applies.', 'ffcertificate' );
			$status_kind  = 'info';
		} elseif ( null !== $start_ts && $start_ts > $now ) {
			$status_label = __( 'Available once the form is open — the button surfaces between the start and the scheduled close.', 'ffcertificate' );
			$status_kind  = 'info';
		} else {
			$geofence_config = get_post_meta( $post->ID, '_ffc_geofence_config', true );
			$date_end        = is_array( $geofence_config ) ? (string) ( $geofence_config['date_end'] ?? '' ) : '';
			$today           = current_time( 'Y-m-d' );
			if ( $date_end !== $today ) {
				$status_label = __( 'Available only on the scheduled close day — the button surfaces to operators on the day the form is configured to end.', 'ffcertificate' );
				$status_kind  = 'info';
			} else {
				$status_label = __( 'Available — operators visiting the public download page will see a "Postpone close" button. One-shot per form.', 'ffcertificate' );
				$status_kind  = 'success';
			}
		}
		?>
		<h3 class="ffc-section-subtitle"><?php esc_html_e( 'Postpone Close', 'ffcertificate' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'When eligible, a "Postpone close" button appears on the public download page above and lets a trusted operator push the form\'s close time later within the same day. Strictly one-shot per form — admin can edit time_end manually afterwards if needed.', 'ffcertificate' ); ?>
		</p>
		<table class="form-table ffc-csv-public-table">
			<tr>
				<th scope="row">
					<label for="ffc_csv_public_extend_end_enabled"><?php esc_html_e( 'Postpone Close', 'ffcertificate' ); ?></label>
				</th>
				<td>
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'     => 'ffc_csv_public[extend_end_enabled]',
							'id'       => 'ffc_csv_public_extend_end_enabled',
							'checked'  => $extend_on,
							'disabled' => $sub_disabled,
							'label'    => __( 'Allow operators to postpone the close time once.', 'ffcertificate' ),
						)
					);
					?>
					<p class="description">
						<?php esc_html_e( 'Off by default — turn on consciously since extending a public-facing window is destructive-ish.', 'ffcertificate' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th>
				<td>
					<span class="ffc-status-pill ffc-status-pill--<?php echo esc_attr( $status_kind ); ?>">
						<?php echo esc_html( $status_label ); ?>
					</span>
				</td>
			</tr>
		</table>
		<?php
	}
}
