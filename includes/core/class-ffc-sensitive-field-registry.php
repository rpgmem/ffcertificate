<?php
/**
 * SensitiveFieldRegistry
 *
 * Declarative registry of fields treated as sensitive per write context.
 * Consolidates policy that was previously duplicated across SubmissionHandler
 * and AppointmentRepository, where each site carried its own hard-coded list
 * of fields to encrypt and hash.
 *
 * Adding or removing a sensitive field is now a single edit to the FIELDS
 * map instead of a hunt across three files. The encrypted/hash column names
 * per field remain visible in one place, so storage schema and encryption
 * policy stay aligned.
 *
 * Activity log encryption is intentionally out of scope: it gates by action
 * name, not by field, and belongs to its own concern.
 *
 * @package FreeFormCertificate\Core
 * @since 5.4.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sensitive Field Registry.
 */
final class SensitiveFieldRegistry {

	/**
	 * Context key: wp_ffc_submissions write path.
	 */
	public const CONTEXT_SUBMISSION = 'submission';

	/**
	 * Context key: wp_ffc_self_scheduling_appointments write path.
	 */
	public const CONTEXT_APPOINTMENT = 'appointment';

	/**
	 * Context key: wp_ffc_recruitment_candidate write path.
	 *
	 * Used by the recruitment CSV importer (sprint 4) and manual candidate
	 * edits (sprint 9.1) to encrypt CPF / RF / email plaintexts into the
	 * matching `*_encrypted` + `*_hash` column pairs on the candidate row.
	 *
	 * @since 6.0.0
	 */
	public const CONTEXT_RECRUITMENT_CANDIDATE = 'recruitment_candidate';

	/**
	 * Field descriptors per context.
	 *
	 * Each entry maps a logical field key (e.g. "email") to the columns that
	 * must be populated when the plaintext is encrypted.
	 *
	 *   - encrypted_column: string|null  Column for ciphertext. Null = no
	 *                                    ciphertext stored (hash-only).
	 *   - hash_column:      string|null  Column for Encryption::hash lookup.
	 *                                    Null = no lookup hash stored.
	 *
	 * @var array<string, array<string, array{encrypted_column: ?string, hash_column: ?string}>>
	 */
	/**
	 * How each identifier is canonicalised before it is encrypted or hashed.
	 *
	 * **Keyed by field, not by context, and that is the point.** The same CPF
	 * must produce the same hash whether it arrives through a certificate, an
	 * appointment, a candidacy or a profile edit -- so the rule cannot live
	 * inside the per-context entries below, where it would be fifteen copies
	 * to keep in step. One map, consulted by every context.
	 *
	 * **Why this map exists at all** (#1313): the hash function was already
	 * uniform -- one salted SHA-256, called from everywhere. What was not
	 * uniform was the string fed into it. Five sites normalised CPF to digits
	 * and `UserProfileService` hashed the raw value; three sites lowercased an
	 * e-mail and two did not. A call site that has to remember is a call site
	 * that can forget, and both of those had already forgotten.
	 *
	 * **`email` was declared `null` until this rule got its migration**, and
	 * the pairing is the reason: lowercasing it changes every `email_hash`
	 * already stored, so a lookup would canonicalise while the rows did not and
	 * the appointment module would stop finding its own history for anyone with
	 * a capital in their address. The `identity_normalization` card (#1313 PR 3)
	 * rewrites those rows, and the two landed in the same commit.
	 *
	 * A field absent from this map is hashed as given, which is correct for a
	 * value that carries no canonical form.
	 *
	 * @var array<string, string|null>
	 */
	private const NORMALIZERS = array(
		'cpf'    => 'cpf_rf',
		'rf'     => 'cpf_rf',
		'ticket' => 'ticket',
		'email'  => 'lowercase_trim',
	);

