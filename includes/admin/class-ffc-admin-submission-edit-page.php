<?php
/**
 * AdminSubmissionEditPage
 *
 * Manages the submission edit page rendering and saving.
 * Extracted from FFC_Admin class to follow Single Responsibility Principle.
 *
 * @package FreeFormCertificate\Admin
 * @since 3.2.0 (Extracted from FFC_Admin)
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Submissions\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page for admin submission edit.
 */
class AdminSubmissionEditPage {

	/**
	 * Submission handler instance
	 *
	 * @var \FreeFormCertificate\Submissions\SubmissionHandler
	 */
	private $submission_handler;

	/**
	 * Submission data (array format)
	 *
	 * @var array<string, mixed>
	 */
	private $sub_array;

	/**
	 * Decoded JSON data
	 *
	 * @var array<string, mixed>
	 */
	private $data;

	/**
	 * Form fields configuration
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $fields;

	/**
	 * Constructor
	 *
	 * @param SubmissionHandler $handler Submission handler instance.
	 */
	public function __construct( SubmissionHandler $handler ) {
		$this->submission_handler = $handler;
	}

	/**
	 * Check if current user can edit submissions
	 *
	 * @since 4.3.0
	 * @return bool True if user can edit submissions
	 */
	private function can_edit_submission(): bool {
		return \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_edit_certificates' );
	}

	/**
	 * Render the edit page
	 *
	 * Main entry point that delegates to specialized render methods.
	 *
	 * @param int $submission_id Submission ID to edit.
	 */
	public function render( int $submission_id ): void {
		// Permission check.
		if ( ! $this->can_edit_submission() ) {
			echo '<div class="wrap ffc-admin-page ffc-page-submissions">';
			wp_admin_notice(
				esc_html__( 'You do not have permission to edit submissions.', 'ffcertificate' ),
				array( 'type' => 'error' )
			);
			echo '</div>';
			return;
		}

		// Get submission data.
		$sub = $this->submission_handler->get_submission( $submission_id );

		if ( ! $sub ) {
			echo '<div class="wrap ffc-admin-page ffc-page-submissions"><p>' . esc_html__( 'Submission not found.', 'ffcertificate' ) . '</p></div>';
			return;
		}

		// Prepare data (convert form_id to int - wpdb returns strings).
		$this->sub_array = (array) $sub;
		$decoded_data    = json_decode( $this->sub_array['data'], true );
		$this->data      = $decoded_data ? $decoded_data : array();
		$this->fields    = get_post_meta( (int) $this->sub_array['form_id'], '_ffc_form_fields', true );

		// Render page.
		?>
		<div class="wrap ffc-admin-page ffc-page-submissions">
			<h1>
			<?php
				/* translators: %s: submission ID */
				echo esc_html( sprintf( __( 'Edit Submission #%s', 'ffcertificate' ), $this->sub_array['id'] ) );
			?>
			</h1>

			<?php $this->render_edit_warning(); ?>

			<form method="POST" class="ffc-edit-submission-form">
				<?php wp_nonce_field( 'ffc_edit_submission_nonce', 'ffc_edit_submission_action' ); ?>
				<input type="hidden" name="submission_id" value="<?php echo esc_attr( $this->sub_array['id'] ); ?>">
				<?php
				/*
				 * The marker `handle_save()` gates on is a FIELD, not the submit
				 * button's name.
				 *
				 * It was the button's `name`, and a submit button contributes its
				 * name/value only when the browser ACTIVATES it. The unlink
				 * control calls `form.submit()` instead, which submits with no
				 * activated button -- so the POST arrived without the marker,
				 * `handle_save()` returned on its first line, and unlinking a
				 * user did nothing at all, silently, for as long as the button
				 * existed. Every test of this handler set the key by hand, which
				 * is why none of them could see it.
				 */
				?>
				<input type="hidden" name="ffc_save_edit" value="1">

				<div class="ffc-edit-layout">
					<div class="ffc-edit-main">
						<?php
						$this->render_participant_data_section();
						$this->render_dynamic_fields();
						$this->render_system_info_section();
						$this->render_consent_section();
						?>
					</div>
					<div class="ffc-edit-side">
						<?php
						$this->render_actions_card();
						$this->render_magic_link_card();
						$this->render_user_link_section();
						?>
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Open a card of the edit layout, with an icon heading.
	 *
	 * @param string $title Card heading.
	 * @param string $icon  `.ffc-icon-*` class name, without the prefix.
	 */
	private static function card_open( string $title, string $icon ): void {
		printf(
			'<div class="ffc-edit-card"><h2 class="ffc-edit-card__title ffc-icon-%s">%s</h2>',
			esc_attr( $icon ),
			esc_html( $title )
		);
	}

	/**
	 * One read-only row of a facts list: a label and its value as text.
	 *
	 * A value nobody can edit reads as text, not as a disabled input that
	 * looks like a field to fill in (#1614).
	 *
	 * @param string $label Row label.
	 * @param string $value Plain-text value.
	 * @param string $note  Optional note under the value.
	 */
	private static function fact( string $label, string $value, string $note = '' ): void {
		printf(
			'<dt class="ffc-facts__label">%s</dt><dd class="ffc-facts__value">%s%s</dd>',
			esc_html( $label ),
			esc_html( $value ),
			'' !== $note ? '<span class="description ffc-edit-note">' . esc_html( $note ) . '</span>' : ''
		);
	}

	/**
	 * Side panel: save and cancel.
	 */
	private function render_actions_card(): void {
		self::card_open( __( 'Save', 'ffcertificate' ), 'checkmark' );
		?>
		<p class="ffc-edit-actions">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'ffcertificate' ); ?></button>
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ffc_form&page=ffc-submissions' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'ffcertificate' ); ?></a>
		</p>
		</div>
		<?php
	}

