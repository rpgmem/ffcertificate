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
	 * Section 7: Public Operator Access (formerly Public CSV Download).
	 *
	 * Lets the admin enable a public hash-gated surface that gates 3
	 * sibling sub-features:
	 *   - CSV Download (`_ffc_csv_public_download_enabled`, default '1')
	 *   - Start Form Early (`_ffc_csv_public_start_early_enabled`, default '0')
	 *   - Postpone Close (`_ffc_csv_public_extend_end_enabled`, default '0')
	 *
	 * Layout mirrors Section 3 (Restriction & Security): the 3 sub-toggles
	 * appear at the top of the master's collapsed wrapper, each followed
	 * by its own conditional sub-options block (collapsed-target keyed
	 * to the respective sub-toggle).
	 *
	 * @since 5.1.0
	 * @param WP_Post $post The post object.
	 */
	public function render( WP_Post $post ): void {
		$enabled = (string) get_post_meta( $post->ID, '_ffc_csv_public_enabled', true );
		$hash    = (string) get_post_meta( $post->ID, '_ffc_csv_public_hash', true );
		$limit   = (int) get_post_meta( $post->ID, '_ffc_csv_public_limit', true );
		$count   = (int) get_post_meta( $post->ID, '_ffc_csv_public_count', true );

		// 3 operator-feature toggles. Defaults reflect the user's stated
		// "enable master → Download ON, Start Early OFF, Postpone Close OFF"
		// rule (#241 follow-up). Existing forms that explicitly saved a
		// value keep that value; only forms that NEVER saved any of these
		// metas pick up the new defaults.
		$preview_raw     = (string) get_post_meta( $post->ID, '_ffc_csv_public_preview_enabled', true );
		$preview_enabled = '' === $preview_raw ? '1' : $preview_raw;

		$download_raw     = (string) get_post_meta( $post->ID, '_ffc_csv_public_download_enabled', true );
		$download_enabled = '' === $download_raw ? '1' : $download_raw;

		$start_early_raw     = (string) get_post_meta( $post->ID, '_ffc_csv_public_start_early_enabled', true );
		$start_early_enabled = $start_early_raw;

		$extend_end_raw     = (string) get_post_meta( $post->ID, '_ffc_csv_public_extend_end_enabled', true );
		$extend_end_enabled = $extend_end_raw;

		if ( $limit <= 0 ) {
			$default_limit = \FreeFormCertificate\Settings\SettingsReader::get_int( 'public_csv_default_limit', 1 );
			$limit         = $default_limit > 0 ? $default_limit : 1;
		}

		// Check that a geofence end date is configured — required for this feature.
		$geofence_config = get_post_meta( $post->ID, '_ffc_geofence_config', true );
		$date_end        = ( is_array( $geofence_config ) && ! empty( $geofence_config['date_end'] ) )
			? (string) $geofence_config['date_end']
			: '';

		$sub_disabled = ( '1' !== $enabled );
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
							'label'   => __( 'Allow operators with the hash to use this form\'s public surface.', 'ffcertificate' ),
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

		<div class="ffc-collapsed-target<?php echo $sub_disabled ? ' ffc-collapsed' : ''; ?>"
			data-ffc-master="ffc_csv_public_enabled"
			aria-hidden="<?php echo $sub_disabled ? 'true' : 'false'; ?>">

		<table class="form-table ffc-csv-public-table">
			<tr>
				<th scope="row">
					<?php esc_html_e( 'Operator features', 'ffcertificate' ); ?>
				</th>
				<td>
					<p class="description ffc-mb-15">
						<?php esc_html_e( 'Pick which operator actions this form exposes (can combine multiple):', 'ffcertificate' ); ?>
					</p>

					<div class="ffc-restriction-label">
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'ffc_csv_public[preview_enabled]',
								'id'      => 'ffc_csv_public_preview_enabled',
								'checked' => '1' === $preview_enabled,
								'label'   => __( 'Certificate Preview', 'ffcertificate' ),
								'data'    => array( 'ffc-autosave-form-key' => 'csv_public_preview_enabled' ),
							)
						);
						?>
						<span class="description"> — <?php esc_html_e( 'Allow the operator to preview the certificate template before the form opens.', 'ffcertificate' ); ?></span>
					</div>

					<div class="ffc-restriction-label">
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'ffc_csv_public[download_enabled]',
								'id'      => 'ffc_csv_public_download_enabled',
								'checked' => '1' === $download_enabled,
								'label'   => __( 'CSV Download', 'ffcertificate' ),
								'data'    => array( 'ffc-autosave-form-key' => 'csv_public_download_enabled' ),
							)
						);
						?>
						<span class="description"> — <?php esc_html_e( 'Allow the operator to download the submissions CSV after the form ends.', 'ffcertificate' ); ?></span>
					</div>

					<div class="ffc-restriction-label">
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'ffc_csv_public[start_early_enabled]',
								'id'      => 'ffc_csv_public_start_early_enabled',
								'checked' => '1' === $start_early_enabled,
								'label'   => __( 'Start Form Early', 'ffcertificate' ),
								'data'    => array( 'ffc-autosave-form-key' => 'csv_public_start_early_enabled' ),
							)
						);
						?>
						<span class="description"> — <?php esc_html_e( 'Allow the operator to open the form ahead of its scheduled start.', 'ffcertificate' ); ?></span>
					</div>

					<div class="ffc-restriction-label">
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'ffc_csv_public[extend_end_enabled]',
								'id'      => 'ffc_csv_public_extend_end_enabled',
								'checked' => '1' === $extend_end_enabled,
								'label'   => __( 'Postpone Close', 'ffcertificate' ),
								'data'    => array( 'ffc-autosave-form-key' => 'csv_public_extend_end_enabled' ),
							)
						);
						?>
						<span class="description"> — <?php esc_html_e( 'Allow the operator to push the close time later (one-shot per form).', 'ffcertificate' ); ?></span>
					</div>

					<p class="description ffc-mt-15">
						<em><?php esc_html_e( 'Note: if none of the three is selected, the public page renders the access screen but exposes no actions.', 'ffcertificate' ); ?></em>
					</p>
				</td>
			</tr>
		</table>

		<?php
		// ───── CSV Download sub-options ─────────────────────────────.
		// Wrapped in its own collapsed-target keyed to the new
		// `_ffc_csv_public_download_enabled` toggle so admins can keep
		// the master on (Start Early / Postpone Close still work) while
		// turning the CSV download off for read-only deployments.
		$csv_download_collapsed = ( '1' !== $download_enabled );
		?>
		<div class="ffc-collapsed-target<?php echo $csv_download_collapsed ? ' ffc-collapsed' : ''; ?>"
			data-ffc-master="ffc_csv_public_download_enabled"
			aria-hidden="<?php echo $csv_download_collapsed ? 'true' : 'false'; ?>">
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
					<?php esc_html_e( 'Access Link', 'ffcertificate' ); ?>
				</th>
				<td>
					<?php
					if ( '' === $hash ) :
						?>
						<p class="description">
							<?php esc_html_e( 'A hash will be generated automatically when you enable Public Operator Access above and save the form.', 'ffcertificate' ); ?>
						</p>
						<?php
					else :
						$csv_download_page_url = (string) \FreeFormCertificate\Settings\SettingsReader::get( 'csv_download_page_url', '' );
						$ffc_query_string      = 'form_id=' . $post->ID . '&hash=' . $hash;
						$share_link            = '' !== $csv_download_page_url
							? $csv_download_page_url . ( str_contains( $csv_download_page_url, '?' ) ? '&' : '?' ) . $ffc_query_string
							: '?' . $ffc_query_string;
						?>
						<div class="ffc-csv-public-share">
							<input type="text"
								id="ffc_csv_public_link"
								value="<?php echo esc_attr( $share_link ); ?>"
								readonly
								class="large-text code"
								onclick="this.select();">
							<button type="button"
								class="button button-secondary ffc-copy-link"
								data-ffc-copy-target="#ffc_csv_public_link"
								aria-live="polite">
								<?php esc_html_e( 'Copy link', 'ffcertificate' ); ?>
							</button>
						</div>
						<?php if ( '' === $csv_download_page_url ) : ?>
							<p class="description">
								<?php esc_html_e( 'Append this query string to the page that contains the [ffc_csv_download] shortcode. Set "CSV Download Page URL" in Settings → General to make this a full URL instead.', 'ffcertificate' ); ?>
							</p>
						<?php endif; ?>
					<?php endif; ?>

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
		</div><!-- /.ffc-collapsed-target (CSV Download sub-options) -->

		<?php
		// ───── Start Form Early status ─────────────────────────────.
		$this->render_start_form_early_status( $post, $enabled, $hash, $start_early_enabled );

		// ───── Postpone Close status ───────────────────────────────.
		$this->render_extend_end_status( $post, $enabled, $hash, $extend_end_enabled );
		?>

		</div><!-- /.ffc-collapsed-target — wraps the Operator features list +
			all three sub-feature blocks (CSV Download / Start Form Early /
			Postpone Close), so they collapse together when Public Operator
			Access is off. -->
		<?php
	}

	/**
	 * Render the "Start Form Early" status sub-block.
	 *
	 * Wraps a single Status row in a `.ffc-collapsed-target` keyed to
	 * the Start Form Early toggle (in the Operator features list above)
	 * so the row reveals only when the feature is on.
	 *
	 * The toggle itself is rendered in the operator-features list at
	 * the top of this metabox — this method only emits the status pill
	 * that mirrors `EarlyOpenAction::is_eligible()` so admins can see
	 * why the public button is / isn't visible to operators.
	 *
	 * @since 6.5.6
	 * @param WP_Post $post                  The form post.
	 * @param string  $enabled               CSV-public master toggle ('1' or '').
	 * @param string  $hash                  The form's CSV-public hash.
	 * @param string  $start_early_enabled   The start-early sub-toggle ('1' or '0').
	 */
	private function render_start_form_early_status( WP_Post $post, string $enabled, string $hash, string $start_early_enabled ): void {
		$start_ts   = \FreeFormCertificate\Security\Geofence::get_form_start_timestamp( $post->ID );
		$end_ts     = \FreeFormCertificate\Security\Geofence::get_form_end_timestamp( $post->ID );
		$now        = time();
		$enabled_ok = '1' === $enabled && '' !== $hash;

		// Status string mirrors the eligibility branches in EarlyOpenAction.
		if ( ! $enabled_ok ) {
			$status_label = __( 'Enable Public Operator Access above to expose the early-start button to operators.', 'ffcertificate' );
			$status_kind  = 'warning';
		} elseif ( '1' !== $start_early_enabled ) {
			$status_label = __( 'Early-start is disabled for this form — toggle it on in the Operator features list above.', 'ffcertificate' );
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

		$collapsed = ( '1' !== $start_early_enabled );
		?>
		<div class="ffc-collapsed-target<?php echo $collapsed ? ' ffc-collapsed' : ''; ?>"
			data-ffc-master="ffc_csv_public_start_early_enabled"
			aria-hidden="<?php echo $collapsed ? 'true' : 'false'; ?>">
		<table class="form-table ffc-csv-public-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Start Form Early — status', 'ffcertificate' ); ?></th>
				<td>
					<span class="ffc-status-pill ffc-status-pill--<?php echo esc_attr( $status_kind ); ?>">
						<?php echo esc_html( $status_label ); ?>
					</span>
				</td>
			</tr>
		</table>
		</div>
		<?php
	}

	/**
	 * Render the "Postpone Close" status sub-block.
	 *
	 * Sibling of {@see render_start_form_early_status()} for the close
	 * boundary. The toggle itself lives in the Operator features list;
	 * this method only emits the status pill.
	 *
	 * @since 6.5.12
	 * @param WP_Post $post                  The form post.
	 * @param string  $enabled               CSV-public master toggle ('1' or '').
	 * @param string  $hash                  The form's CSV-public hash.
	 * @param string  $extend_end_enabled    The postpone-close sub-toggle ('1' or '0').
	 */
	private function render_extend_end_status( WP_Post $post, string $enabled, string $hash, string $extend_end_enabled ): void {
		$start_ts      = \FreeFormCertificate\Security\Geofence::get_form_start_timestamp( $post->ID );
		$end_ts        = \FreeFormCertificate\Security\Geofence::get_form_end_timestamp( $post->ID );
		$now           = time();
		$enabled_ok    = '1' === $enabled && '' !== $hash;
		$extend_on     = '1' === $extend_end_enabled;
		$postponed_at  = (string) get_post_meta( $post->ID, \FreeFormCertificate\Frontend\ExtendEndAction::META_POSTPONED_AT, true );
		$postponed_frm = (string) get_post_meta( $post->ID, \FreeFormCertificate\Frontend\ExtendEndAction::META_POSTPONED_FROM, true );

		// Status string mirrors ExtendEndAction::is_eligible() branches.
		if ( ! $enabled_ok ) {
			$status_label = __( 'Enable Public Operator Access above to expose the postpone-close button to operators.', 'ffcertificate' );
			$status_kind  = 'warning';
		} elseif ( ! $extend_on ) {
			$status_label = __( 'Postponing the close is disabled for this form — toggle it on in the Operator features list above.', 'ffcertificate' );
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

		$collapsed = ! $extend_on;
		?>
		<div class="ffc-collapsed-target<?php echo $collapsed ? ' ffc-collapsed' : ''; ?>"
			data-ffc-master="ffc_csv_public_extend_end_enabled"
			aria-hidden="<?php echo $collapsed ? 'true' : 'false'; ?>">
		<table class="form-table ffc-csv-public-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Postpone Close — status', 'ffcertificate' ); ?></th>
				<td>
					<span class="ffc-status-pill ffc-status-pill--<?php echo esc_attr( $status_kind ); ?>">
						<?php echo esc_html( $status_label ); ?>
					</span>
				</td>
			</tr>
		</table>
		</div>
		<?php
	}
}