	private const FIELDS = array(
		self::CONTEXT_SUBMISSION            => array(
			'email'   => array(
				'encrypted_column' => 'email_encrypted',
				'hash_column'      => 'email_hash',
			),
			'cpf'     => array(
				'encrypted_column' => 'cpf_encrypted',
				'hash_column'      => 'cpf_hash',
			),
			'rf'      => array(
				'encrypted_column' => 'rf_encrypted',
				'hash_column'      => 'rf_hash',
			),
			'user_ip' => array(
				'encrypted_column' => 'user_ip_encrypted',
				'hash_column'      => null,
			),
			'data'    => array(
				'encrypted_column' => 'data_encrypted',
				'hash_column'      => null,
			),
			'ticket'  => array(
				'encrypted_column' => null,
				'hash_column'      => 'ticket_hash',
			),
		),
		self::CONTEXT_APPOINTMENT           => array(
			'email'       => array(
				'encrypted_column' => 'email_encrypted',
				'hash_column'      => 'email_hash',
			),
			'cpf'         => array(
				'encrypted_column' => 'cpf_encrypted',
				'hash_column'      => 'cpf_hash',
			),
			'rf'          => array(
				'encrypted_column' => 'rf_encrypted',
				'hash_column'      => 'rf_hash',
			),
			'phone'       => array(
				'encrypted_column' => 'phone_encrypted',
				'hash_column'      => null,
			),
			'custom_data' => array(
				'encrypted_column' => 'custom_data_encrypted',
				'hash_column'      => null,
			),
			'user_ip'     => array(
				'encrypted_column' => 'user_ip_encrypted',
				'hash_column'      => null,
			),
		),
		self::CONTEXT_RECRUITMENT_CANDIDATE => array(
			'email' => array(
				'encrypted_column' => 'email_encrypted',
				'hash_column'      => 'email_hash',
			),
			'cpf'   => array(
				'encrypted_column' => 'cpf_encrypted',
				'hash_column'      => 'cpf_hash',
			),
			'rf'    => array(
				'encrypted_column' => 'rf_encrypted',
				'hash_column'      => 'rf_hash',
			),
		),
	);

	/**
	 * All field specs for a context.
	 *
	 * @param string $context One of the CONTEXT_* constants.
	 * @return array<string, array{encrypted_column: ?string, hash_column: ?string}>
	 */
	public static function fields_for( string $context ): array {
		return self::FIELDS[ $context ] ?? array();
	}

	/**
	 * Whether a logical field is declared sensitive in a given context.
	 *
	 * @param string $context Context key.
	 * @param string $field_key Logical field key.
	 * @return bool
	 */
	public static function has( string $context, string $field_key ): bool {
		return isset( self::FIELDS[ $context ][ $field_key ] );
	}

	/**
	 * Encrypt and hash a batch of plaintext values into their column map.
	 *
	 * Empty or null values are skipped silently. When encryption is not
	 * configured (no keys in wp-config), the returned array is empty and
	 * the caller is expected to fall back to plaintext storage.
	 *
	 * @param string               $context Context key.
	 * @param array<string, mixed> $values  Plaintext values keyed by logical field.
	 * @return array<string, string|null> Columns to merge into the insert row.
	 */
	public static function encrypt_fields( string $context, array $values ): array {
		if ( ! class_exists( Encryption::class ) || ! Encryption::is_configured() ) {
			return array();
		}

		$out = array();

		foreach ( self::fields_for( $context ) as $field_key => $spec ) {
			if ( ! array_key_exists( $field_key, $values ) ) {
				continue;
			}
			$value = $values[ $field_key ];
			if ( null === $value || '' === $value ) {
				continue;
			}

			$plain = self::normalize( $field_key, is_string( $value ) ? $value : (string) $value );

			// A value that normalises to nothing carried no identifier -- a
			// CPF field holding only punctuation, say. Writing a ciphertext
			// and a hash of the empty string would make it findable, so it is
			// skipped exactly like an empty input.
			if ( '' === $plain ) {
				continue;
			}

			if ( ! empty( $spec['encrypted_column'] ) ) {
				$out[ $spec['encrypted_column'] ] = Encryption::encrypt( $plain );
			}
			if ( ! empty( $spec['hash_column'] ) ) {
				$out[ $spec['hash_column'] ] = Encryption::hash( $plain );
			}
		}

		return $out;
	}

