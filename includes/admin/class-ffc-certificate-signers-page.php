<?php
/**
 * CertificateSignersPage
 *
 * Certificates → Signers: the people a form can name as its signer (#2582).
 *
 * @package FreeFormCertificate\Admin
 * @since   6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\CertificateSigners;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The signer list screen and its save handler.
 */
final class CertificateSignersPage {

	public const MENU_SLUG = 'ffc-certificate-signers';

	/** Who sees the screen: the form editor's viewers. */
	public const VIEW_CAP = 'ffc_view_forms';

	/** Who adds and edits signers: the cap that manages templates. */
	public const MANAGE_CAP = 'ffc_manage_forms';

	/** Who reveals a signer's RF on this screen. */
	public const PII_CAP = 'ffc_view_certificates_pii';

	/** The reveal type of a signer's RF ({@see AdminAjax::pii_reveal_configs()}). */
	public const REVEAL_TYPE = 'certificate_signer';

	/** The save action, on `admin-post.php`. */
	public const SAVE_ACTION = 'ffc_save_certificate_signer';

	/** The account search behind Add signer (#2622), on `admin-ajax.php`. */
	public const SEARCH_ACTION = 'ffc_search_signer_candidates';

	/** How many accounts one search returns. */
	private const SEARCH_LIMIT = 20;

	/** The shortest term a search runs on. */
	private const SEARCH_MIN_LENGTH = 2;

