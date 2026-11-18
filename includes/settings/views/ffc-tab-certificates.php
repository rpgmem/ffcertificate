<?php
/**
 * Certificates Settings Tab View
 *
 * @package FreeFormCertificate\Settings\Views
 * @since 6.39.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffcertificate_req_rows = \FreeFormCertificate\Settings\RequiredData::rows();
$ffcertificate_types    = array(
	'text'     => __( 'Text', 'ffcertificate' ),
	'textarea' => __( 'Textarea', 'ffcertificate' ),
	'email'    => __( 'Email', 'ffcertificate' ),
	'number'   => __( 'Number', 'ffcertificate' ),
	'date'     => __( 'Date', 'ffcertificate' ),
);

/**
 * One row of the "Required data" table (#1759).
 *
 * A tag the plugin fills in itself has no form field, so its row carries
 * the HTML switch only. The Email row's form switch and the {{auth_code}}
 * row's HTML switch are locked on, and neither row can be removed.
 *
 * @param string                                                                     $index Row index, or the template placeholder.
 * @param array{name: string, label: string, type: string, form: bool, html: bool} $row   Row values.
 * @param array<string, string>                                                      $types Type => label.
 */
$ffcertificate_data_row = static function ( string $index, array $row, array $types ): void {
	$generated = '' !== $row['name'] && \FreeFormCertificate\Settings\RequiredData::is_generated( $row['name'] );
	$is_email  = \FreeFormCertificate\Settings\RequiredData::LOCKED_FORM_FIELD === $row['name'];
	$is_auth   = \FreeFormCertificate\Settings\RequiredData::LOCKED_TAG === $row['name'];
	$prefix    = 'ffc_settings[required_data][' . $index . ']';
	$id_prefix = 'ffc_required_data_' . $index;
	?>
	<tr class="ffc-required-data-row<?php echo $generated ? ' ffc-required-data-generated' : ''; ?>">
		<td>
			<input type="text" class="regular-text code ffc-required-data-name" name="<?php echo esc_attr( $prefix . '[name]' ); ?>" value="<?php echo esc_attr( $row['name'] ); ?>" pattern="[a-z0-9_]+" aria-label="<?php esc_attr_e( 'Field name', 'ffcertificate' ); ?>" <?php echo $is_email || $generated ? 'readonly' : ''; ?>>
		</td>
		<td>
			<?php if ( $generated ) : ?>
				<input type="hidden" class="ffc-required-data-label" name="<?php echo esc_attr( $prefix . '[label]' ); ?>" value="<?php echo esc_attr( $row['label'] ); ?>">
				<span class="description">&mdash;</span>
			<?php else : ?>
				<input type="text" class="regular-text ffc-required-data-label" name="<?php echo esc_attr( $prefix . '[label]' ); ?>" value="<?php echo esc_attr( $row['label'] ); ?>" aria-label="<?php esc_attr_e( 'Label', 'ffcertificate' ); ?>">
			<?php endif; ?>
		</td>
		<td>
			<?php if ( $generated ) : ?>
				<input type="hidden" class="ffc-required-data-type" name="<?php echo esc_attr( $prefix . '[type]' ); ?>" value="text">
				<span class="description"><?php esc_html_e( 'Filled in by the plugin', 'ffcertificate' ); ?></span>
			<?php elseif ( $is_email ) : ?>
				<input type="hidden" class="ffc-required-data-type" name="<?php echo esc_attr( $prefix . '[type]' ); ?>" value="email">
				<select disabled aria-label="<?php esc_attr_e( 'Type', 'ffcertificate' ); ?>"><option><?php echo esc_html( $types['email'] ); ?></option></select>
			<?php else : ?>
				<select class="ffc-required-data-type" name="<?php echo esc_attr( $prefix . '[type]' ); ?>" aria-label="<?php esc_attr_e( 'Type', 'ffcertificate' ); ?>">
					<?php foreach ( $types as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $row['type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
		</td>
		<td>
			<?php
			if ( $generated ) {
				echo '<span class="description">&mdash;</span>';
			} else {
				\FreeFormCertificate\Admin\AdminUI::render_toggle(
					array(
						'name'        => $prefix . '[form]',
						'id'          => $id_prefix . '_form',
						'checked'     => $row['form'] || $is_email,
						'disabled'    => $is_email,
						'input_class' => 'ffc-required-data-form',
						'aria_label'  => __( 'Required in the form', 'ffcertificate' ),
						'mark'        => true,
					)
				);
			}
			?>
		</td>
		<td>
			<?php
			\FreeFormCertificate\Admin\AdminUI::render_toggle(
				array(
					'name'        => $prefix . '[html]',
					'id'          => $id_prefix . '_html',
					'checked'     => $row['html'] || $is_auth,
					'disabled'    => $is_auth,
					'input_class' => 'ffc-required-data-html',
					'aria_label'  => __( 'Required in the certificate HTML', 'ffcertificate' ),
					'mark'        => true,
				)
			);
			?>
		</td>
		<td>
			<?php
			\FreeFormCertificate\Admin\AdminUI::render_button(
				array(
					'label'    => __( 'Remove', 'ffcertificate' ),
					'category' => 'danger',
					'icon'     => 'delete',
					'class'    => 'ffc-required-data-remove',
					'disabled' => $is_email || $is_auth,
				)
			);
			?>
		</td>
	</tr>
	<?php
};
?>

<div class="ffc-settings-wrap">

<form method="post">
	<?php wp_nonce_field( 'ffc_settings_action', 'ffc_settings_nonce' ); ?>
	<input type="hidden" name="_ffc_tab" value="certificates">

<div class="card">
	<h2 class="ffc-icon-award"><?php esc_html_e( 'Certificates', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'What every certificate form must carry. The certificate HTML editor\'s colour theme is on the General tab, next to Dark Mode.', 'ffcertificate' ); ?>
	</p>
	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row">
					<label for="quiz_focus_grace_ms"><?php esc_html_e( 'Quiz focus-loss grace (ms)', 'ffcertificate' ); ?></label>
				</th>
				<td>
					<input type="number" name="ffc_settings[quiz_focus_grace_ms]" id="quiz_focus_grace_ms" value="<?php echo esc_attr( (string) \FreeFormCertificate\Settings\SettingsReader::quiz_focus_grace_ms() ); ?>" min="<?php echo esc_attr( (string) \FreeFormCertificate\Core\QuizLeaveLevel::GRACE_MIN_MS ); ?>" max="<?php echo esc_attr( (string) \FreeFormCertificate\Core\QuizLeaveLevel::GRACE_MAX_MS ); ?>" step="100" class="small-text" data-ffc-autosave-key="quiz_focus_grace_ms" required>
					<p class="description">
						<?php esc_html_e( 'Used by quizzes set to Strict on leaving the window: a loss of focus counts only when it lasts longer than this, so a system notification or a password manager does not clear the answers. 0 counts every loss.', 'ffcertificate' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="browser_check"><?php esc_html_e( 'Browser check', 'ffcertificate' ); ?></label>
				</th>
				<td>
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'    => 'ffc_settings[browser_check]',
							'id'      => 'browser_check',
							'checked' => \FreeFormCertificate\Settings\SettingsReader::browser_check_enabled(),
							'label'   => __( 'Check the visitor\'s browser before the form', 'ffcertificate' ),
							'data'    => array( 'ffc-autosave-key' => 'browser_check' ),
						)
					);
					?>
					<p class="description">
						<?php esc_html_e( 'An app\'s own browser (Instagram, WhatsApp, Facebook and others) is asked to open the page in the phone\'s browser, with a "Continue anyway" link; a browser missing what the form needs to issue the certificate is stopped. Turn it off only if visitors are stopped by mistake: they then reach the form whatever browser they use.', 'ffcertificate' ); ?>
					</p>
				</td>
			</tr>
		</tbody>
	</table>
</div>

<div class="card">
	<h2 class="ffc-icon-text"><?php esc_html_e( 'Certificate fonts', 'ffcertificate' ); ?></h2>
	<?php \FreeFormCertificate\Core\CertificateFonts::render_notice(); ?>
</div>

<div class="card">
	<h2 class="ffc-icon-list"><?php esc_html_e( 'Required data', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'What every certificate needs, in one table. Required in the form: a new form starts with the field, and in the form editor its name, type and required flag are locked; only the label can change. Required in the certificate HTML: every certificate layout must print the field through its name in braces, such as {{cpf_rf}}. Changes save as you make them.', 'ffcertificate' ); ?>
	</p>
	<table class="widefat striped ffc-required-data-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Field name', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Label', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Type', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Required in the form', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Required in the certificate HTML', 'ffcertificate' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'ffcertificate' ); ?></span></th>
			</tr>
		</thead>
		<tbody id="ffc-required-data-rows" data-ffc-next-index="<?php echo esc_attr( (string) count( $ffcertificate_req_rows ) ); ?>" data-ffc-autosave-key="required_data" data-ffc-autosave-debounce="800">
			<?php
			foreach ( $ffcertificate_req_rows as $ffcertificate_i => $ffcertificate_row ) {
				$ffcertificate_data_row( (string) $ffcertificate_i, $ffcertificate_row, $ffcertificate_types );
			}
			?>
		</tbody>
	</table>
	<template id="ffc-required-data-template">
		<?php
		$ffcertificate_data_row(
			'__index__',
			array(
				'name'  => '',
				'label' => '',
				'type'  => 'text',
				'form'  => true,
				'html'  => false,
			),
			$ffcertificate_types
		);
		?>
	</template>
	<p>
		<?php
		\FreeFormCertificate\Admin\AdminUI::render_button(
			array(
				'label'    => __( 'Add required data', 'ffcertificate' ),
				'category' => 'create',
				'icon'     => 'plus',
				'id'       => 'ffc-required-data-add',
			)
		);
		?>
	</p>
	<p class="description">
		<?php esc_html_e( 'A name takes lowercase letters, digits and underscores. Email is always required in the form: the certificate is sent to it. {{auth_code}} is always required in the certificate HTML: verification depends on it. A tag the plugin fills in itself, such as {{schedule}}, has no form field. {{schedule}} prints nothing on a form without an Event Schedule, and every shipped certificate model carries it.', 'ffcertificate' ); ?>
	</p>
</div>

	<?php \FreeFormCertificate\Admin\AdminUI::render_save_button(); ?>

</form>

<?php
// Integrity signature (#2639): the key that signs certificate records. Its
// own form, outside the settings form above, because generating a key is an
// action, not a setting the tab's Save writes.
$ffcertificate_keys       = \FreeFormCertificate\Core\SigningKeys::all();
$ffcertificate_active_key = $ffcertificate_keys['active'];
$ffcertificate_can_rotate = \FreeFormCertificate\Admin\SigningKeyHandler::current_user_can();
$ffcertificate_key_notice = \FreeFormCertificate\Admin\SettingsNotice::take( \FreeFormCertificate\Admin\SigningKeyHandler::NOTICE_AREA );
$ffcertificate_key_counts = array() === $ffcertificate_keys['keys'] ? array() : \FreeFormCertificate\Submissions\CertificateSignatureRepository::count_by_key();
$ffcertificate_rotate_url = wp_nonce_url( add_query_arg( 'action', \FreeFormCertificate\Admin\SigningKeyHandler::ACTION, admin_url( 'admin-post.php' ) ), \FreeFormCertificate\Admin\SigningKeyHandler::ACTION );
?>
<div class="card" id="ffc-integrity-signature">
	<h2 class="ffc-icon-key"><?php esc_html_e( 'Integrity signature', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Forms with a signed QR mode sign a record of each certificate with this site\'s key. The verification page checks the record against the database and says when a certificate changed after it was signed. Choose the mode per form, on its Layout tab.', 'ffcertificate' ); ?>
	</p>
	<?php
	if ( null !== $ffcertificate_key_notice ) {
		\FreeFormCertificate\Core\AdminNotice::callout( $ffcertificate_key_notice['type'], '<p>' . esc_html( $ffcertificate_key_notice['message'] ) . '</p>', array( 'classes' => array( 'inline' ) ) );
	}
	if ( '' === $ffcertificate_active_key ) {
		\FreeFormCertificate\Core\AdminNotice::callout( 'warning', '<p>' . esc_html__( 'No signing key yet. Forms with a signed QR mode print the link only until one is generated.', 'ffcertificate' ) . '</p>', array( 'classes' => array( 'inline' ) ) );
	} else {
		$ffcertificate_active = $ffcertificate_keys['keys'][ $ffcertificate_active_key ];
		?>
		<dl class="ffc-facts">
			<dt class="ffc-facts__label"><?php esc_html_e( 'Active key', 'ffcertificate' ); ?></dt>
			<dd class="ffc-facts__value">
				<code><?php echo esc_html( $ffcertificate_active_key ); ?></code>
				<?php
				/* translators: %s: date the key was created */
				echo esc_html( sprintf( __( 'created on %s', 'ffcertificate' ), \FreeFormCertificate\Core\DateFormatter::format_date( $ffcertificate_active['created'] ) ) );
				?>
			</dd>
			<dt class="ffc-facts__label"><?php esc_html_e( 'Algorithm', 'ffcertificate' ); ?></dt>
			<dd class="ffc-facts__value"><?php echo esc_html( \FreeFormCertificate\Core\SigningKeys::ALGORITHM ); ?></dd>
			<dt class="ffc-facts__label"><?php esc_html_e( 'Fingerprint', 'ffcertificate' ); ?></dt>
			<dd class="ffc-facts__value"><code><?php echo esc_html( \FreeFormCertificate\Core\SigningKeys::fingerprint( $ffcertificate_active_key ) ); ?></code></dd>
			<dt class="ffc-facts__label"><?php esc_html_e( 'Signed certificates', 'ffcertificate' ); ?></dt>
			<dd class="ffc-facts__value"><?php echo esc_html( number_format_i18n( (int) ( $ffcertificate_key_counts[ $ffcertificate_active_key ] ?? 0 ) ) ); ?></dd>
			<dt class="ffc-facts__label"><?php esc_html_e( 'Public key', 'ffcertificate' ); ?></dt>
			<dd class="ffc-facts__value"><code><?php echo esc_html( $ffcertificate_active['public'] ); ?></code></dd>
		</dl>
		<?php
		if ( ! \FreeFormCertificate\Core\SigningKeys::secret_is_encrypted() ) {
			\FreeFormCertificate\Core\AdminNotice::callout( 'warning', '<p>' . esc_html__( 'The private key is stored unencrypted, because this site has no encryption key. Configure one under Settings → Advanced and generate a new signing key.', 'ffcertificate' ) . '</p>', array( 'classes' => array( 'inline' ) ) );
		}
	}

	$ffcertificate_retired = array_filter( $ffcertificate_keys['keys'], static fn( array $key ): bool => $key['retired'] > 0 );
	if ( array() !== $ffcertificate_retired ) {
		?>
		<h3><?php esc_html_e( 'Previous keys', 'ffcertificate' ); ?></h3>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Key', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Used from', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Until', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Signed certificates', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $ffcertificate_retired as $ffcertificate_key_id => $ffcertificate_key ) : ?>
					<tr>
						<td><code><?php echo esc_html( (string) $ffcertificate_key_id ); ?></code></td>
						<td><?php echo esc_html( \FreeFormCertificate\Core\DateFormatter::format_date( $ffcertificate_key['created'] ) ); ?></td>
						<td><?php echo esc_html( \FreeFormCertificate\Core\DateFormatter::format_date( $ffcertificate_key['retired'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $ffcertificate_key_counts[ $ffcertificate_key_id ] ?? 0 ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'A previous key is never deleted: it keeps verifying the certificates it signed.', 'ffcertificate' ); ?></p>
		<?php
	}

	if ( $ffcertificate_can_rotate ) {
		// The question is ffc-core's delegated `[data-confirm]` (#2535).
		$ffcertificate_key_button = '' === $ffcertificate_active_key
			? array(
				'label'    => __( 'Generate key', 'ffcertificate' ),
				'category' => 'primary',
				'icon'     => 'key',
				'href'     => $ffcertificate_rotate_url,
			)
			: array(
				'label'    => __( 'Rotate key', 'ffcertificate' ),
				'category' => 'caution',
				'icon'     => 'key',
				'href'     => $ffcertificate_rotate_url,
				'attrs'    => array(
					'data-confirm'          => __( "Generate a new signing key?\n\nCertificates issued from now on are signed with the new key. The current key is retired and keeps verifying the certificates it signed.", 'ffcertificate' ),
					'data-confirm-category' => 'caution',
				),
			);
		echo '<p>' . \FreeFormCertificate\Admin\AdminUI::button( $ffcertificate_key_button ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Button::html() escapes every value.
	} else {
		echo '<p class="description">' . esc_html__( 'Generating or rotating the key needs the Danger Zone capability.', 'ffcertificate' ) . '</p>';
	}
	?>
</div>

</div><!-- .ffc-settings-wrap -->
