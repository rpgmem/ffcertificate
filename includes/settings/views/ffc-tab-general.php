<?php
/**
 * General Settings Tab
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file

$ffcertificate_get_option = \Closure::fromCallable( array( $settings, 'get_option' ) );

$ffcertificate_date_formats = array(
	'Y-m-d'              => '2026-01-04 (YYYY-MM-DD)',
	'd/m/Y'              => '04/01/2026 (DD/MM/YYYY)',
	'm/d/Y'              => '01/04/2026 (MM/DD/YYYY)',
	'F j, Y'             => __( 'January 4, 2026 (Month Day, Year)', 'ffcertificate' ),
	'j \d\e F \d\e Y'    => __( '4 of January, 2026', 'ffcertificate' ),
	'd \d\e F \d\e Y'    => __( '04 of January, 2026', 'ffcertificate' ),
	'l, j \d\e F \d\e Y' => __( 'Saturday, January 4, 2026', 'ffcertificate' ),
	'custom'             => __( 'Custom Format', 'ffcertificate' ),
);

$ffcertificate_current_format = $ffcertificate_get_option( 'date_format', \FreeFormCertificate\Core\DateFormatter::DEFAULT_DATE_FORMAT );
$ffcertificate_custom_format  = $ffcertificate_get_option( 'date_format_custom', '' );

// Smart-match for legacy installs (#244 era). The presets used to include
// combined date+time options ("d/m/Y H:i" etc.); #248 removed them. If a
// site has one of those saved we strip the time chars to find the matching
// date-only preset, so the dropdown opens on the closest equivalent
// instead of mis-rendering as the first option. Falling through means the
// value is genuinely custom — we surface it in the Custom Format field.
if ( ! isset( $ffcertificate_date_formats[ $ffcertificate_current_format ] ) && 'custom' !== $ffcertificate_current_format ) {
	$ffcertificate_stripped = \FreeFormCertificate\Core\DateFormatter::strip_time_chars( $ffcertificate_current_format );
	if ( isset( $ffcertificate_date_formats[ $ffcertificate_stripped ] ) ) {
		$ffcertificate_current_format = $ffcertificate_stripped;
	} else {
		$ffcertificate_custom_format  = '' !== $ffcertificate_stripped ? $ffcertificate_stripped : $ffcertificate_current_format;
		$ffcertificate_current_format = 'custom';
	}
}
// #244 — time format + per-context PDF overrides.
$ffcertificate_time_format            = $ffcertificate_get_option( 'time_format', \FreeFormCertificate\Core\DateFormatter::DEFAULT_TIME_FORMAT );
$ffcertificate_time_format_custom     = $ffcertificate_get_option( 'time_format_custom', '' );
$ffcertificate_date_format_pdf        = $ffcertificate_get_option( 'date_format_pdf', '' );
$ffcertificate_date_format_pdf_custom = $ffcertificate_get_option( 'date_format_pdf_custom', '' );
$ffcertificate_time_format_pdf        = $ffcertificate_get_option( 'time_format_pdf', '' );
$ffcertificate_time_format_pdf_custom = $ffcertificate_get_option( 'time_format_pdf_custom', '' );
$ffcertificate_main_address           = $ffcertificate_get_option( 'main_address', '' );

// Time format presets shared by the base Time Format dropdown (#248) and
// the PDF Time Format override below.
$ffcertificate_time_formats = array(
	'H:i'     => '15:30 (24h HH:MM)',
	'H:i:s'   => '15:30:45 (24h HH:MM:SS)',
	'g:i a'   => '3:30 pm (12h)',
	'g:i:s a' => '3:30:45 pm (12h with seconds)',
	'custom'  => __( 'Custom Format', 'ffcertificate' ),
);

// Base Time Format select (#248): if the saved value isn't a preset it
// flows into Custom Format. Pre-#248 installs saved free-form time
// strings here — they land in the Custom field transparently.
$ffcertificate_time_format_select = $ffcertificate_time_format;
if ( ! isset( $ffcertificate_time_formats[ $ffcertificate_time_format_select ] ) && 'custom' !== $ffcertificate_time_format_select ) {
	$ffcertificate_time_format_custom = $ffcertificate_time_format_select;
	$ffcertificate_time_format_select = 'custom';
}

// PDF Time Format override (#248) — symmetric to PDF Date Format.
// `''` = Inherit, `custom` = free-form via `time_format_pdf_custom`.
$ffcertificate_time_format_pdf_options = array(
	'' => __( 'Inherit — use Time Format above', 'ffcertificate' ),
) + $ffcertificate_time_formats;
$ffcertificate_time_format_pdf_select  = $ffcertificate_time_format_pdf;
if ( '' !== $ffcertificate_time_format_pdf_select
	&& ! isset( $ffcertificate_time_format_pdf_options[ $ffcertificate_time_format_pdf_select ] )
	&& 'custom' !== $ffcertificate_time_format_pdf_select
) {
	// No smart-match for time (time_format_pdf is new in #244 — no legacy
	// combined format to disentangle). An unrecognised saved value just
	// flows into Custom Format.
	$ffcertificate_time_format_pdf_custom = $ffcertificate_time_format_pdf_select;
	$ffcertificate_time_format_pdf_select = 'custom';
}

// PDF Date Format override (#248): same dropdown shape as the main Date
// Format, with `''` for "Inherit" and `custom` for free-form entry. Smart-
// match an unrecognised legacy value the same way the main dropdown does.
$ffcertificate_date_format_pdf_options = array(
	'' => __( 'Inherit — use Date Format above', 'ffcertificate' ),
) + $ffcertificate_date_formats;
$ffcertificate_date_format_pdf_select  = $ffcertificate_date_format_pdf;
if ( '' !== $ffcertificate_date_format_pdf_select
	&& ! isset( $ffcertificate_date_format_pdf_options[ $ffcertificate_date_format_pdf_select ] )
	&& 'custom' !== $ffcertificate_date_format_pdf_select
) {
	$ffcertificate_pdf_stripped = \FreeFormCertificate\Core\DateFormatter::strip_time_chars( $ffcertificate_date_format_pdf_select );
	if ( isset( $ffcertificate_date_format_pdf_options[ $ffcertificate_pdf_stripped ] ) ) {
		$ffcertificate_date_format_pdf_select = $ffcertificate_pdf_stripped;
	} else {
		$ffcertificate_date_format_pdf_custom = '' !== $ffcertificate_pdf_stripped ? $ffcertificate_pdf_stripped : $ffcertificate_date_format_pdf_select;
		$ffcertificate_date_format_pdf_select = 'custom';
	}
}

// Divergence between plugin format and WordPress core format (#244).
// Surfaces a notice in the General tab so admins notice when they have
// the plugin configured differently from the rest of WP. The plugin's
// `date_format` setting takes precedence inside the plugin (PDFs, emails,
// admin lists since #244 Sprint 2), so divergence is intentional but
// worth flagging.
$ffcertificate_wp_date_format  = (string) \get_option( 'date_format', '' );
$ffcertificate_wp_time_format  = (string) \get_option( 'time_format', '' );
$ffcertificate_effective_date  = ( 'custom' === $ffcertificate_current_format && '' !== $ffcertificate_custom_format )
	? $ffcertificate_custom_format
	: $ffcertificate_current_format;
$ffcertificate_effective_time  = ( 'custom' === $ffcertificate_time_format_select && '' !== $ffcertificate_time_format_custom )
	? $ffcertificate_time_format_custom
	: $ffcertificate_time_format_select;
$ffcertificate_date_diverges   = '' !== $ffcertificate_wp_date_format && $ffcertificate_wp_date_format !== $ffcertificate_effective_date;
$ffcertificate_time_diverges   = '' !== $ffcertificate_wp_time_format && $ffcertificate_wp_time_format !== $ffcertificate_effective_time;
$ffcertificate_show_divergence = $ffcertificate_date_diverges || $ffcertificate_time_diverges;
?>

<div class="ffc-settings-wrap">

<?php if ( $ffcertificate_show_divergence ) : ?>
	<div class="notice notice-info inline ffc-settings-divergence-notice" style="margin: 15px 0; padding: 12px 15px;">
		<p style="margin: 0 0 8px;">
			<strong><?php esc_html_e( 'Heads-up: plugin formats differ from the WordPress global formats.', 'ffcertificate' ); ?></strong>
		</p>
		<p style="margin: 0 0 8px;">
			<?php esc_html_e( 'The plugin uses its own date and time formats (configured below) for everything it renders — admin lists, frontend pages, emails, REST responses and PDFs. The rest of WordPress (themes, other plugins, posts) keeps using the global formats from Settings → General. This is intentional, but worth knowing when comparing dates across the dashboard.', 'ffcertificate' ); ?>
		</p>
		<ul style="margin: 6px 0 0 18px; list-style: disc;">
			<?php if ( $ffcertificate_date_diverges ) : ?>
				<li>
					<?php
					printf(
						/* translators: 1: plugin date format string, 2: WordPress global date format string */
						esc_html__( 'Date: plugin uses %1$s, WordPress uses %2$s.', 'ffcertificate' ),
						'<code>' . esc_html( $ffcertificate_effective_date ) . '</code>',
						'<code>' . esc_html( $ffcertificate_wp_date_format ) . '</code>'
					); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped via esc_html inside printf args.
					?>
				</li>
			<?php endif; ?>
			<?php if ( $ffcertificate_time_diverges ) : ?>
				<li>
					<?php
					printf(
						/* translators: 1: plugin time format string, 2: WordPress global time format string */
						esc_html__( 'Time: plugin uses %1$s, WordPress uses %2$s.', 'ffcertificate' ),
						'<code>' . esc_html( $ffcertificate_effective_time ) . '</code>',
						'<code>' . esc_html( $ffcertificate_wp_time_format ) . '</code>'
					); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped via esc_html inside printf args.
					?>
				</li>
			<?php endif; ?>
		</ul>
	</div>