	/**
	 * Hook the menu and the save handler.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::SEARCH_ACTION, array( $this, 'ajax_search_candidates' ) );
	}

	/**
	 * Register the submenu under Certificates.
	 */
	public function register_menu(): void {
		add_submenu_page(
			CertificatesDashboard::PARENT,
			__( 'Signers', 'ffcertificate' ),
			__( 'Signers', 'ffcertificate' ),
			self::VIEW_CAP,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * The media picker and the reveal script, on this screen only.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, self::MENU_SLUG ) ) {
			return;
		}
		wp_enqueue_media();
		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-branding-media' );
		wp_localize_script( 'ffc-branding-media', 'ffcBrandingMedia', array( 'chooseImage' => __( 'Select image', 'ffcertificate' ) ) );
		\FreeFormCertificate\Core\PiiReveal::enqueue_assets();
		if ( self::can_manage() ) {
			$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
			\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-core' );
			wp_enqueue_script( 'ffc-signer-user-search', FFC_PLUGIN_URL . "assets/js/ffc-signer-user-search{$s}.js", array( 'ffc-core' ), FFC_VERSION, true );
			wp_localize_script(
				'ffc-signer-user-search',
				'ffcSignerUserSearch',
				array(
					'action'   => self::SEARCH_ACTION,
					'nonce'    => wp_create_nonce( self::SEARCH_ACTION ),
					'minChars' => self::SEARCH_MIN_LENGTH,
					'strings'  => array(
						'searching' => __( 'Searching…', 'ffcertificate' ),
						'none'      => __( 'No account with an RF matches.', 'ffcertificate' ),
						/* translators: %d: number of accounts found */
						'found'     => __( '%d accounts found.', 'ffcertificate' ),
						'failed'    => __( 'The search failed. Try again.', 'ffcertificate' ),
						'choose'    => __( 'Choose a WordPress user.', 'ffcertificate' ),
					),
				)
			);
		}
	}

	/**
	 * Whether the current user may manage signers.
	 */
	private static function can_manage(): bool {
		return \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( self::MANAGE_CAP );
	}

	/**
	 * Render the screen.
	 */
	public function render_page(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( self::VIEW_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ffcertificate' ) );
		}
		$signers = CertificateSigners::all();
		$counts  = CertificateSigners::form_counts();
		$editing = self::can_manage() ? CertificateSigners::get( RequestInput::get_get_int( 'edit' ) ) : null;
		$reveal  = \FreeFormCertificate\Core\PiiAccessPolicy::TIER_MASKED !== \FreeFormCertificate\Core\PiiAccessPolicy::resolve( self::PII_CAP, null );
		$nonce   = $reveal ? wp_create_nonce( 'ffc_reveal_pii_nonce' ) : '';
		?>
		<div class="wrap ffc-admin-page ffc-page-certificate-signers ffc-boxed">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Signers', 'ffcertificate' ); ?></h1>
			<hr class="wp-header-end">
			<?php $this->render_notice(); ?>

			<div class="card">
				<h2 class="ffc-icon-users"><?php esc_html_e( 'Who can sign certificates', 'ffcertificate' ); ?></h2>
				<p class="description"><?php esc_html_e( 'A form names one of these people as its signer. Listing someone here lets their RF print in full on the certificates they sign, so adding a signer is recorded in the activity log. An inactive signer leaves the choice for new forms; forms that already use them keep them.', 'ffcertificate' ); ?></p>
				<?php if ( array() === $signers ) : ?>
					<p><?php esc_html_e( 'No signer yet.', 'ffcertificate' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Person', 'ffcertificate' ); ?></th>
								<th scope="col"><?php esc_html_e( 'RF', 'ffcertificate' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Role or sector', 'ffcertificate' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Signature', 'ffcertificate' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Forms', 'ffcertificate' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $signers as $signer ) : ?>
								<?php $this->render_row( $signer, $counts[ $signer['id'] ] ?? 0, $nonce ); ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<?php
			if ( self::can_manage() ) {
				$this->render_form( $editing );
			}
			?>
		</div>
		<?php
	}

	/**
	 * The notice the save redirect asked for.
	 */
	private function render_notice(): void {
		$code     = RequestInput::get_get_key( 'ffc_signer_notice' );
		$messages = array(
			'added'   => array( 'success', __( 'Signer added.', 'ffcertificate' ) ),
			'updated' => array( 'success', __( 'Signer updated.', 'ffcertificate' ) ),
			'no_rf'   => array( 'error', __( 'This user has no RF on their profile, so they cannot sign certificates.', 'ffcertificate' ) ),
			'exists'  => array( 'error', __( 'This user is already a signer.', 'ffcertificate' ) ),
			'user'    => array( 'error', __( 'Choose a WordPress user.', 'ffcertificate' ) ),
		);
		if ( isset( $messages[ $code ] ) ) {
			\FreeFormCertificate\Core\AdminNotice::callout( $messages[ $code ][0], esc_html( $messages[ $code ][1] ), array( 'classes' => array( 'inline' ) ) );
		}
	}

	/**
	 * One signer row.
	 *
	 * @param array{id: int, user_id: int, role: string, signature_id: int, active: bool, kept_name: string, kept_rf: string, kept_email_hash: string, added_at: int, added_by: int} $signer Signer.
	 * @param int                                                                                                                                                                    $forms  Forms that name it.
	 * @param string                                                                                                                                                                 $nonce  Reveal nonce, or '' when the user may not reveal.
	 */
	private function render_row( array $signer, int $forms, string $nonce ): void {
		$user = $signer['user_id'] > 0 ? get_userdata( $signer['user_id'] ) : false;
		$rf   = CertificateSigners::rf( $signer );
		?>
		<tr>
			<td>
				<strong><?php echo esc_html( CertificateSigners::name( $signer ) ); ?></strong><br>
				<span class="description">
					<?php echo $user ? esc_html( (string) $user->user_login ) : esc_html__( 'Account deleted; name and RF were kept', 'ffcertificate' ); ?>
				</span>
			</td>
			<td>
				<?php if ( '' === $rf ) : ?>
					<span class="description"><?php esc_html_e( 'No RF', 'ffcertificate' ); ?></span>
				<?php else : ?>
					<code class="ffc-pii-value" data-field="rf"><?php echo esc_html( \FreeFormCertificate\Core\DocumentFormatter::mask_rf( $rf ) ); ?></code>
					<?php
					if ( '' !== $nonce ) {
						$reveal_button = \FreeFormCertificate\Core\PiiReveal::field_button(
							array(
								'field'         => 'rf',
								'type'          => self::REVEAL_TYPE,
								'submission-id' => $signer['id'],
								'nonce'         => $nonce,
							),
							true
						);
						echo ' ' . $reveal_button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_button() escapes every attribute.
					}
					?>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $signer['role'] ); ?></td>
			<td>
				<?php
				$image = $signer['signature_id'] > 0 ? wp_get_attachment_image( $signer['signature_id'], array( 120, 40 ), false, array( 'alt' => __( 'Signature', 'ffcertificate' ) ) ) : '';
				echo '' !== $image ? wp_kses_post( $image ) : '—';
				?>
			</td>
			<td><?php echo $signer['active'] ? esc_html__( 'Active', 'ffcertificate' ) : esc_html__( 'Inactive', 'ffcertificate' ); ?></td>
			<td><?php echo esc_html( number_format_i18n( $forms ) ); ?></td>
			<td>
				<?php
				if ( self::can_manage() ) {
					AdminUI::render_button(
						array(
							'label' => __( 'Edit', 'ffcertificate' ),
							'icon'  => 'edit',
							'href'  => add_query_arg( array( 'edit' => $signer['id'] ), self::page_url() ),
						)
					);
				}
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * The Add or Edit form.
	 *
	 * @param array{id: int, user_id: int, role: string, signature_id: int, active: bool, kept_name: string, kept_rf: string, kept_email_hash: string, added_at: int, added_by: int}|null $editing The signer being edited, or null to add one.
	 */
	private function render_form( ?array $editing ): void {
		$sig_id = null !== $editing ? $editing['signature_id'] : 0;
		?>
		<div class="card">
			<h2 class="<?php echo null !== $editing ? 'ffc-icon-edit' : 'ffc-icon-plus'; ?>">
				<?php echo null !== $editing ? esc_html__( 'Edit signer', 'ffcertificate' ) : esc_html__( 'Add signer', 'ffcertificate' ); ?>
			</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
				<?php wp_nonce_field( self::SAVE_ACTION, 'ffc_signer_nonce' ); ?>
				<table class="form-table" role="presentation">
					<?php if ( null === $editing ) : ?>
						<tr>
							<th scope="row"><label for="ffc_signer_user"><?php esc_html_e( 'WordPress user', 'ffcertificate' ); ?></label></th>
							<td>
								<div class="ffc-signer-user-search" data-ffc-signer-user-search>
									<input type="hidden" name="ffc_signer_user" value="">
									<input type="text" class="regular-text" id="ffc_signer_user" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="ffc_signer_user_results" aria-describedby="ffc_signer_user_help ffc_signer_user_status" placeholder="<?php esc_attr_e( 'Name, login, e-mail or RF', 'ffcertificate' ); ?>">
									<ul class="ffc-signer-user-results" id="ffc_signer_user_results" role="listbox" aria-label="<?php esc_attr_e( 'Matching accounts', 'ffcertificate' ); ?>" hidden></ul>
									<p class="ffc-signer-user-status" id="ffc_signer_user_status" role="status" aria-live="polite"></p>
								</div>
								<p class="description" id="ffc_signer_user_help"><?php esc_html_e( 'Type at least two letters of the name, login or e-mail, or the full RF. Only users with an RF on their profile who are not signers yet are found. Their RF prints in full on the certificates they sign.', 'ffcertificate' ); ?></p>
							</td>
						</tr>
					<?php else : ?>
						<input type="hidden" name="ffc_signer_id" value="<?php echo esc_attr( (string) $editing['id'] ); ?>">
						<tr>
							<th scope="row"><?php esc_html_e( 'Person', 'ffcertificate' ); ?></th>
							<td><?php echo esc_html( CertificateSigners::name( $editing ) ); ?></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><label for="ffc_signer_role"><?php esc_html_e( 'Role or sector', 'ffcertificate' ); ?></label></th>
						<td><input type="text" class="regular-text" name="ffc_signer_role" id="ffc_signer_role" value="<?php echo esc_attr( null !== $editing ? $editing['role'] : '' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ffc_signer_signature"><?php esc_html_e( 'Signature image', 'ffcertificate' ); ?></label></th>
						<td>
							<input type="hidden" name="ffc_signer_signature" id="ffc_signer_signature" value="<?php echo esc_attr( (string) $sig_id ); ?>">
							<?php
							AdminUI::render_button(
								array(
									'label' => __( 'Choose from the media library', 'ffcertificate' ),
									'icon'  => 'image',
									'class' => 'ffc-media-select',
									'attrs' => array(
										'data-ffc-media-target' => '#ffc_signer_signature',
										'data-ffc-media-value'  => 'id',
									),
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'Optional. Print it in the layout as <img src="{{signer:signature}}">.', 'ffcertificate' ); ?></p>
						</td>
					</tr>
					<?php if ( null !== $editing ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th>
							<td>
								<?php
								AdminUI::render_toggle(
									array(
										'name'     => 'ffc_signer_active',
										'id'       => 'ffc_signer_active',
										'checked'  => $editing['active'],
										'disabled' => $editing['user_id'] <= 0,
										'label'    => __( 'Active: new forms may choose this signer', 'ffcertificate' ),
									)
								);
								?>
							</td>
						</tr>
					<?php endif; ?>
				</table>
				<?php
				AdminUI::render_save_button(
					null !== $editing ? __( 'Save signer', 'ffcertificate' ) : __( 'Add signer', 'ffcertificate' )
				);
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * AJAX: the accounts Add signer can choose (#2622). Searches name, login
	 * and e-mail, or matches a full RF through the profile hash, and returns
	 * only accounts with an RF on the profile that are not signers yet. The
	 * answer names each account by display name and login: no e-mail, no RF.
	 */
	public function ajax_search_candidates(): void {
		check_ajax_referer( self::SEARCH_ACTION, 'nonce' );
		if ( ! self::can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage signers.', 'ffcertificate' ) ), 403 );
		}
		$term = trim( RequestInput::get_post_string( 'term' ) );
		if ( mb_strlen( $term ) < self::SEARCH_MIN_LENGTH ) {
			wp_send_json_error( array( 'message' => __( 'Type at least two characters.', 'ffcertificate' ) ), 400 );
		}
		wp_send_json_success( array( 'users' => self::search_candidates( $term ) ) );
	}

	/**
	 * The accounts a term finds, signers excluded.
	 *
	 * @param string $term Search term, at least {@see self::SEARCH_MIN_LENGTH} characters.
	 * @return array<int, array{id: int, label: string}>
	 */
	private static function search_candidates( string $term ): array {
		$listed = array_map( static fn( array $signer ): int => $signer['user_id'], CertificateSigners::all() );
		$listed = array_values( array_filter( $listed ) );

		$args   = array(
			'meta_key'     => CertificateSigners::RF_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the signer screen only, bounded by SEARCH_LIMIT.
			'meta_compare' => 'EXISTS',
			'number'       => self::SEARCH_LIMIT,
			'orderby'      => 'display_name',
			'exclude'      => $listed,
		);
		$rf_ids = self::accounts_with_rf( $term );
		if ( null !== $rf_ids ) {
			if ( array() === $rf_ids ) {
				return array();
			}
			$args['include'] = $rf_ids;
		} else {
			$args['search']         = '*' . $term . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'user_nicename' );
		}

		$found = array();
		foreach ( get_users( $args ) as $user ) {
			if ( $user instanceof \WP_User ) {
				$found[] = array(
					'id'    => (int) $user->ID,
					'label' => $user->display_name . ' (' . $user->user_login . ')',
				);
			}
		}
		return $found;
	}

	/**
	 * When the term is a full RF, the accounts whose profile carries it;
	 * null when it is not an RF, so the text search runs instead.
	 *
	 * @param string $term Search term.
	 * @return array<int, int>|null
	 */
	private static function accounts_with_rf( string $term ): ?array {
		$digits = (string) preg_replace( '/\D/', '', $term );
		if ( 1 !== preg_match( '/^[\d.\-\s]+$/', $term ) || 'rf' !== \FreeFormCertificate\Core\DataSanitizer::classify_cpf_rf( $digits ) ) {
			return null;
		}
		$hash = \FreeFormCertificate\Core\SensitiveFieldRegistry::hash_identifier( 'rf', $digits );
		if ( null === $hash ) {
			return array();
		}
		return ( new \FreeFormCertificate\Repositories\UserProfileRepository() )->findUserIdsByHash( 'rf_hash', $hash );
	}

	/**
	 * Add or update a signer, then go back to the list.
	 */
	public function handle_save(): void {
		check_admin_referer( self::SAVE_ACTION, 'ffc_signer_nonce' );
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage signers.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}
		$role      = RequestInput::get_post_string( 'ffc_signer_role' );
		$signature = RequestInput::get_post_int( 'ffc_signer_signature', 0 );
		$id        = RequestInput::get_post_int( 'ffc_signer_id', 0 );

		if ( $id > 0 ) {
			CertificateSigners::update( $id, $role, $signature, RequestInput::get_post_bool( 'ffc_signer_active' ) );
			$code = 'updated';
		} else {
			$result = CertificateSigners::add( RequestInput::get_post_int( 'ffc_signer_user', 0 ), $role, $signature );
			$code   = is_wp_error( $result ) ? str_replace( 'ffc_signer_', '', (string) $result->get_error_code() ) : 'added';
		}
		wp_safe_redirect( add_query_arg( array( 'ffc_signer_notice' => $code ), self::page_url() ) );
		exit;
	}

	/**
	 * The screen's URL.
	 */
	public static function page_url(): string {
		return add_query_arg( array( 'page' => self::MENU_SLUG ), admin_url( CertificatesDashboard::PARENT ) );
	}
}
