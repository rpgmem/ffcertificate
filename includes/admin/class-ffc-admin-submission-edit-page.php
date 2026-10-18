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
	 * Answer keys the edit form neither shows nor posts, carried over from
	 * the stored answers on save: the grading records of a quiz attempt
	 * (#1732), which are lists the text fields of this form would flatten.
	 * The save rebuilds the answers from the POST, so without this they would
	 * be lost by any save.
	 *
	 * @var array<int, string>
	 */
	private const PRESERVED_KEYS = array( '_quiz_pending', '_quiz_grades' );

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
	 * Whether the current user may reveal this submission's PII, resolved
	 * once per render by {@see self::reveal_context()}.
	 *
	 * @var bool|null
	 */
	private $can_reveal = null;

	/**
	 * The `ffc_reveal_pii_nonce` the reveal buttons carry, or '' when the
	 * user may not reveal.
	 *
	 * @var string
	 */
	private $reveal_nonce = '';

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
			\FreeFormCertificate\Core\AdminNotice::render(
				'error',
				esc_html__( 'You do not have permission to edit submissions.', 'ffcertificate' )
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
						$this->render_grading_card();
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
			<?php $this->render_grading_form(); ?>
		</div>
		<?php
	}

	/**
	 * Whether this attempt waits for grading and the current user may grade it.
	 */
	private function shows_grading(): bool {
		return \FreeFormCertificate\Core\SubmissionStatus::QUIZ_PENDING === ( $this->sub_array['status'] ?? '' )
			&& QuizGradingHandler::current_user_can_grade();
	}

	/**
	 * Main column: the open answers waiting for a grade (#1732).
	 *
	 * Its inputs belong to the grading form printed after the edit form, through
	 * their `form` attribute: forms cannot nest, and the edit form's save must
	 * not decide a result.
	 */
	private function render_grading_card(): void {
		if ( ! $this->shows_grading() ) {
			return;
		}
		$pending = QuizGrading::pending_questions( $this->data, (array) $this->fields );
		$config  = get_post_meta( (int) $this->sub_array['form_id'], '_ffc_form_config', true );
		$passing = \FreeFormCertificate\Core\ArrayValue::int( is_array( $config ) ? $config : array(), 'quiz_passing_score', 70 );

		self::card_open( __( 'Grade open answers', 'ffcertificate' ), 'edit' );
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: points earned by the automatically scored questions, 2: maximum points, 3: passing percentage */
				__( 'The automatically scored questions earned %1$d of %2$d points. The passing score is %3$d%%.', 'ffcertificate' ),
				\FreeFormCertificate\Core\ArrayValue::int( $this->data, '_quiz_score', 0 ),
				\FreeFormCertificate\Core\ArrayValue::int( $this->data, '_quiz_max_score', 0 ),
				$passing
			)
		) . '</p>';

		foreach ( $pending as $name => $question ) {
			$id = 'ffc-grade-' . sanitize_key( $name );
			?>
			<div class="ffc-grade-question">
				<p><strong><?php echo esc_html( $question['label'] ); ?></strong></p>
				<blockquote class="ffc-grade-answer"><?php echo '' !== $question['answer'] ? nl2br( esc_html( $question['answer'] ) ) : esc_html__( '(no answer)', 'ffcertificate' ); ?></blockquote>
				<p>
					<label for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Points', 'ffcertificate' ); ?></label>
					<input type="number" id="<?php echo esc_attr( $id ); ?>" name="ffc_grade[<?php echo esc_attr( $name ); ?>]" form="ffc-grade-form" min="0" max="<?php echo esc_attr( (string) $question['worth'] ); ?>" step="1" required class="small-text">
					<?php
					/* translators: %d: points the question is worth */
					echo esc_html( sprintf( __( 'of %d', 'ffcertificate' ), $question['worth'] ) );
					?>
				</p>
			</div>
			<?php
		}
		?>
		<p class="ffc-edit-actions">
			<?php
			AdminUI::render_button(
				array(
					'label'    => __( 'Save grades and send the result', 'ffcertificate' ),
					'category' => 'primary',
					'icon'     => 'save',
					'type'     => 'submit',
					'attrs'    => array(
						'form' => 'ffc-grade-form',
					),
				)
			);
			?>
		</p>
		<p class="description"><?php esc_html_e( 'Saving decides the attempt: a pass issues the document, and the participant is emailed the result either way.', 'ffcertificate' ); ?></p>
		</div>
		<?php
	}

	/**
	 * The grading form the card's inputs post through, outside the edit form.
	 */
	private function render_grading_form(): void {
		if ( ! $this->shows_grading() ) {
			return;
		}
		$id = (int) $this->sub_array['id'];
		?>
		<form id="ffc-grade-form" method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( QuizGradingHandler::ACTION ); ?>">
			<input type="hidden" name="submission_id" value="<?php echo esc_attr( (string) $id ); ?>">
			<?php wp_nonce_field( QuizGradingHandler::nonce_action( $id ) ); ?>
		</form>
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
	 * Resolve, once per render, whether the current user may reveal this
	 * submission's PII, and the nonce the reveal buttons carry. One gate for
	 * every masked value on the screen: email, CPF/RF and both IP displays.
	 */
	private function reveal_context(): bool {
		if ( null === $this->can_reveal ) {
			$this->can_reveal   = \FreeFormCertificate\Core\PiiAccessPolicy::can_reveal(
				'ffc_view_certificates_pii',
				(int) ( $this->sub_array['user_id'] ?? 0 )
			);
			$this->reveal_nonce = $this->can_reveal ? wp_create_nonce( 'ffc_reveal_pii_nonce' ) : '';
		}
		return $this->can_reveal;
	}

	/**
	 * The audited "Reveal" button for one masked field, or '' when the user
	 * may not reveal. Every reveal button on the screen comes from here, so
	 * they share one size and one gate.
	 *
	 * @param string $field Field the endpoint reveals: email, cpf, rf, ip or
	 *                      account_email (the linked account's, #1824).
	 * @return string Escaped HTML.
	 */
	private function reveal_button( string $field ): string {
		if ( ! $this->reveal_context() ) {
			return '';
		}
		return \FreeFormCertificate\Core\PiiReveal::field_button(
			array(
				'field'         => $field,
				'submission-id' => (string) ( $this->sub_array['id'] ?? 0 ),
				'nonce'         => $this->reveal_nonce,
			)
		);
	}

	/**
	 * A masked, read-only value on the row of its reveal button.
	 *
	 * The shared reveal script writes the clear value into the input it finds
	 * by `data-ffc-pii-field` inside the button's `.ffc-pii-row`, so the value
	 * stays an input, read-only and drawn as text.
	 *
	 * @param string $field  Field the endpoint reveals.
	 * @param string $masked The masked value.
	 * @param string $label  Accessible name of the value.
	 * @return string Escaped HTML.
	 */
	private function masked_value_row( string $field, string $masked, string $label ): string {
		return sprintf(
			'<span class="ffc-pii-row"><input type="text" value="%1$s" class="ffc-input-readonly ffc-edit-plain" data-ffc-pii-field="%2$s" readonly aria-label="%3$s">%4$s</span>',
			esc_attr( $masked ),
			esc_attr( $field ),
			esc_attr( $label ),
			$this->reveal_button( $field )
		);
	}

	/**
	 * The linked account's email, masked, with the same gate and button as
	 * the submission's own values (#1824). The clear address is fetched
	 * through `ffc_reveal_pii` with `field=account_email`, never printed.
	 *
	 * @param string $email The linked account's email.
	 * @return string Escaped HTML.
	 */
	private function masked_account_email( string $email ): string {
		$masked = \FreeFormCertificate\Core\DocumentFormatter::mask_email( $email );
		if ( $masked === $email ) {
			// mask_email() hands back an address it cannot parse unchanged.
			$masked = '' === $email ? '' : '***';
		}
		return sprintf(
			'<span class="ffc-pii-row"><span class="ffc-pii-value" data-field="account_email">%1$s</span>%2$s</span>',
			esc_html( $masked ),
			'' !== $email ? ' ' . $this->reveal_button( 'account_email' ) : ''
		);
	}

	/**
	 * The stored IP the submission and its consent were recorded from,
	 * already decrypted by the handler. One value, shown in two sections.
	 */
	private function stored_ip(): string {
		return (string) ( $this->sub_array['user_ip'] ?? '' );
	}

	/**
	 * Side panel: save and cancel.
	 */
	private function render_actions_card(): void {
		self::card_open( __( 'Save', 'ffcertificate' ), 'save' );
		?>
		<p class="ffc-edit-actions">
			<?php
			AdminUI::render_button(
				array(
					'label'    => __( 'Save Changes', 'ffcertificate' ),
					'category' => 'primary',
					'icon'     => 'save',
					'type'     => 'submit',
				)
			);
			AdminUI::render_button(
				array(
					'label' => __( 'Cancel', 'ffcertificate' ),
					'icon'  => 'cross',
					'href'  => admin_url( 'edit.php?post_type=ffc_form&page=ffc-submissions' ),
				)
			);
			?>
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
			<?php
			AdminUI::render_button(
				array(
					'label' => __( 'Copy', 'ffcertificate' ),
					'icon'  => 'copy',
					'class' => 'ffc-copy-magic-link',
					'attrs' => array(
						'data-url' => $magic_link,
					),
				)
			);
			AdminUI::render_button(
				array(
					'label' => __( 'Open certificate', 'ffcertificate' ),
					'icon'  => 'external',
					'href'  => $magic_link,
					'attrs' => array(
						'target' => '_blank',
						'rel'    => 'noopener',
					),
				)
			);
			?>
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
				<strong><?php esc_html_e( 'Warning:', 'ffcertificate' ); ?></strong>
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
		\FreeFormCertificate\Core\AdminNotice::callout(
			'warning',
			(string) ob_get_clean(),
			array( 'classes' => array( 'ffc-edited-notice' ) )
		);
	}

	/**
	 * Render system information section
	 *
	 * Displays ID, date, status, magic token, user IP. The IP starts masked,
	 * behind the same audited Reveal as email and CPF/RF (#1760).
	 */
	private function render_system_info_section(): void {
		$formatted_date = isset( $this->sub_array['submission_date'] )
			? \FreeFormCertificate\Core\DateFormatter::format_datetime( $this->sub_array['submission_date'] )
			: __( 'Unknown', 'ffcertificate' );

		\FreeFormCertificate\Admin\AdminUI::render_section_open(
			array(
				'title' => __( 'System Information', 'ffcertificate' ),
				'icon'  => 'info',
				'open'  => true,
			)
		);
		echo '<dl class="ffc-facts">';
		self::fact( __( 'Submission ID', 'ffcertificate' ), (string) $this->sub_array['id'] );
		self::fact( __( 'Submission Date', 'ffcertificate' ), (string) $formatted_date );
		self::fact( __( 'Status', 'ffcertificate' ), (string) $this->sub_array['status'] );
		if ( ! empty( $this->sub_array['magic_token'] ) ) {
			self::fact( __( 'Magic Link Token', 'ffcertificate' ), (string) $this->sub_array['magic_token'] );
		}
		$ip = $this->stored_ip();
		if ( '' !== $ip ) {
			printf(
				'<dt class="ffc-facts__label">%1$s</dt><dd class="ffc-facts__value">%2$s%3$s</dd>',
				esc_html__( 'User IP', 'ffcertificate' ),
				$this->masked_value_row( 'ip', \FreeFormCertificate\Core\DocumentFormatter::mask_ip( $ip ), __( 'User IP', 'ffcertificate' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- masked_value_row() escapes every value.
				empty( $this->sub_array['user_ip_encrypted'] ) ? '' : '<span class="description ffc-edit-note ffc-icon-lock">' . esc_html__( 'This IP is encrypted in the database.', 'ffcertificate' ) . '</span>'
			);
		}
		echo '</dl>';
		\FreeFormCertificate\Admin\AdminUI::render_section_close();
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
									<?php
									/*
									 * The linked account's own email, masked for every tier
									 * like the submission's (#1824). It is the account's
									 * field, not a submission column, so the endpoint serves
									 * it as `account_email`; a field name of its own also
									 * keeps the script from writing it into the submission's
									 * email input on the same screen.
									 */
									?>
									<span class="ffc-user-email">(<?php echo $this->masked_account_email( (string) $current_user->user_email ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- masked_account_email() escapes every value. ?>)</span>
									<span class="ffc-user-id">ID: <?php echo esc_html( (string) $current_user_id ); ?></span>
								</span>
								<?php
								AdminUI::render_button(
									array(
										'label' => __( 'View Profile', 'ffcertificate' ),
										'icon'  => 'external',
										'href'  => (string) get_edit_user_link( $current_user_id ),
										'attrs' => array(
											'target' => '_blank',
										),
									)
								);
								?>
							</div>
							<div class="ffc-unlink-action">
								<?php
								AdminUI::render_button(
									array(
										'label'    => __( 'Unlink User', 'ffcertificate' ),
										'category' => 'caution',
										'icon'     => 'unlink',
										'class'    => 'ffc-unlink-user-btn',
										'attrs'    => array(
											'data-confirm' => __( 'Are you sure you want to unlink this user from the submission?', 'ffcertificate' ),
										),
									)
								);
								AdminUI::render_button(
									array(
										'label' => __( 'Link to Another User', 'ffcertificate' ),
										'icon'  => 'link',
										'class' => 'ffc-relink-user-btn',
									)
								);
								?>
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
							<?php
							AdminUI::render_button(
								array(
									'label' => __( 'Search', 'ffcertificate' ),
									'icon'  => 'search',
									'class' => 'ffc-search-user-btn',
									'attrs' => array(
										'data-nonce' => $nonce,
									),
								)
							);
							?>
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
		$consent_ip   = $this->stored_ip();

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

				<?php if ( '' !== $consent_ip ) : ?>
					<p class="description ffc-consent-ip">
						<?php esc_html_e( 'IP:', 'ffcertificate' ); ?>
						<?php echo $this->masked_value_row( 'ip', \FreeFormCertificate\Core\DocumentFormatter::mask_ip( $consent_ip ), __( 'Consent IP', 'ffcertificate' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- masked_value_row() escapes every value. ?>
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
	 * disabled, is not posted, and the save keeps the stored address. Each
	 * Reveal sits on its value's row (#1760).
	 */
	private function render_participant_data_section(): void {
		\FreeFormCertificate\Admin\AdminUI::render_section_open(
			array(
				'title' => __( 'Participant Data', 'ffcertificate' ),
				'icon'  => 'user',
				'open'  => true,
			)
		);
		$ffc_email = (string) $this->sub_array['email'];
		?>
		<div class="ffc-edit-field">
			<label for="user_email"><?php esc_html_e( 'Email', 'ffcertificate' ); ?> <span class="ffc-required" aria-hidden="true">*</span></label>
			<span class="ffc-pii-row">
				<input type="email" name="user_email" id="user_email" value="<?php echo esc_attr( \FreeFormCertificate\Core\DocumentFormatter::mask_email( $ffc_email ) ); ?>" class="regular-text" data-ffc-pii-field="email" data-ffc-pii-editable="1" disabled required>
				<?php echo '' !== $ffc_email ? $this->reveal_button( 'email' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- reveal_button() escapes every value. ?>
			</span>
			<?php if ( $this->reveal_context() && '' !== $ffc_email ) : ?>
				<span class="description ffc-edit-note ffc-pii-reveal-hint"><?php esc_html_e( 'Reveal the email to edit it.', 'ffcertificate' ); ?></span>
			<?php endif; ?>
			<?php if ( ! empty( $this->sub_array['email_encrypted'] ) ) : ?>
				<span class="description ffc-edit-note ffc-icon-lock"><?php esc_html_e( 'This email is encrypted in the database.', 'ffcertificate' ); ?></span>
			<?php endif; ?>
		</div>
		<dl class="ffc-facts">
		<?php
		if ( ! empty( $this->sub_array['cpf_rf'] ) ) :
			$ffc_is_rf       = ! empty( $this->sub_array['rf'] );
			$ffc_pii_field   = $ffc_is_rf ? 'rf' : 'cpf';
			$ffc_pii_label   = $ffc_is_rf ? __( 'RF', 'ffcertificate' ) : __( 'CPF', 'ffcertificate' );
			$ffc_pii_display = $ffc_is_rf
				? \FreeFormCertificate\Core\DocumentFormatter::mask_rf( $this->sub_array['cpf_rf'] )
				: \FreeFormCertificate\Core\DocumentFormatter::mask_cpf( $this->sub_array['cpf_rf'] );
			?>
			<dt class="ffc-facts__label"><?php echo esc_html( $ffc_pii_label ); ?></dt>
			<dd class="ffc-facts__value">
				<?php echo $this->masked_value_row( $ffc_pii_field, $ffc_pii_display, $ffc_pii_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- masked_value_row() escapes every value. ?>
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
		<?php
		\FreeFormCertificate\Admin\AdminUI::render_section_close();
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
		// Protected fields (read-only within JSON). The window-leave count
		// is the browser's record of the attempt, not an answer (#1731).
		$protected_json_fields = array( 'auth_code', 'fill_date', 'ticket', '_quiz_focus_losses' );
		$internal_labels       = array(
			'_quiz_focus_losses' => __( 'Times the quiz window was left', 'ffcertificate' ),
		);

		\FreeFormCertificate\Admin\AdminUI::render_section_open(
			array(
				'title' => __( 'Form answers', 'ffcertificate' ),
				'icon'  => 'list',
				'open'  => true,
			)
		);

		$editable  = '';
		$protected = '';
		foreach ( $this->data as $k => $v ) {
			// Skip old tracking fields (now in columns), and the grading
			// records, which are lists a text field would flatten (#1732).
			if ( 'is_edited' === $k || 'edited_at' === $k || in_array( $k, self::PRESERVED_KEYS, true ) ) {
				continue;
			}

			// Get field label.
			$lbl = $internal_labels[ $k ] ?? $k;
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
		\FreeFormCertificate\Admin\AdminUI::render_section_close();
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

		$clean_data = array_merge( $clean_data, $this->preserved_answers( $id ) );

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

	/**
	 * The stored answers under {@see self::PRESERVED_KEYS}.
	 *
	 * @param int $id Submission ID.
	 * @return array<string, mixed>
	 */
	private function preserved_answers( int $id ): array {
		$sub = $this->submission_handler->get_submission( $id );
		if ( ! is_array( $sub ) || ! is_string( $sub['data'] ?? null ) ) {
			return array();
		}
		$stored = json_decode( $sub['data'], true );
		return is_array( $stored ) ? array_intersect_key( $stored, array_flip( self::PRESERVED_KEYS ) ) : array();
	}
}