	/**
	 * Side panel: the magic link, with copy and open.
	 */
	private function render_magic_link_card(): void {
		$magic_token = isset( $this->sub_array['magic_token'] ) ? (string) $this->sub_array['magic_token'] : '';
		self::card_open( __( 'Magic Link', 'ffcertificate' ), 'link' );
		if ( '' === $magic_token ) {
			echo '<p class="description">' . esc_html__( 'Submission created before magic links', 'ffcertificate' ) . '</p></div>';
			return;
		}
		$magic_link = \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( $magic_token );
		?>
		<p class="ffc-edit-magic-link">
			<a href="<?php echo esc_url( $magic_link ); ?>" target="_blank" rel="noopener" class="ffc-magic-link"><?php echo esc_html( $magic_link ); ?></a>
		</p>
		<p class="ffc-edit-actions">
			<button type="button" class="button ffc-copy-magic-link ffc-icon-copy" data-url="<?php echo esc_attr( $magic_link ); ?>"><?php esc_html_e( 'Copy', 'ffcertificate' ); ?></button>
			<a href="<?php echo esc_url( $magic_link ); ?>" target="_blank" rel="noopener" class="button ffc-icon-external"><?php esc_html_e( 'Open certificate', 'ffcertificate' ); ?></a>
		</p>
		<p class="description"><?php esc_html_e( 'Anyone with this link can download the certificate.', 'ffcertificate' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render edit warning notice
	 *
	 * Shows if submission was previously edited.
	 */
	private function render_edit_warning(): void {
		$was_edited = ! empty( $this->sub_array['edited_at'] );

		if ( ! $was_edited ) {
			return;
		}

		// `edited_at` is unix UTC int since 6.6.0 (#249 sub-escopo d).
		$edited_at      = (int) $this->sub_array['edited_at'];
		$edited_by_id   = ! empty( $this->sub_array['edited_by'] ) ? (int) $this->sub_array['edited_by'] : 0;
		$edited_by_name = '';

		if ( $edited_by_id ) {
			$user           = get_userdata( $edited_by_id );
			$edited_by_name = $user ? $user->display_name : 'ID: ' . $edited_by_id;
		}

		ob_start();
		?>
			<p>
				<strong class="ffc-icon-warning"><?php esc_html_e( 'Warning:', 'ffcertificate' ); ?></strong>
				<?php
				echo wp_kses_post(
					sprintf(
					/* translators: %s: name */
						__( 'This record was manually edited on <strong>%s</strong>', 'ffcertificate' ),
						esc_html( \FreeFormCertificate\Core\DateFormatter::format_datetime( $edited_at ) )
					)
				);
				?>
				<?php if ( $edited_by_name ) : ?>
					<?php
					/* translators: %s: editor name */
					echo wp_kses_post( sprintf( __( ' by <strong>%s</strong>', 'ffcertificate' ), esc_html( $edited_by_name ) ) );
					?>
				<?php endif; ?>.
			</p>
		<?php
		wp_admin_notice(
			(string) ob_get_clean(),
			array(
				'type'               => 'warning',
				'additional_classes' => array( 'ffc-edited-notice' ),
				'paragraph_wrap'     => false,
			)
		);
	}

	/**
	 * Render system information section
	 *
	 * Displays ID, date, status, magic token, user IP.
	 */
	private function render_system_info_section(): void {
		$formatted_date = isset( $this->sub_array['submission_date'] )
			? \FreeFormCertificate\Core\DateFormatter::format_datetime( $this->sub_array['submission_date'] )
			: __( 'Unknown', 'ffcertificate' );

		self::card_open( __( 'System Information', 'ffcertificate' ), 'info' );
		echo '<dl class="ffc-facts">';
		self::fact( __( 'Submission ID', 'ffcertificate' ), (string) $this->sub_array['id'] );
		self::fact( __( 'Submission Date', 'ffcertificate' ), (string) $formatted_date );
		self::fact( __( 'Status', 'ffcertificate' ), (string) $this->sub_array['status'] );
		if ( ! empty( $this->sub_array['magic_token'] ) ) {
			self::fact( __( 'Magic Link Token', 'ffcertificate' ), (string) $this->sub_array['magic_token'] );
		}
		if ( ! empty( $this->sub_array['user_ip'] ) ) {
			self::fact(
				__( 'User IP', 'ffcertificate' ),
				(string) $this->sub_array['user_ip'],
				empty( $this->sub_array['user_ip_encrypted'] ) ? '' : __( 'This IP is encrypted in the database.', 'ffcertificate' )
			);
		}
		echo '</dl></div>';
	}

	/**
	 * Render user link section
	 *
	 * Simplified UI: Shows unlink button if linked, search field if not.
	 *
	 * @since 4.3.0
	 */
	private function render_user_link_section(): void {
		$current_user_id = isset( $this->sub_array['user_id'] ) ? (int) $this->sub_array['user_id'] : 0;
		$current_user    = $current_user_id ? get_userdata( $current_user_id ) : null;
		$nonce           = wp_create_nonce( 'ffc_user_search_nonce' );

		self::card_open( __( 'Linked User', 'ffcertificate' ), 'user' );
		?>
				<div class="ffc-user-link-container" data-submission-id="<?php echo esc_attr( $this->sub_array['id'] ); ?>">
					<?php
					/*
					 * ONE field carries the decision, and it has three states:
					 * `__keep__` (leave the link alone), `''` (unlink) and a
					 * user id (link or relink). The controls below all mutate
					 * this one input, which is why it is rendered once here and
					 * not inside either branch -- two inputs of the same name
					 * would let the later one silently win in the POST.
					 *
					 * It defaults to `__keep__` in BOTH states. When no user was
					 * linked it used to default to `''`, so every ordinary save
					 * of an unlinked submission called `update_user_link( id,
					 * null )`: a pointless write that bumped `edited_at` and
					 * logged a `user_unlinked` entry against a submission that
					 * never had a user.
					 */
					?>
					<input type="hidden" name="linked_user_id" id="ffc-selected-user-id" value="__keep__">

					<?php if ( $current_user ) : ?>
						<!-- User is linked: show info, unlink, and a way to relink. -->
						<div class="ffc-linked-user-display">
							<div class="ffc-current-user">
								<span class="ffc-user-info">
									<?php echo get_avatar( $current_user_id, 32 ); ?>
									<strong><?php echo esc_html( $current_user->display_name ); ?></strong>
									<span class="ffc-user-email">(<?php echo esc_html( $current_user->user_email ); ?>)</span>
									<span class="ffc-user-id">ID: <?php echo esc_html( (string) $current_user_id ); ?></span>
								</span>
								<a href="<?php echo esc_url( get_edit_user_link( $current_user_id ) ); ?>" target="_blank" class="button button-small ffc-icon-external">
									<?php esc_html_e( 'View Profile', 'ffcertificate' ); ?>
								</a>
							</div>
							<div class="ffc-unlink-action">
								<button type="button" class="button button-secondary ffc-unlink-user-btn" data-confirm="<?php esc_attr_e( 'Are you sure you want to unlink this user from the submission?', 'ffcertificate' ); ?>">
									<?php esc_html_e( 'Unlink User', 'ffcertificate' ); ?>
								</button>
								<button type="button" class="button button-secondary ffc-relink-user-btn">
									<?php esc_html_e( 'Link to Another User', 'ffcertificate' ); ?>
								</button>
								<p class="description">
									<?php esc_html_e( 'Removes the link between this submission and the WordPress user.', 'ffcertificate' ); ?>
									<?php esc_html_e( 'An unlinked submission has no account, which is the state the identity queue adopts.', 'ffcertificate' ); ?>
								</p>
								<p class="description">
									<?php esc_html_e( 'Choose another user to own this submission. The change is applied when you save.', 'ffcertificate' ); ?>
								</p>
							</div>
						</div>
					<?php endif; ?>

					<?php
					/*
					 * The search block is rendered in BOTH states. With a user
					 * linked it starts hidden and the relink button reveals it,
					 * so changing the account no longer means unlinking, saving,
					 * searching and saving again.
					 */
					?>
					<div class="ffc-user-search-container<?php echo $current_user ? ' ffc-hidden' : ''; ?>">
						<?php if ( ! $current_user ) : ?>
							<p class="ffc-no-user">
								<em><?php esc_html_e( 'No user linked to this submission.', 'ffcertificate' ); ?></em>
							</p>
						<?php endif; ?>
						<div class="ffc-user-search-form">
							<input type="text" id="ffc-user-search-input" class="regular-text" placeholder="<?php esc_attr_e( 'Search by name, email, ID or CPF/RF...', 'ffcertificate' ); ?>">
							<button type="button" class="button ffc-search-user-btn" data-nonce="<?php echo esc_attr( $nonce ); ?>">
								<?php esc_html_e( 'Search', 'ffcertificate' ); ?>
							</button>
							<span class="spinner" id="ffc-search-spinner"></span>
						</div>
						<div id="ffc-user-search-results" class="ffc-user-search-results ffc-hidden">
							<!-- Results will be populated via AJAX -->
						</div>
						<div id="ffc-selected-user-preview" class="ffc-selected-user-preview ffc-hidden">
							<!-- Selected user preview will be shown here -->
						</div>
						<p class="description">
							<?php esc_html_e( 'Search for a WordPress user to link to this submission. The user will see this certificate in their dashboard.', 'ffcertificate' ); ?>
						</p>
					</div>
				</div>
		</div>
		<?php
	}


	/**
	 * Render LGPD consent status section (collapsible)
	 *
	 * @since 4.3.0 Made collapsible
	 */
	private function render_consent_section(): void {
		$consent_given = isset( $this->sub_array['consent_given'] ) ? (int) $this->sub_array['consent_given'] : 0;
		// `consent_date` is unix UTC int since 6.6.0 (#249 sub-escopo d).
		$consent_date = ! empty( $this->sub_array['consent_date'] )
			? \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $this->sub_array['consent_date'] )
			: '';
		$consent_ip   = \FreeFormCertificate\Core\Encryption::decrypt_field( $this->sub_array, 'user_ip' );

		\FreeFormCertificate\Admin\AdminUI::render_section_open(
			array(
				'title' => __( 'LGPD Consent Status', 'ffcertificate' ),
				'icon'  => $consent_given ? 'check' : 'warning',
				'chip'  => $consent_given ? __( 'Consent given', 'ffcertificate' ) : __( 'No consent recorded', 'ffcertificate' ),
			)
		);
		?>
		<div class="ffc-consent-details <?php echo esc_attr( $consent_given ? 'ffc-consent-given' : 'ffc-consent-not-given' ); ?>">
			<?php if ( $consent_given ) : ?>
				<p>
					<strong><?php esc_html_e( 'Consent given:', 'ffcertificate' ); ?></strong>
					<?php esc_html_e( 'User explicitly agreed to data storage and privacy policy.', 'ffcertificate' ); ?>
				</p>
				<?php if ( $consent_date ) : ?>
					<p class="description">
						<?php
						/* translators: %s: consent date/time */
						echo esc_html( sprintf( __( 'Date: %s', 'ffcertificate' ), $consent_date ) );
						?>
					</p>
				<?php endif; ?>

				<?php if ( $consent_ip ) : ?>
					<p class="description">
						<?php
						/* translators: %s: IP address */
						echo esc_html( sprintf( __( 'IP: %s', 'ffcertificate' ), $consent_ip ) );
						?>
					</p>
				<?php endif; ?>

				<p class="description">
					<?php esc_html_e( 'Sensitive data (email, CPF/RF, IP) is encrypted in the database.', 'ffcertificate' ); ?>
				</p>
			<?php else : ?>
				<p>
					<strong><?php esc_html_e( 'No consent recorded:', 'ffcertificate' ); ?></strong>
					<?php esc_html_e( 'This submission was created before LGPD consent feature (v2.10.0).', 'ffcertificate' ); ?>
				</p>
				<p class="description">
					<?php esc_html_e( 'Older submissions do not have explicit consent flag but may have been collected under privacy policy.', 'ffcertificate' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
		\FreeFormCertificate\Admin\AdminUI::render_section_close();
	}

	/**
	 * Render participant data section
	 *
	 * Email, CPF/RF and auth code. Email and CPF/RF start masked for everyone,
	 * administrators included (#1655): the clear value never sits in the page,
	 * and a user who may see it fetches it through the audited "Reveal" button,
	 * so every disclosure on this screen leaves an Activity Log entry. The
	 * email becomes editable only once revealed; left masked, its input is
	 * disabled, is not posted, and the save keeps the stored address.
	 */
	private function render_participant_data_section(): void {
		$ffc_can_reveal   = \FreeFormCertificate\Core\PiiAccessPolicy::can_reveal(
			'ffc_view_certificates_pii',
			'ffc_certificates_admin',
			(int) ( $this->sub_array['user_id'] ?? 0 )
		);
		$ffc_reveal_nonce = $ffc_can_reveal ? wp_create_nonce( 'ffc_reveal_pii_nonce' ) : '';
		$ffc_record_id    = (string) ( $this->sub_array['id'] ?? 0 );

		self::card_open( __( 'Participant Data', 'ffcertificate' ), 'user' );
		?>
		<p class="ffc-edit-field">
			<label for="user_email"><?php esc_html_e( 'Email', 'ffcertificate' ); ?> *</label>
			<input type="email" name="user_email" id="user_email" value="<?php echo esc_attr( \FreeFormCertificate\Core\DocumentFormatter::mask_email( (string) $this->sub_array['email'] ) ); ?>" class="regular-text" data-ffc-pii-field="email" data-ffc-pii-editable="1" disabled required>
			<?php if ( $ffc_can_reveal && '' !== (string) $this->sub_array['email'] ) : ?>
				<button type="button" class="button button-small ffc-reveal-pii ffc-icon-eye"
					data-field="email"
					data-submission-id="<?php echo esc_attr( $ffc_record_id ); ?>"
					data-nonce="<?php echo esc_attr( $ffc_reveal_nonce ); ?>">
					<?php esc_html_e( 'Reveal', 'ffcertificate' ); ?>
				</button>
				<span class="description ffc-edit-note ffc-pii-reveal-hint"><?php esc_html_e( 'Reveal the email to edit it.', 'ffcertificate' ); ?></span>
			<?php endif; ?>
			<?php if ( ! empty( $this->sub_array['email_encrypted'] ) ) : ?>
				<span class="description ffc-edit-note ffc-icon-lock"><?php esc_html_e( 'This email is encrypted in the database.', 'ffcertificate' ); ?></span>
			<?php endif; ?>
		</p>
		<dl class="ffc-facts">
		<?php
		if ( ! empty( $this->sub_array['cpf_rf'] ) ) :
			$ffc_is_rf       = ! empty( $this->sub_array['rf'] );
			$ffc_pii_field   = $ffc_is_rf ? 'rf' : 'cpf';
			$ffc_pii_display = $ffc_is_rf
				? \FreeFormCertificate\Core\DocumentFormatter::mask_rf( $this->sub_array['cpf_rf'] )
				: \FreeFormCertificate\Core\DocumentFormatter::mask_cpf( $this->sub_array['cpf_rf'] );
			?>
			<dt class="ffc-facts__label"><?php echo esc_html( $ffc_is_rf ? __( 'RF', 'ffcertificate' ) : __( 'CPF', 'ffcertificate' ) ); ?></dt>
			<dd class="ffc-facts__value">
				<?php
				// The shared reveal script writes the clear value into the
				// input it finds by data-ffc-pii-field, so the value stays an
				// input — read-only and drawn as text.
				?>
				<input type="text" value="<?php echo esc_attr( $ffc_pii_display ); ?>" class="ffc-input-readonly ffc-edit-plain" data-ffc-pii-field="<?php echo esc_attr( $ffc_pii_field ); ?>" readonly aria-label="<?php echo esc_attr( $ffc_is_rf ? __( 'RF', 'ffcertificate' ) : __( 'CPF', 'ffcertificate' ) ); ?>">
				<?php if ( $ffc_can_reveal ) : ?>
					<button type="button" class="button button-small ffc-reveal-pii ffc-icon-eye"
						data-field="<?php echo esc_attr( $ffc_pii_field ); ?>"
						data-submission-id="<?php echo esc_attr( $ffc_record_id ); ?>"
						data-nonce="<?php echo esc_attr( $ffc_reveal_nonce ); ?>">
						<?php esc_html_e( 'Reveal', 'ffcertificate' ); ?>
					</button>
				<?php endif; ?>
				<?php if ( ! empty( $this->sub_array['cpf_encrypted'] ) || ! empty( $this->sub_array['rf_encrypted'] ) ) : ?>
					<span class="description ffc-edit-note ffc-icon-lock"><?php esc_html_e( 'This identifier is encrypted in the database.', 'ffcertificate' ); ?></span>
				<?php endif; ?>
			</dd>
		<?php endif; ?>
		<?php
		if ( ! empty( $this->sub_array['auth_code'] ) ) {
			self::fact(
				__( 'Auth Code', 'ffcertificate' ),
				\FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $this->sub_array['auth_code'], \FreeFormCertificate\Core\DocumentFormatter::PREFIX_CERTIFICATE ),
				__( 'Protected authentication code.', 'ffcertificate' )
			);
		}
		?>
		</dl>
		</div>
		<?php
	}

	/**
	 * Render dynamic fields from JSON data
	 *
	 * The form answers as editable fields. A protected internal field
	 * (auth code, fill date, ticket) reads as text, and travels in a hidden
	 * input: the save handler rebuilds the data from what is posted, so a
	 * field left out of the POST would be deleted.
	 */
	private function render_dynamic_fields(): void {
		// Protected fields (read-only within JSON).
		$protected_json_fields = array( 'auth_code', 'fill_date', 'ticket' );

		self::card_open( __( 'Form answers', 'ffcertificate' ), 'list' );

		$editable  = '';
		$protected = '';
		foreach ( $this->data as $k => $v ) {
			// Skip old tracking fields (now in columns).
			if ( 'is_edited' === $k || 'edited_at' === $k ) {
				continue;
			}

			// Get field label.
			$lbl = $k;
			foreach ( (array) $this->fields as $f ) {
				if ( isset( $f['name'] ) && $f['name'] === $k ) {
					$lbl = $f['label'];
				}
			}

			$display_value = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
			$name          = 'data[' . $k . ']';

			if ( in_array( $k, $protected_json_fields, true ) ) {
				$protected .= sprintf(
					'<dt class="ffc-facts__label">%1$s</dt><dd class="ffc-facts__value">%2$s<input type="hidden" name="%3$s" value="%4$s"><span class="description ffc-edit-note">%5$s</span></dd>',
					esc_html( (string) $lbl ),
					esc_html( $display_value ),
					esc_attr( $name ),
					esc_attr( $display_value ),
					esc_html__( 'Protected internal field.', 'ffcertificate' )
				);
				continue;
			}

			$editable .= sprintf(
				'<p class="ffc-edit-field"><label for="%1$s">%2$s</label><input type="text" id="%1$s" name="%3$s" value="%4$s" class="regular-text"></p>',
				esc_attr( 'ffc-edit-data-' . sanitize_key( (string) $k ) ),
				esc_html( (string) $lbl ),
				esc_attr( $name ),
				esc_attr( $display_value )
			);
		}

		if ( '' === $editable && '' === $protected ) {
			echo '<p class="description">' . esc_html__( 'This submission has no form answers.', 'ffcertificate' ) . '</p>';
		}
		echo $editable; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value escaped where the markup is built above.
		if ( '' !== $protected ) {
			echo '<dl class="ffc-facts ffc-edit-protected">' . $protected . '</dl>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value escaped where the markup is built above.
		}
		echo '</div>';
	}

	/**
	 * Handle save request
	 *
	 * Processes submission edit form POST request.
	 */
	public function handle_save(): void {
		if ( ! isset( $_POST['ffc_save_edit'] ) ) {
			return;
		}

		// Permission check.
		if ( ! $this->can_edit_submission() ) {
			wp_die( esc_html__( 'You do not have permission to edit submissions.', 'ffcertificate' ) );
		}

		/**
		 * The stub types check_admin_referer() as always-truthy because it
		 * wp_die()s; the early return is defence-in-depth and is covered by
		 * test_handle_save_returns_on_bad_nonce.
		 *
		 * @phpstan-ignore booleanNot.alwaysFalse
		 */
		if ( ! check_admin_referer( 'ffc_edit_submission_nonce', 'ffc_edit_submission_action' ) ) {
			return;
		}

		$id = isset( $_POST['submission_id'] ) ? absint( wp_unslash( $_POST['submission_id'] ) ) : 0;
		// Normalize email to lowercase for consistent storage and lookups.
		$new_email = isset( $_POST['user_email'] ) ? \FreeFormCertificate\Core\DataSanitizer::normalize_email( sanitize_email( wp_unslash( $_POST['user_email'] ) ) ) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field sanitized individually below.
		$raw_data   = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array();
		$clean_data = array();

		// Name fields that should be normalized (capitalized with lowercase
		// connectives). The key list is `SubmitterName`'s, not this file's: it
		// had been written at five sites and two of them had already dropped a
		// key (#1480).
		$name_fields = \FreeFormCertificate\Core\SubmitterName::CANDIDATE_KEYS;

		foreach ( $raw_data as $k => $v ) {
			$sanitized_key   = sanitize_key( $k );
			$sanitized_value = wp_kses( $v, \FreeFormCertificate\Core\HtmlPolicy::get_allowed_html_tags() );

			// Normalize name fields (proper capitalization with lowercase connectives).
			if ( in_array( $sanitized_key, $name_fields, true ) && ! empty( $sanitized_value ) ) {
				$sanitized_value = \FreeFormCertificate\Core\DataSanitizer::normalize_brazilian_name( $sanitized_value );
			}

			$clean_data[ $sanitized_key ] = $sanitized_value;
		}

		// Process user link change (simplified: value is user ID, empty string, or __keep__).
		$linked_user_id = \FreeFormCertificate\Core\RequestInput::get_post_string( 'linked_user_id', '__keep__' );

		// Update submission data (email + custom fields).
		$this->submission_handler->update_submission( $id, $new_email, $clean_data );

		// Update user link if changed (not __keep__).
		if ( '__keep__' !== $linked_user_id ) {
			$new_user_id = '' === $linked_user_id ? null : (int) $linked_user_id;

			// Validate user exists if linking to a user.
			if ( null !== $new_user_id && ! get_userdata( $new_user_id ) ) {
				// Invalid user ID - skip user link update.
				\FreeFormCertificate\Core\Debug::log_admin(
					'Invalid user ID for linking',
					array(
						'submission_id' => $id,
						'user_id'       => $new_user_id,
					)
				);
			} else {
				$this->submission_handler->update_user_link( $id, $new_user_id );
			}
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=ffc_form&page=ffc-submissions&msg=updated' ) );
		exit;
	}
}
