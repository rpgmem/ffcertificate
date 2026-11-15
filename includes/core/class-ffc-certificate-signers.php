<?php
/**
 * CertificateSigners
 *
 * The people who sign certificates, chosen from WordPress users who have an
 * RF on their profile (#2582).
 *
 * @package FreeFormCertificate\Core
 * @since   6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The signer list, a form's signer and the `{{signer:…}}` tokens.
 *
 * Data model: the list lives in the `ffc_certificate_signers` option, an id
 * → entry map; a form names its signer in the `_ffc_signer_id` meta. An entry
 * stores the user, the role or sector, the signature image and an active
 * flag, and no copy of the RF: the name and the RF are read from the user's
 * profile when a certificate is generated, so a profile correction reaches
 * every certificate.
 *
 * The RF prints in full on the certificate, a documented exception in
 * CLAUDE.md "Security & PII conventions". It is read here, not through
 * `UserProfileService::read( …, FULL )`, because that read writes an
 * activity-log entry and a form issues thousands of certificates: what is
 * audited is adding the signer, which is what authorises their RF to print
 * on other people's certificates.
 *
 * When the user is deleted the entry keeps a copy of the name and the RF,
 * encrypted, and becomes inactive, so issued certificates do not change
 * ("retain records, drop relationships"). The privacy eraser removes that
 * copy, and the certificate then prints the RF masked.
 *
 * @phpstan-type Signer array{id: int, user_id: int, role: string, signature_id: int, active: bool, kept_name: string, kept_rf: string, kept_email_hash: string, added_at: int, added_by: int}
 */
final class CertificateSigners {

	/** The signer list. Listed in `uninstall.php`. */
	public const OPTION = 'ffc_certificate_signers';

	/** Form meta: the form's signer id. */
	public const FORM_META = '_ffc_signer_id';

	/**
	 * The user meta that holds the profile RF, encrypted
	 * (`UserProfileFieldMap::get( 'rf' )['meta_key']`). Repeated here because
	 * Core does not depend on UserDashboard; `CertificateSignersTest` holds
	 * the two spellings together.
	 */
	public const RF_META_KEY = 'ffc_user_rf';

	/** Keyed-hash context of the e-mail kept after the account is deleted. */
	private const EMAIL_HASH_CONTEXT = 'certificate_signer_email';

	/** The tokens a signer resolves. */
	public const TOKENS = array( 'name', 'rf', 'role', 'signature' );

