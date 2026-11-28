<?php
/**
 * Profile Completion
 *
 * The details a person completes before using the Site PWA app (#2962).
 *
 * @package FreeFormCertificate\Reregistration
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Core\Button;
use FreeFormCertificate\Core\DataSanitizer;
use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\UserDashboard\UserCreator;
use FreeFormCertificate\UserDashboard\UserManager;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asks a person, inside the app, for the reregistration details that
 * identify them: the full name, a CPF or an RF, and the unit of assignment.
 * The e-mail is shown, read-only, because it is the WordPress account's.
 *
 * Nothing is stored twice. The values are the ones the reregistration form
 * reads and writes (the profile for a profile-mapped field, the custom-field
 * answers otherwise), so a reregistration, an import or this screen
 * completes them, and no campaign has to be open. The field definitions are
 * the person's own when they belong to an audience, and the global list
 * otherwise; a field the install does not define is not asked for.
 *
 * The screen is registered with Site PWA as `required` (site-pwa#80): while
 * something is missing every other app screen leads here. A CPF or RF is
 * written only when the account has neither, and refused when it belongs
 * to another account. FFC Administrators are never asked.
 *
 * @phpstan-import-type CustomFieldRow from CustomFieldReader
 */
final class ProfileCompletion {

	/**
	 * The app screen's id.
	 */
	public const SCREEN = 'complete-profile';

	/**
	 * The admin-post action and nonce action of the form.
	 */
	public const ACTION = 'ffc_complete_profile';

	/**
	 * The role that is never asked.
	 */
	public const EXEMPT_ROLE = 'ffc_administrator';

	/**
	 * The `field_key` values this screen reads: stored values, so they keep
	 * their Portuguese names (CLAUDE.md, the language criterion).
	 */
	public const NAME_KEY = 'display_name';

	public const CPF_KEY = 'cpf';

	public const RF_KEY = 'rf';

	public const UNIT_KEY = 'unidade_lotacao';

	/**
	 * What can be missing.
	 */
	public const MISSING_NAME = 'name';

	public const MISSING_IDENTITY = 'identity';

	public const MISSING_UNIT = 'unit';

	/**
	 * The contract version of Site PWA's screens this class speaks.
	 */
	public const API_VERSION = 1;

	/**
	 * Transient prefix of a refused save's messages, by user id.
	 */
	private const ERRORS_TRANSIENT = 'ffc_profile_completion_errors_';