	/**
	 * Canonical form of one identifier, by field key.
	 *
	 * Public because READERS need it too: a search that normalises the typed
	 * value while the write did not -- or the reverse -- simply stops matching.
	 * One function serves both sides.
	 *
	 * @since 6.26.0
	 * @param string $field_key Logical field key (`cpf`, `rf`, `email`, …).
	 * @param string $value     Raw value.
	 * @return string Canonical value, or the input unchanged when the field
	 *                declares no canonical form.
	 */
	public static function normalize( string $field_key, string $value ): string {
		$kind = self::NORMALIZERS[ $field_key ] ?? null;

		if ( null === $kind || ! class_exists( DataSanitizer::class ) ) {
			return $value;
		}

		// No trailing pass-through on purpose: every kind the map declares has
		// an arm here, so PHPStan proves a fall-through unreachable and reports
		// it as dead code -- which is the good outcome, because it means the
		// switch is exhaustive over what is actually declared. A kind added to
		// NORMALIZERS without an arm is caught by
		// `IdentityHashBoundaryTest::test_every_declared_field_resolves_to_a_normalizer()`
		// before it can reach a request.
		switch ( $kind ) {
			case 'cpf_rf':
				return DataSanitizer::normalize_cpf_rf( $value );
			case 'lowercase_trim':
				return DataSanitizer::normalize_email( $value );
			case 'ticket':
				return DataSanitizer::normalize_ticket( $value );
		}
	}

	/**
	 * The lookup hash of one identifier -- normalise, then hash.
	 *
	 * **This is the boundary.** Every site that turns an identifier into a
	 * hash goes through here, on the read side as much as the write side, so
	 * that "what gets hashed" stops being a decision each caller makes for
	 * itself (#1313). Calling `Encryption::hash()` directly on a CPF, RF,
	 * e-mail or ticket is the defect this method exists to remove.
	 *
	 * Returns null for an empty value, mirroring `Encryption::hash()`, so a
	 * caller can keep using `null` to mean "no identifier supplied" rather
	 * than storing the hash of an empty string.
	 *
	 * @since 6.26.0
	 * @param string $field_key Logical field key.
	 * @param string $value     Raw value, masked or not.
	 * @return string|null Hash, or null when the value is empty.
	 */
	public static function hash_identifier( string $field_key, string $value ): ?string {
		$plain = self::normalize( $field_key, $value );

		if ( '' === $plain || ! class_exists( Encryption::class ) ) {
			return null;
		}

		return Encryption::hash( $plain );
	}

	/**
	 * Field keys that declare a canonical form.
	 *
	 * Exposed for the guard that charges every hash-bearing field against this
	 * map, so a new identifier cannot be added to `FIELDS` with a hash column
	 * and no decision about its canonical form.
	 *
	 * @since 6.26.0
	 * @return list<string>
	 */
	public static function normalized_field_keys(): array {
		return array_keys( self::NORMALIZERS );
	}

	/**
	 * A fingerprint of the canonical-form RULES, not of any value.
	 *
	 * The `identity_normalization` migration (#1313 PR 3) rewrites rows whose
	 * stored plaintext is not canonical, and "canonical" is defined by the map
	 * above. So the card's completion is only meaningful relative to the rules
	 * that were in force when it ran: change a rule and every row it approved
	 * has to be re-examined, exactly as the key-rotation card re-arms when the
	 * encryption key changes.
	 *
	 * This makes that automatic. The alternative -- remembering to reset the
	 * card by hand -- is the shape `CLAUDE.md` records as going stale in
	 * silence, and here the silence would mean rows nobody can find.
	 *
	 * It fingerprints the rules alone. A new CONTEXT or a new column changes
	 * which rows are walked, not what canonical means, and re-arming for that
	 * would rewrite a whole database for no correction.
	 *
	 * @since 6.26.0
	 * @return string
	 */
	public static function normalizer_fingerprint(): string {
		return hash( 'sha256', (string) wp_json_encode( self::NORMALIZERS ) );
	}

	/**
	 * Every field key that carries a lookup hash, across all contexts.
	 *
	 * The set the normalizer map must cover: a field whose value becomes a
	 * searchable hash has to have decided what form it is searched in, or the
	 * spelling that happened to arrive is the one stored (#1313).
	 *
	 * @since 6.26.0
	 * @return list<string>
	 */
	public static function hashed_field_keys(): array {
		$keys = array();

		foreach ( self::FIELDS as $fields ) {
			foreach ( $fields as $field_key => $spec ) {
				if ( ! empty( $spec['hash_column'] ) ) {
					$keys[ $field_key ] = true;
				}
			}
		}

		$out = array_keys( $keys );
		sort( $out );

		return $out;
	}