<?php endif; ?>

<form method="post">
	<?php wp_nonce_field( 'ffc_settings_action', 'ffc_settings_nonce' ); ?>
	<input type="hidden" name="_ffc_tab" value="general">

<!-- General Settings Card -->
<div class="card">
	<h2 class="ffc-icon-settings"><?php esc_html_e( 'General Settings', 'ffcertificate' ); ?></h2>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="ffc_dark_mode"><?php esc_html_e( 'Dark Mode', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<select name="ffc_settings[dark_mode]" id="ffc_dark_mode" class="regular-text" data-ffc-autosave-key="dark_mode">
							<option value="off" <?php selected( $ffcertificate_get_option( 'dark_mode', 'off' ), 'off' ); ?>><?php esc_html_e( 'Off', 'ffcertificate' ); ?></option>
							<option value="on" <?php selected( $ffcertificate_get_option( 'dark_mode', 'off' ), 'on' ); ?>><?php esc_html_e( 'On (always dark)', 'ffcertificate' ); ?></option>
							<option value="auto" <?php selected( $ffcertificate_get_option( 'dark_mode', 'off' ), 'auto' ); ?>><?php esc_html_e( 'Auto (follow OS)', 'ffcertificate' ); ?></option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Controls the dark mode appearance for plugin admin pages.', 'ffcertificate' ); ?><br>
							<span class="ffc-text-info ffc-icon-info"><?php esc_html_e( '"Auto" follows your operating system preference.', 'ffcertificate' ); ?></span>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="cleanup_days"><?php esc_html_e( 'Auto-delete (days)', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<input type="number" name="ffc_settings[cleanup_days]" id="cleanup_days" value="<?php echo esc_attr( $ffcertificate_get_option( 'cleanup_days' ) ); ?>" class="small-text" min="0" data-ffc-autosave-key="cleanup_days">
						<p class="description"><?php esc_html_e( 'Files removed after X days. Set to 0 to disable.', 'ffcertificate' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="ffc_date_format"><?php esc_html_e( 'Date Format', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<select name="ffc_settings[date_format]" id="ffc_date_format" class="regular-text" data-ffc-autosave-key="date_format">
							<?php foreach ( $ffcertificate_date_formats as $ffcertificate_format => $ffcertificate_label ) : ?>
								<option value="<?php echo esc_attr( $ffcertificate_format ); ?>" <?php selected( $ffcertificate_current_format, $ffcertificate_format ); ?>>
									<?php echo esc_html( $ffcertificate_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Format used for {{submission_date}} placeholder in PDFs and emails.', 'ffcertificate' ); ?>
							<br>
							<strong><?php esc_html_e( 'Preview:', 'ffcertificate' ); ?></strong>
							<span class="ffc-text-info ffc-monospace">
								<?php
								$ffcertificate_preview_date = '2026-01-04 15:30:45';
								echo esc_html( date_i18n( ( 'custom' === $ffcertificate_current_format && ! empty( $ffcertificate_custom_format ) ) ? $ffcertificate_custom_format : $ffcertificate_current_format, strtotime( $ffcertificate_preview_date ) ) );
								?>
							</span>
						</p>

						<div id="ffc_custom_format_container" class="ffc-collapsible-section <?php echo esc_attr( 'custom' !== $ffcertificate_current_format ? 'ffc-hidden' : '' ); ?>">
							<div class="ffc-collapsible-content active">
								<label>
									<strong><?php esc_html_e( 'Custom Format:', 'ffcertificate' ); ?></strong><br>
									<input type="text" name="ffc_settings[date_format_custom]" id="ffc_date_format_custom" value="<?php echo esc_attr( $ffcertificate_custom_format ); ?>" placeholder="d/m/Y H:i" class="regular-text" data-ffc-autosave-key="date_format_custom">
								</label>
								<p class="description">
									<?php esc_html_e( 'Use PHP date format characters.', 'ffcertificate' ); ?>
									<a href="https://www.php.net/manual/en/datetime.format.php" target="_blank"><?php esc_html_e( 'See documentation', 'ffcertificate' ); ?></a>
								</p>
							</div>
						</div>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="ffc_time_format"><?php esc_html_e( 'Time Format', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<select name="ffc_settings[time_format]" id="ffc_time_format" class="regular-text" data-ffc-autosave-key="time_format">
							<?php foreach ( $ffcertificate_time_formats as $ffcertificate_format => $ffcertificate_label ) : ?>
								<option value="<?php echo esc_attr( $ffcertificate_format ); ?>" <?php selected( $ffcertificate_time_format_select, $ffcertificate_format ); ?>>
									<?php echo esc_html( $ffcertificate_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Time portion used by DateFormatter::format_time() and combined date+time output.', 'ffcertificate' ); ?>
							<br>
							<strong><?php esc_html_e( 'Preview:', 'ffcertificate' ); ?></strong>
							<span class="ffc-text-info ffc-monospace">
								<?php
								$ffcertificate_preview_time     = '2026-01-04 15:30:45';
								$ffcertificate_time_preview_fmt = ( 'custom' === $ffcertificate_time_format_select && '' !== $ffcertificate_time_format_custom )
									? $ffcertificate_time_format_custom
									: $ffcertificate_time_format_select;
								echo esc_html( date_i18n( '' !== $ffcertificate_time_preview_fmt ? $ffcertificate_time_preview_fmt : 'H:i', strtotime( $ffcertificate_preview_time ) ) );
								?>
							</span>
						</p>

						<div id="ffc_time_format_custom_container" class="ffc-collapsed-target <?php echo esc_attr( 'custom' !== $ffcertificate_time_format_select ? 'ffc-collapsed' : '' ); ?>" data-ffc-master="ffc_time_format" data-ffc-master-value="custom">
							<label>
								<strong><?php esc_html_e( 'Custom Format:', 'ffcertificate' ); ?></strong><br>
								<input type="text" name="ffc_settings[time_format_custom]" id="ffc_time_format_custom" value="<?php echo esc_attr( $ffcertificate_time_format_custom ); ?>" placeholder="H:i" class="regular-text" data-ffc-autosave-key="time_format_custom">
							</label>
							<p class="description">
								<?php esc_html_e( 'Use PHP date format characters.', 'ffcertificate' ); ?>
								<a href="https://www.php.net/manual/en/datetime.format.php" target="_blank"><?php esc_html_e( 'See documentation', 'ffcertificate' ); ?></a>
							</p>
						</div>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="ffc_date_format_pdf"><?php esc_html_e( 'PDF Date Format (override)', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<select name="ffc_settings[date_format_pdf]" id="ffc_date_format_pdf" class="regular-text" data-ffc-autosave-key="date_format_pdf">
							<?php foreach ( $ffcertificate_date_format_pdf_options as $ffcertificate_format => $ffcertificate_label ) : ?>
								<option value="<?php echo esc_attr( $ffcertificate_format ); ?>" <?php selected( $ffcertificate_date_format_pdf_select, $ffcertificate_format ); ?>>
									<?php echo esc_html( $ffcertificate_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Optional override for the PDF generator. "Inherit" reuses the Date Format above.', 'ffcertificate' ); ?>
							<?php
							$ffcertificate_pdf_preview_format = ( 'custom' === $ffcertificate_date_format_pdf_select && '' !== $ffcertificate_date_format_pdf_custom )
								? $ffcertificate_date_format_pdf_custom
								: $ffcertificate_date_format_pdf_select;
							if ( '' !== $ffcertificate_pdf_preview_format ) :
								?>
								<br>
								<strong><?php esc_html_e( 'Preview:', 'ffcertificate' ); ?></strong>
								<span class="ffc-text-info ffc-monospace">
									<?php echo esc_html( date_i18n( $ffcertificate_pdf_preview_format, strtotime( '2026-01-04' ) ) ); ?>
								</span>
							<?php endif; ?>
						</p>

						<div id="ffc_date_format_pdf_custom_container" class="ffc-collapsed-target <?php echo esc_attr( 'custom' !== $ffcertificate_date_format_pdf_select ? 'ffc-collapsed' : '' ); ?>" data-ffc-master="ffc_date_format_pdf" data-ffc-master-value="custom">
							<label>
								<strong><?php esc_html_e( 'Custom Format:', 'ffcertificate' ); ?></strong><br>
								<input type="text" name="ffc_settings[date_format_pdf_custom]" id="ffc_date_format_pdf_custom" value="<?php echo esc_attr( $ffcertificate_date_format_pdf_custom ); ?>" placeholder="d \d\e F \d\e Y" class="regular-text" data-ffc-autosave-key="date_format_pdf_custom">
							</label>
							<p class="description">
								<?php esc_html_e( 'Use PHP date format characters.', 'ffcertificate' ); ?>
								<a href="https://www.php.net/manual/en/datetime.format.php" target="_blank"><?php esc_html_e( 'See documentation', 'ffcertificate' ); ?></a>
							</p>
						</div>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="ffc_time_format_pdf"><?php esc_html_e( 'PDF Time Format (override)', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<select name="ffc_settings[time_format_pdf]" id="ffc_time_format_pdf" class="regular-text" data-ffc-autosave-key="time_format_pdf">
							<?php foreach ( $ffcertificate_time_format_pdf_options as $ffcertificate_format => $ffcertificate_label ) : ?>
								<option value="<?php echo esc_attr( $ffcertificate_format ); ?>" <?php selected( $ffcertificate_time_format_pdf_select, $ffcertificate_format ); ?>>
									<?php echo esc_html( $ffcertificate_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Optional override for the PDF generator. "Inherit" reuses the Time Format above.', 'ffcertificate' ); ?>
							<?php
							$ffcertificate_pdf_time_preview = ( 'custom' === $ffcertificate_time_format_pdf_select && '' !== $ffcertificate_time_format_pdf_custom )
								? $ffcertificate_time_format_pdf_custom
								: $ffcertificate_time_format_pdf_select;
							if ( '' !== $ffcertificate_pdf_time_preview ) :
								?>
								<br>
								<strong><?php esc_html_e( 'Preview:', 'ffcertificate' ); ?></strong>
								<span class="ffc-text-info ffc-monospace">
									<?php echo esc_html( date_i18n( $ffcertificate_pdf_time_preview, strtotime( '2026-01-04 15:30:45' ) ) ); ?>
								</span>
							<?php endif; ?>
						</p>

						<div id="ffc_time_format_pdf_custom_container" class="ffc-collapsed-target <?php echo esc_attr( 'custom' !== $ffcertificate_time_format_pdf_select ? 'ffc-collapsed' : '' ); ?>" data-ffc-master="ffc_time_format_pdf" data-ffc-master-value="custom">
							<label>
								<strong><?php esc_html_e( 'Custom Format:', 'ffcertificate' ); ?></strong><br>
								<input type="text" name="ffc_settings[time_format_pdf_custom]" id="ffc_time_format_pdf_custom" value="<?php echo esc_attr( $ffcertificate_time_format_pdf_custom ); ?>" placeholder="H:i" class="regular-text" data-ffc-autosave-key="time_format_pdf_custom">
							</label>
							<p class="description">
								<?php esc_html_e( 'Use PHP date format characters.', 'ffcertificate' ); ?>
								<a href="https://www.php.net/manual/en/datetime.format.php" target="_blank"><?php esc_html_e( 'See documentation', 'ffcertificate' ); ?></a>
							</p>
						</div>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="main_address"><?php esc_html_e( 'Main Address', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<input type="text" name="ffc_settings[main_address]" id="main_address" value="<?php echo esc_attr( $ffcertificate_main_address ); ?>" class="large-text" data-ffc-autosave-key="main_address">
						<p class="description">
							<?php esc_html_e( 'Main institutional address. Available as {{main_address}} placeholder in certificate and appointment templates.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="csv_download_page_url"><?php esc_html_e( 'CSV Download Page URL', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<input type="url" name="ffc_settings[csv_download_page_url]" id="csv_download_page_url" value="<?php echo esc_attr( $ffcertificate_get_option( 'csv_download_page_url', '' ) ); ?>" class="large-text" placeholder="https://example.com/csv-download/" data-ffc-autosave-key="csv_download_page_url">
						<p class="description">
							<?php esc_html_e( 'URL of the page containing the [ffc_csv_download] shortcode. When set, the form editor will display the full download link instead of just the query string.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>
				</tbody>
		</table>
</div>

<!-- QR Code Defaults Card -->
<div class="card">
	<h2 class="ffc-icon-phone"><?php esc_html_e( 'QR Code Defaults', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Default settings for QR Code generation in certificates.', 'ffcertificate' ); ?>
	</p>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="qr_default_size"><?php esc_html_e( 'Default QR Code Size', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<input type="number" name="ffc_settings[qr_default_size]" id="qr_default_size" value="<?php echo esc_attr( $ffcertificate_get_option( 'qr_default_size', 100 ) ); ?>" min="100" max="500" step="10" class="small-text" data-ffc-autosave-key="qr_default_size"> px
						<p class="description">
							<?php esc_html_e( 'Default size when {{qr_code}} placeholder is used without size parameter. Range: 100-500px.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="qr_default_margin"><?php esc_html_e( 'Default QR Code Margin', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<input type="number" name="ffc_settings[qr_default_margin]" id="qr_default_margin" value="<?php echo esc_attr( $ffcertificate_get_option( 'qr_default_margin', 0 ) ); ?>" min="0" max="10" step="1" class="small-text" data-ffc-autosave-key="qr_default_margin">
						<p class="description">
							<?php esc_html_e( 'White space around QR Code in modules. 0 = no margin, higher values = more white space.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="qr_default_error_level"><?php esc_html_e( 'Default Error Correction Level', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<select name="ffc_settings[qr_default_error_level]" id="qr_default_error_level" class="regular-text" data-ffc-autosave-key="qr_default_error_level">
							<option value="L" <?php selected( 'L', $ffcertificate_get_option( 'qr_default_error_level', 'L' ) ); ?>>
								L - <?php esc_html_e( 'Low (7% correction)', 'ffcertificate' ); ?>
							</option>
							<option value="M" <?php selected( 'M', $ffcertificate_get_option( 'qr_default_error_level', 'M' ) ); ?>>
								M - <?php esc_html_e( 'Medium (15% correction) - Recommended', 'ffcertificate' ); ?>
							</option>
							<option value="Q" <?php selected( 'Q', $ffcertificate_get_option( 'qr_default_error_level', 'Q' ) ); ?>>
								Q - <?php esc_html_e( 'Quartile (25% correction)', 'ffcertificate' ); ?>
							</option>
							<option value="H" <?php selected( 'H', $ffcertificate_get_option( 'qr_default_error_level', 'H' ) ); ?>>
								H - <?php esc_html_e( 'High (30% correction)', 'ffcertificate' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Higher levels allow more damage to QR Code but create denser patterns.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>
</div>

	<?php submit_button(); ?>

</form>

</div><!-- .ffc-settings-wrap -->