	/**
	 * Hook the screen and the form.
	 */
	public static function init(): void {
		add_action( 'site_pwa_register_screens', array( self::class, 'register' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Register the screen with Site PWA, for a signed-in person.
	 *
	 * @param mixed $screens The app's screen registry.
	 * @param mixed $api     The contract version the app speaks.
	 */
	public static function register( $screens, $api = 0 ): void {
		if ( self::API_VERSION !== (int) $api || ! is_object( $screens ) || ! method_exists( $screens, 'add' ) || get_current_user_id() <= 0 ) {
			return;
		}
		$screens->add(
			self::SCREEN,
			array(
				'label'    => __( 'Complete your details', 'ffcertificate' ),
				'icon'     => 'user',
				'place'    => 'more',
				'render'   => array( self::class, 'render' ),
				'required' => array( self::class, 'required' ),
			)
		);
	}

	/**
	 * Whether a person still has to complete their details.
	 *
	 * @param WP_User $user The person.
	 */
	public static function required( WP_User $user ): bool {
		if ( $user->ID <= 0 || in_array( self::EXEMPT_ROLE, (array) $user->roles, true ) ) {
			return false;
		}
		return array() !== self::missing( $user );
	}

	/**
	 * The field definitions this screen reads, by field key: the person's
	 * own resolution when they belong to an audience, the global list
	 * otherwise. A key the install does not define is absent.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, object>
	 * @phpstan-return array<string, CustomFieldRow>
	 */
	public static function fields( int $user_id ): array {
		$rows = ProfileCustomFields::fields( $user_id );
		if ( array() === $rows ) {
			$rows = CustomFieldReader::get_by_audience_with_parents( 0, true );
		}
		$fields = array();
		foreach ( $rows as $row ) {
			$key = (string) $row->field_key;
			if ( in_array( $key, array( self::NAME_KEY, self::CPF_KEY, self::RF_KEY, self::UNIT_KEY ), true ) && ! isset( $fields[ $key ] ) ) {
				$fields[ $key ] = $row;
			}
		}
		return $fields;
	}

	/**
	 * What the person still has to complete, in the screen's order.
	 *
	 * @param WP_User $user The person.
	 * @return list<string> MISSING_* values.
	 */
	public static function missing( WP_User $user ): array {
		$fields = self::fields( $user->ID );
		if ( array() === $fields ) {
			return array();
		}
		return self::evaluate( $fields, ProfileCustomFields::stored_values( $user->ID, array_values( $fields ) ), (string) $user->user_login );
	}

	/**
	 * What is missing, from the definitions and the stored values.
	 *
	 * @param array<string, object> $fields Field key => definition.
	 * @phpstan-param array<string, CustomFieldRow> $fields
	 * @param array<int, mixed>     $values Field id => stored value.
	 * @param string                $login  The account's login.
	 * @return list<string> MISSING_* values.
	 */
	public static function evaluate( array $fields, array $values, string $login ): array {
		$value   = static function ( string $key ) use ( $fields, $values ): string {
			if ( ! isset( $fields[ $key ] ) ) {
				return '';
			}
			$stored = $values[ (int) $fields[ $key ]->id ] ?? '';
			return is_scalar( $stored ) ? trim( (string) $stored ) : '';
		};
		$missing = array();

		if ( isset( $fields[ self::NAME_KEY ] ) ) {
			$name = $value( self::NAME_KEY );
			// An account created by an import carries its login as its name.
			if ( '' === $name || 0 === strcasecmp( $name, $login ) ) {
				$missing[] = self::MISSING_NAME;
			}
		}
		if ( ( isset( $fields[ self::CPF_KEY ] ) || isset( $fields[ self::RF_KEY ] ) ) && '' === $value( self::CPF_KEY ) && '' === $value( self::RF_KEY ) ) {
			$missing[] = self::MISSING_IDENTITY;
		}
		if ( isset( $fields[ self::UNIT_KEY ] ) && '' === $value( self::UNIT_KEY ) ) {
			$missing[] = self::MISSING_UNIT;
		}
		return $missing;
	}

	/**
	 * The screen.
	 */
	public static function render(): string {
		$user = wp_get_current_user();
		if ( $user->ID <= 0 ) {
			return '';
		}
		\FreeFormCertificate\Core\AssetHelper::enqueue_common_style();
		\FreeFormCertificate\Core\AssetHelper::enqueue_dark_mode();

		$missing = self::required( $user ) ? self::missing( $user ) : array();
		$fields  = self::fields( $user->ID );
		$errors  = get_transient( self::ERRORS_TRANSIENT . $user->ID );
		if ( false !== $errors ) {
			delete_transient( self::ERRORS_TRANSIENT . $user->ID );
		}

		$shown = array();
		foreach ( array(
			self::MISSING_NAME     => array( self::NAME_KEY ),
			self::MISSING_IDENTITY => array( self::CPF_KEY, self::RF_KEY ),
			self::MISSING_UNIT     => array( self::UNIT_KEY ),
		) as $part => $keys ) {
			if ( ! in_array( $part, $missing, true ) ) {
				continue;
			}
			foreach ( $keys as $key ) {
				if ( isset( $fields[ $key ] ) ) {
					$field = clone $fields[ $key ];
					// A CPF or an RF is enough; the rest is asked for.
					$field->is_required = self::MISSING_IDENTITY === $part ? 0 : 1;
					$shown[]            = ProfileCustomFields::field_html( $field, '' );
				}
			}
		}

		ob_start();
		include FFC_PLUGIN_DIR . 'templates/user-dashboard/profile-completion.php';
		$html = ob_get_clean();
		return false === $html ? '' : $html;
	}

	/**
	 * The form's handler: validate what is missing, write it where the
	 * reregistration keeps it, and return to the screen, which Site PWA
	 * then leaves for where the person was going.
	 */
	public static function handle(): void {
		$user = wp_get_current_user();
		if ( $user->ID <= 0 ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}
		check_admin_referer( self::ACTION );

		$back = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_POST['redirect_to'] ) ) : '';
		$back = wp_validate_redirect( $back, home_url( '/' ) );

		$posted = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized by save() for its own field.
		$errors = self::save( $user, $posted );
		if ( array() !== $errors ) {
			set_transient( self::ERRORS_TRANSIENT . $user->ID, $errors, 5 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Validate and write what is missing.
	 *
	 * @param WP_User              $user   The person.
	 * @param array<string, mixed> $posted Field key => raw (unslashed) value.
	 * @return list<string> Messages; empty when everything was written.
	 */
	public static function save( WP_User $user, array $posted ): array {
		if ( ! self::required( $user ) ) {
			return array();
		}
		$plan = self::plan( $user->ID, self::missing( $user ), self::fields( $user->ID ), $posted );
		if ( array() !== $plan['errors'] ) {
			return $plan['errors'];
		}
		if ( array() !== $plan['profile'] ) {
			UserManager::update_extended_profile( $user->ID, $plan['profile'], array_values( array_intersect( array( self::CPF_KEY, self::RF_KEY ), array_keys( $plan['profile'] ) ) ) );
		}
		if ( array() !== $plan['answers'] ) {
			CustomFieldWriter::save_user_data( $user->ID, $plan['answers'] );
		}
		return array();
	}

	/**
	 * What a save would write, or why it is refused: only what is missing,
	 * each value checked as the reregistration form checks it.
	 *
	 * @param int                   $user_id The person.
	 * @param array<int, string>    $missing What is missing.
	 * @param array<string, object> $fields  Field key => definition.
	 * @phpstan-param array<string, CustomFieldRow> $fields
	 * @param array<string, mixed>  $posted  Field key => raw (unslashed) value.
	 * @return array{errors: list<string>, profile: array<string, string>, answers: array<string, string>}
	 */
	public static function plan( int $user_id, array $missing, array $fields, array $posted ): array {
		$text    = static fn( string $key ): string => isset( $posted[ $key ] ) && is_scalar( $posted[ $key ] ) ? trim( sanitize_text_field( (string) $posted[ $key ] ) ) : '';
		$errors  = array();
		$profile = array();
		$answers = array();

		if ( in_array( self::MISSING_NAME, $missing, true ) ) {
			$name = $text( self::NAME_KEY );
			if ( '' === $name || false === strpos( $name, ' ' ) ) {
				$errors[] = __( 'Type your full name, first and last.', 'ffcertificate' );
			} else {
				$profile[ self::NAME_KEY ] = $name;
			}
		}

		if ( in_array( self::MISSING_IDENTITY, $missing, true ) ) {
			$identity = array(
				self::CPF_KEY => isset( $fields[ self::CPF_KEY ] ) ? DataSanitizer::normalize_cpf_rf( $text( self::CPF_KEY ) ) : '',
				self::RF_KEY  => isset( $fields[ self::RF_KEY ] ) ? DataSanitizer::normalize_cpf_rf( $text( self::RF_KEY ) ) : '',
			);
			if ( '' === $identity[ self::CPF_KEY ] && '' === $identity[ self::RF_KEY ] ) {
				$errors[] = __( 'Type your CPF or your RF.', 'ffcertificate' );
			}
			foreach ( $identity as $key => $digits ) {
				if ( '' === $digits ) {
					continue;
				}
				$error = self::identity_error( $key, $digits, $user_id );
				if ( '' !== $error ) {
					$errors[] = $error;
					continue;
				}
				$profile[ $key ] = $digits;
			}
		}

		if ( in_array( self::MISSING_UNIT, $missing, true ) && isset( $fields[ self::UNIT_KEY ] ) ) {
			$field = $fields[ self::UNIT_KEY ];
			$raw   = isset( $posted[ self::UNIT_KEY ] ) && is_scalar( $posted[ self::UNIT_KEY ] ) ? (string) $posted[ self::UNIT_KEY ] : '';
			$unit  = 'dependent_select' === (string) $field->field_type ? (string) ProfileCustomFields::clean_dependent_select( $field, $raw ) : trim( sanitize_text_field( $raw ) );
			$check = '' === $unit ? null : CustomFieldValidator::validate( $field, $unit );
			if ( '' === $unit || is_wp_error( $check ) ) {
				/* translators: %s: the field's label, such as "Unit of assignment" */
				$errors[] = sprintf( __( 'Fill in %s.', 'ffcertificate' ), (string) $field->field_label );
			} elseif ( ! empty( $field->field_profile_key ) ) {
				$profile[ (string) $field->field_profile_key ] = $unit;
			} else {
				$answers[ 'field_' . (int) $field->id ] = $unit;
			}
		}

		return array(
			'errors'  => $errors,
			'profile' => $profile,
			'answers' => $answers,
		);
	}

	/**
	 * Why a typed CPF or RF cannot be written, or ''.
	 *
	 * @param string $key     `cpf` or `rf`.
	 * @param string $digits  The identifier's digits.
	 * @param int    $user_id The person writing it.
	 */
	private static function identity_error( string $key, string $digits, int $user_id ): string {
		$valid = self::CPF_KEY === $key ? DocumentFormatter::validate_cpf( $digits ) : DocumentFormatter::validate_rf( $digits );
		if ( ! $valid ) {
			return self::CPF_KEY === $key ? __( 'This CPF is not valid.', 'ffcertificate' ) : __( 'This RF is not valid.', 'ffcertificate' );
		}
		$hash   = SensitiveFieldRegistry::hash_identifier( $key, $digits );
		$others = null === $hash ? array() : array_diff( UserCreator::user_ids_by_hash( $key . '_hash', $hash ), array( $user_id ) );
		if ( array() !== $others ) {
			return self::CPF_KEY === $key
				? __( 'This CPF is already on another account. Ask an administrator.', 'ffcertificate' )
				: __( 'This RF is already on another account. Ask an administrator.', 'ffcertificate' );
		}
		return '';
	}

	/**
	 * The account's e-mail, masked: it is shown for reference, never edited
	 * here.
	 *
	 * @param WP_User $user The person.
	 */
	public static function masked_email( WP_User $user ): string {
		$email = (string) $user->user_email;
		return '' === $email ? '' : DocumentFormatter::mask( DocumentFormatter::MASK_EMAIL, $email );
	}

	/**
	 * The form's submit button.
	 */
	public static function submit_button(): string {
		return Button::html(
			array(
				'label'    => __( 'Save and continue', 'ffcertificate' ),
				'category' => 'primary',
				'icon'     => 'checkmark',
				'type'     => 'submit',
			)
		);
	}
}