	/**
	 * Logical keys whose plaintext must be removed from a row before insert
	 * to avoid LGPD leaks.
	 *
	 * @param string $context Context key.
	 * @return list<string>
	 */
	public static function plaintext_keys( string $context ): array {
		return array_keys( self::fields_for( $context ) );
	}

	/**
	 * In-memory cache of the union of all static sensitive keys.
	 *
	 * @var array<string, bool>|null
	 */
	private static $universal_static_cache = null;

	/**
	 * Cache object key used for the dynamic (is_sensitive=1) set.
	 */
	private const DYNAMIC_CACHE_KEY = 'ffc_sensitive_field_keys_dynamic';

	/**
	 * Cache group for wp_cache_* of the dynamic set.
	 */
	private const DYNAMIC_CACHE_GROUP = 'ffc_sensitive_fields';

	/**
	 * Union of all static sensitive field keys across every context.
	 *
	 * Unlike fields_for(), this collapses per-context entries into a single
	 * flat set useful for payload inspection ("does this blob contain any
	 * field we consider sensitive?"). Cached in-memory per request.
	 *
	 * @return array<string, bool> Map of field_key => true for O(1) lookup.
	 */
	public static function universal_sensitive_keys(): array {
		if ( null !== self::$universal_static_cache ) {
			return self::$universal_static_cache;
		}

		$keys = array();
		foreach ( self::FIELDS as $fields ) {
			foreach ( array_keys( $fields ) as $key ) {
				$keys[ $key ] = true;
			}
		}
		self::$universal_static_cache = $keys;
		return $keys;
	}

	/**
	 * Dynamic sensitive field keys configured by admins via
	 * wp_ffc_custom_fields.is_sensitive = 1.
	 *
	 * Cached via the WP object cache. Invalidate with
	 * invalidate_dynamic_cache() when the custom fields table changes.
	 *
	 * @return array<string, bool> Map of field_key => true.
	 */
	public static function dynamic_sensitive_keys(): array {
		$cached = wp_cache_get( self::DYNAMIC_CACHE_KEY, self::DYNAMIC_CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$keys = array();

		if ( class_exists( '\\FreeFormCertificate\\Reregistration\\CustomFieldReader' ) ) {
			foreach ( \FreeFormCertificate\Reregistration\CustomFieldReader::list_sensitive_field_keys() as $field_key ) {
				$keys[ $field_key ] = true;
			}
		}

		wp_cache_set( self::DYNAMIC_CACHE_KEY, $keys, self::DYNAMIC_CACHE_GROUP );
		return $keys;
	}

	/**
	 * Drop the dynamic key cache. Call whenever wp_ffc_custom_fields is
	 * created, updated or deleted so the next read reflects the change.
	 *
	 * @return void
	 */
	public static function invalidate_dynamic_cache(): void {
		wp_cache_delete( self::DYNAMIC_CACHE_KEY, self::DYNAMIC_CACHE_GROUP );
	}

	/**
	 * Whether the payload contains any key classified as sensitive.
	 *
	 * Recursively descends into nested arrays so a wrapper like
	 * [ 'fields' => [ 'cpf' => '...' ] ] is correctly flagged. Matching is
	 * exact on the key name — callers are expected to log using canonical
	 * field keys ('cpf', not 'cpf_aluno') or risk a false negative.
	 *
	 * @param array<int|string, mixed> $payload Payload to inspect.
	 * @return bool
	 */
	public static function contains_sensitive( array $payload ): bool {
		if ( empty( $payload ) ) {
			return false;
		}

		$sensitive = self::universal_sensitive_keys() + self::dynamic_sensitive_keys();
		if ( empty( $sensitive ) ) {
			return false;
		}

		return self::walk_for_sensitive( $payload, $sensitive );
	}

	/**
	 * Depth-first scan for any array key that belongs to the sensitive set.
	 *
	 * @param array<int|string, mixed> $node Current node.
	 * @param array<string, bool>      $sensitive Lookup table.
	 * @return bool
	 */
	private static function walk_for_sensitive( array $node, array $sensitive ): bool {
		foreach ( $node as $key => $value ) {
			if ( is_string( $key ) && isset( $sensitive[ $key ] ) ) {
				return true;
			}
			if ( is_array( $value ) && self::walk_for_sensitive( $value, $sensitive ) ) {
				return true;
			}
		}
		return false;
	}
}