	/**
	 * Every signer, by id.
	 *
	 * @return array<int, array{id: int, user_id: int, role: string, signature_id: int, active: bool, kept_name: string, kept_rf: string, kept_email_hash: string, added_at: int, added_by: int}>
	 */
	public static function all(): array {
		$raw     = get_option( self::OPTION, array() );
		$signers = array();
		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			$signer = self::normalize( $row );
			if ( null !== $signer ) {
				$signers[ $signer['id'] ] = $signer;
			}
		}
		ksort( $signers );
		return $signers;
	}

	/**
	 * One signer, or null.
	 *
	 * @param int $id Signer id.
	 * @return array{id: int, user_id: int, role: string, signature_id: int, active: bool, kept_name: string, kept_rf: string, kept_email_hash: string, added_at: int, added_by: int}|null
	 */
	public static function get( int $id ): ?array {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * The signer a form names, active or not: a form keeps a signer that was
	 * deactivated after it was chosen.
	 *
	 * @param int $form_id Form post id.
	 * @return array{id: int, user_id: int, role: string, signature_id: int, active: bool, kept_name: string, kept_rf: string, kept_email_hash: string, added_at: int, added_by: int}|null
	 */
	public static function for_form( int $form_id ): ?array {
		$raw = $form_id > 0 ? get_post_meta( $form_id, self::FORM_META, true ) : '';
		return is_numeric( $raw ) && (int) $raw > 0 ? self::get( (int) $raw ) : null;
	}

	/**
	 * Add a signer. Refused for a user without an RF or one already listed.
	 *
	 * @param int    $user_id      WordPress user id.
	 * @param string $role         Role or sector, as printed.
	 * @param int    $signature_id Signature image attachment id, or 0.
	 * @return int|\WP_Error The new signer id.
	 */
	public static function add( int $user_id, string $role, int $signature_id ) {
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'ffc_signer_user', __( 'Choose a WordPress user.', 'ffcertificate' ) );
		}
		if ( '' === self::user_rf( $user_id ) ) {
			return new \WP_Error( 'ffc_signer_no_rf', __( 'This user has no RF on their profile, so they cannot sign certificates.', 'ffcertificate' ) );
		}
		$signers = self::all();
		foreach ( $signers as $signer ) {
			if ( $signer['user_id'] === $user_id ) {
				return new \WP_Error( 'ffc_signer_exists', __( 'This user is already a signer.', 'ffcertificate' ) );
			}
		}
		$id             = array() === $signers ? 1 : max( array_keys( $signers ) ) + 1;
		$signers[ $id ] = array(
			'id'              => $id,
			'user_id'         => $user_id,
			'role'            => sanitize_text_field( $role ),
			'signature_id'    => max( 0, $signature_id ),
			'active'          => true,
			'kept_name'       => '',
			'kept_rf'         => '',
			'kept_email_hash' => '',
			'added_at'        => time(),
			'added_by'        => get_current_user_id(),
		);
		self::store( $signers );

		// Adding a signer is what lets their RF print on other people's
		// certificates, so it is recorded; the RF itself is not.
		ActivityLog::log(
			'certificate_signer_added',
			ActivityLog::LEVEL_INFO,
			array(
				'signer_id'      => $id,
				'signer_user_id' => $user_id,
			),
			get_current_user_id()
		);
		return $id;
	}

	/**
	 * Change a signer's role, signature or active flag. A signer whose user
	 * was deleted cannot be reactivated: nothing links it to a person any more.
	 *
	 * @param int    $id           Signer id.
	 * @param string $role         Role or sector.
	 * @param int    $signature_id Signature attachment id, or 0.
	 * @param bool   $active       Whether new forms may choose it.
	 */
	public static function update( int $id, string $role, int $signature_id, bool $active ): bool {
		$signers = self::all();
		if ( ! isset( $signers[ $id ] ) ) {
			return false;
		}
		$signers[ $id ]['role']         = sanitize_text_field( $role );
		$signers[ $id ]['signature_id'] = max( 0, $signature_id );
		$signers[ $id ]['active']       = $active && $signers[ $id ]['user_id'] > 0;
		self::store( $signers );
		return true;
	}

	/**
	 * The decrypted RF on a user's profile, or ''.
	 *
	 * @param int $user_id WordPress user id.
	 */
	public static function user_rf( int $user_id ): string {
		$stored = $user_id > 0 ? get_user_meta( $user_id, self::RF_META_KEY, true ) : '';
		if ( ! is_string( $stored ) || '' === $stored ) {
			return '';
		}
		return (string) Encryption::decrypt( $stored );
	}

	/**
	 * A signer's name: the user's display name, or the copy kept when the
	 * account was deleted.
	 *
	 * @param array{id: int, user_id: int, role: string, signature_id: int, active: bool, kept_name: string, kept_rf: string, kept_email_hash: string, added_at: int, added_by: int} $signer Signer.
	 */
	public static function name( array $signer ): string {
		if ( $signer['user_id'] > 0 ) {
			$user = get_userdata( $signer['user_id'] );
			return $user ? (string) $user->display_name : '';
		}
		return $signer['kept_name'];
	}

	/**
	 * A signer's RF in clear, or '' once the kept copy was erased.
	 *
	 * @param array{id: int, user_id: int, role: string, signature_id: int, active: bool, kept_name: string, kept_rf: string, kept_email_hash: string, added_at: int, added_by: int} $signer Signer.
	 */
	public static function rf( array $signer ): string {
		if ( $signer['user_id'] > 0 ) {
			return self::user_rf( $signer['user_id'] );
		}
		return '' === $signer['kept_rf'] ? '' : (string) Encryption::decrypt( $signer['kept_rf'] );
	}

	/**
	 * The values the `{{signer:…}}` tokens print for a signer, keyed by token.
	 * The RF prints in full and formatted on the certificate; a preview, which
	 * staff and, before a form opens, the public see, prints it masked. Once
	 * the kept copy was erased it prints masked everywhere. No signer prints
	 * every token empty.
	 *
	 * @param array{id: int, user_id: int, role: string, signature_id: int, active: bool, kept_name: string, kept_rf: string, kept_email_hash: string, added_at: int, added_by: int}|null $signer Signer.
	 * @param bool                                                                                                                                                                        $masked Mask the RF, for a preview.
	 * @return array<string, string>
	 */
	public static function token_values( ?array $signer, bool $masked = false ): array {
		$values = array_fill_keys( self::token_keys(), '' );
		if ( null === $signer ) {
			return $values;
		}
		$rf = self::rf( $signer );
		if ( '' === $rf && $signer['user_id'] <= 0 && '' !== $signer['kept_email_hash'] ) {
			// The copy kept on deletion was erased on request.
			$rf_text = DocumentFormatter::mask_placeholder( DocumentFormatter::MASK_RF );
		} else {
			$rf_text = '' === $rf ? '' : ( $masked ? DocumentFormatter::mask_rf( $rf ) : DocumentFormatter::format_rf( $rf ) );
		}
		$signature = $signer['signature_id'] > 0 ? wp_get_attachment_url( $signer['signature_id'] ) : '';

		$values['signer:name']      = self::name( $signer );
		$values['signer:rf']        = $rf_text;
		$values['signer:role']      = $signer['role'];
		$values['signer:signature'] = is_string( $signature ) ? $signature : '';
		return $values;
	}

	/**
	 * The data keys the renderer replaces as `{{signer:…}}`.
	 *
	 * @return array<int, string>
	 */
	public static function token_keys(): array {
		return array_map( static fn( string $token ): string => 'signer:' . $token, self::TOKENS );
	}

	/**
	 * Sample values for a preview with no signer chosen.
	 *
	 * @return array<string, string>
	 */
	public static function sample_values(): array {
		return array(
			'signer:name'      => 'Maria Souza',
			'signer:rf'        => DocumentFormatter::mask_placeholder( DocumentFormatter::MASK_RF ),
			'signer:role'      => 'Coordenação Pedagógica',
			'signer:signature' => '',
		);
	}

	/**
	 * How many forms name each signer.
	 *
	 * @return array<int, int> Signer id → forms.
	 */
	public static function form_counts(): array {
		$counts = array();
		$ids    = get_posts(
			array(
				'post_type'        => 'ffc_form',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts'      => -1,
				'fields'           => 'ids',
				'meta_key'         => self::FORM_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the signer screen only, over the plugin's forms.
				'suppress_filters' => false,
			)
		);
		foreach ( array_filter( $ids, 'is_numeric' ) as $form_id ) {
			$signer_id = get_post_meta( (int) $form_id, self::FORM_META, true );
			if ( is_numeric( $signer_id ) && (int) $signer_id > 0 ) {
				$counts[ (int) $signer_id ] = ( $counts[ (int) $signer_id ] ?? 0 ) + 1;
			}
		}
		return $counts;
	}

	/**
	 * Hook the account deletion. `delete_user` fires before WordPress removes
	 * the account's meta, which is the last moment the RF can be copied;
	 * `deleted_user` would be too late.
	 */
	public static function register(): void {
		add_action( 'delete_user', array( self::class, 'keep_copy_of_deleted_user' ), 10, 1 );
	}

	/**
	 * Keep the name and the RF of a signer whose account is being deleted,
	 * deactivate the entry and drop the link to the account.
	 *
	 * @param int $user_id The account being deleted.
	 */
	public static function keep_copy_of_deleted_user( int $user_id ): void {
		$signers = self::all();
		$changed = false;
		foreach ( $signers as $id => $signer ) {
			if ( $signer['user_id'] !== $user_id ) {
				continue;
			}
			$user = get_userdata( $user_id );
			$rf   = self::user_rf( $user_id );

			$signers[ $id ]['kept_name']       = $user ? (string) $user->display_name : '';
			$signers[ $id ]['kept_rf']         = '' === $rf ? '' : (string) Encryption::encrypt( $rf );
			$signers[ $id ]['kept_email_hash'] = $user ? self::email_hash( (string) $user->user_email ) : '';
			$signers[ $id ]['user_id']         = 0;
			$signers[ $id ]['active']          = false;
			$changed                           = true;
		}
		if ( $changed ) {
			self::store( $signers );
		}
	}

	/**
	 * Privacy eraser (`wp_privacy_personal_data_erasers`): remove the copy of
	 * name and RF kept for a deleted account with this e-mail.
	 *
	 * @param string $email E-mail of the request.
	 * @param int    $page  Page, unused: the list is one option.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public static function erase( string $email, int $page = 1 ): array {
		unset( $page );
		$hash    = self::email_hash( $email );
		$signers = self::all();
		$removed = false;
		foreach ( $signers as $id => $signer ) {
			if ( '' === $hash || $signer['kept_email_hash'] !== $hash || ( '' === $signer['kept_name'] && '' === $signer['kept_rf'] ) ) {
				continue;
			}
			$signers[ $id ]['kept_name'] = '';
			$signers[ $id ]['kept_rf']   = '';
			$removed                     = true;
		}
		if ( $removed ) {
			self::store( $signers );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * The keyed hash an e-mail is matched by after its account is gone.
	 *
	 * @param string $email E-mail.
	 */
	private static function email_hash( string $email ): string {
		$email = DataSanitizer::normalize_email( $email );
		return '' === $email ? '' : Encryption::keyed_hash( $email, self::EMAIL_HASH_CONTEXT );
	}

	/**
	 * Write the list.
	 *
	 * @param array<int, array{id: int, user_id: int, role: string, signature_id: int, active: bool, kept_name: string, kept_rf: string, kept_email_hash: string, added_at: int, added_by: int}> $signers List.
	 */
	private static function store( array $signers ): void {
		update_option( self::OPTION, array_values( $signers ), false );
	}

	/**
	 * One stored entry, or null when it is not one.
	 *
	 * @param mixed $row Stored row.
	 * @return array{id: int, user_id: int, role: string, signature_id: int, active: bool, kept_name: string, kept_rf: string, kept_email_hash: string, added_at: int, added_by: int}|null
	 */
	private static function normalize( $row ): ?array {
		if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! is_numeric( $row['id'] ) || (int) $row['id'] <= 0 ) {
			return null;
		}
		return array(
			'id'              => (int) $row['id'],
			'user_id'         => max( 0, ArrayValue::int( $row, 'user_id' ) ),
			'role'            => ArrayValue::string( $row, 'role' ),
			'signature_id'    => max( 0, ArrayValue::int( $row, 'signature_id' ) ),
			'active'          => ! empty( $row['active'] ),
			'kept_name'       => ArrayValue::string( $row, 'kept_name' ),
			'kept_rf'         => ArrayValue::string( $row, 'kept_rf' ),
			'kept_email_hash' => ArrayValue::string( $row, 'kept_email_hash' ),
			'added_at'        => max( 0, ArrayValue::int( $row, 'added_at' ) ),
			'added_by'        => max( 0, ArrayValue::int( $row, 'added_by' ) ),
		);
	}
}
