<?php
/**
 * Shared template migration strategy.
 *
 * Moves forms that carry near-identical copies of one certificate layout onto
 * a shared template (#2583).
 *
 * @package FreeFormCertificate\Migrations\Strategies
 * @since 6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

use FreeFormCertificate\Core\CertificateSigners;
use FreeFormCertificate\Core\CertificateTemplateLink;
use FreeFormCertificate\Core\EventDate;
use FreeFormCertificate\Core\FormBackground;
use FreeFormCertificate\Core\TemplateFields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Own layout copy → a reference to a shared template, with its values filled.
 *
 * On production, 240 forms were one attendance declaration with the place, the
 * action and the signer typed into each copy. This card reads each form that
 * prints its own copy against every certificate template that uses
 * `{{model:…}}` or `{{signer:…}}` tokens:
 *
 * 1. the template's literal text must match the form's layout, with each token
 *    capturing what the form typed in its place (whitespace and character
 *    references compare as the browser renders them);
 * 2. the captured `{{signer:…}}` name and RF must name a signer on the list;
 * 3. the template with the captured values, the signer and the form's own event
 *    date must render exactly what the form prints today, background included.
 *
 * Only then does the form reference the template; its own copy stays, so
 * switching back prints what it printed. Every other form is left alone and
 * listed with the reason. No matching rule is written for one site's text: the
 * administrator writes the shared template, and the template is the rule.
 *
 * Examined forms are kept in a register with a fingerprint of the templates and
 * signers they were read against, so adding a signer or a second template
 * brings the forms it could change back to pending, and a batch counts the
 * forms it CHANGED (#1378): termination does not depend on the repair.
 *
 * @phpstan-type SharedTemplate array{id: int, title: string, html: string, fields: array<int, array{key: string, label: string, type: string, required: bool, default: string}>, bg_image: string, bg_color: string}
 */
class SharedTemplateMigrationStrategy implements MigrationStrategyInterface {

	/**
	 * The register of examined forms. Listed in `uninstall.php`.
	 */
	public const STATE_OPTION = 'ffc_shared_template_migration_state';

	/**
	 * How many forms one batch examines.
	 */
	private const BATCH_SIZE = 25;

	/**
	 * How many pending forms the card previews.
	 */
	private const PREVIEW_LIMIT = 20;

	/**
	 * A token that captures what the form typed in its place.
	 */
	private const SLOT_PATTERN = '/(\{\{(?:model|signer):[a-z0-9_]+(?::format=[^}]*)?\}\})/';

	/**
	 * How long an extracted value may be in a preview line.
	 */
	private const PREVIEW_VALUE_LENGTH = 60;

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return array<string, mixed>
	 */
	public function calculate_status( string $migration_key, array $migration_config ): array {
		$templates   = self::candidate_templates();
		$fingerprint = self::fingerprint( $templates );
		$state       = $this->state();
		// With no shared template there is nothing a form could move onto.
		$pending = array() === $templates ? array() : $this->pending_ids( $state, $fingerprint );
		$skipped = $this->current_skips( $state, $fingerprint );
		$done    = count( $state['done'] ) + count( $skipped );
		$total   = $done + count( $pending );

		$failures = array();
		foreach ( $skipped as $form_id => $skip ) {
			$failures[] = sprintf( '%s — %s', $this->form_label( $form_id ), $this->reason_text( $skip ) );
		}

		$preview = array();
		if ( array() === $templates ) {
			$preview[] = __( 'No certificate template uses {{model:…}} or {{signer:…}} tokens yet. Write the shared template first: copy one form\'s layout into a new template, put a token where the forms differ, declare the fields and add the signers; then this card lists what it would switch.', 'ffcertificate' );
		} else {
			foreach ( array_slice( $pending, 0, self::PREVIEW_LIMIT ) as $form_id ) {
				$preview[] = $this->preview_line( $form_id, $templates );
			}
		}

		return array(
			'total'       => $total,
			'migrated'    => $done,
			'pending'     => count( $pending ),
			'percent'     => $total > 0 ? round( ( $done / $total ) * 100, 2 ) : 100,
			'is_complete' => array() === $pending,
			'failures'    => array_slice( $failures, 0, 50 ),
			'failed'      => count( $failures ),
			'preview'     => $preview,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @param int                  $batch_number     Batch number.
	 * @return array<string, mixed>
	 */
	public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
		$templates   = self::candidate_templates();
		$fingerprint = self::fingerprint( $templates );
		$state       = $this->state();
		$pending     = array() === $templates ? array() : array_slice( $this->pending_ids( $state, $fingerprint ), 0, self::BATCH_SIZE );
		$changed     = 0;

		foreach ( $pending as $form_id ) {
			$plan = self::plan( $form_id, $templates );
			if ( 'match' === $plan['outcome'] ) {
				update_post_meta( $form_id, CertificateTemplateLink::META_VALUES, $plan['values'] );
				if ( $plan['signer_id'] > 0 ) {
					update_post_meta( $form_id, CertificateSigners::FORM_META, $plan['signer_id'] );
				}
				// Written last: the reference is what makes the form print the template.
				update_post_meta( $form_id, CertificateTemplateLink::META_TEMPLATE_ID, $plan['template_id'] );
				$state['done'][] = $form_id;
				unset( $state['skipped'][ $form_id ] );
				++$changed;
			} else {
				$state['skipped'][ $form_id ] = array(
					'code'        => $plan['outcome'],
					'detail'      => $plan['detail'],
					'template'    => $plan['template_id'],
					'fingerprint' => $fingerprint,
				);
			}
		}
		update_option( self::STATE_OPTION, $state, false );

		return array(
			'success'   => true,
			'processed' => $changed,
			'message'   => sprintf(
				/* translators: 1: forms examined, 2: forms switched to a shared template */
				__( 'Examined %1$d forms and switched %2$d to a shared template; the rest are listed with the reason.', 'ffcertificate' ),
				count( $pending ),
				$changed
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return bool|\WP_Error
	 */
	public function can_run( string $migration_key, array $migration_config ) {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'shared_template_from_layout';
	}

	/**
	 * Certificate templates a form can be moved onto: usable ones whose layout
	 * holds a `{{model:…}}` or `{{signer:…}}` token, by id.
	 *
	 * @return array<int, SharedTemplate>
	 */
	public static function candidate_templates(): array {
		$ids       = get_posts(
			array(
				'post_type'        => CertificateTemplateLink::TEMPLATE_POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
		$templates = array();
		foreach ( array_map( 'intval', array_filter( $ids, 'is_numeric' ) ) as $id ) {
			if ( ! CertificateTemplateLink::is_usable_template( $id ) ) {
				continue;
			}
			$html = (string) get_post_meta( $id, CertificateTemplateLink::TEMPLATE_META_HTML, true );
			if ( 1 !== preg_match( self::SLOT_PATTERN, $html ) ) {
				continue;
			}
			$templates[ $id ] = array(
				'id'       => $id,
				'title'    => (string) get_the_title( $id ),
				'html'     => $html,
				'fields'   => CertificateTemplateLink::fields( $id ),
				'bg_image' => CertificateTemplateLink::bg_image( $id ),
				'bg_color' => CertificateTemplateLink::bg_color( $id ),
			);
		}
		return $templates;
	}

	/**
	 * What one form would become, without writing it: the first template that
	 * reproduces its certificate, or the reason none does (read against the
	 * template whose text it follows furthest).
	 *
	 * @param int                        $form_id   Form post id.
	 * @param array<int, SharedTemplate> $templates Candidate templates.
	 * @return array{outcome: string, template_id: int, values: array<string, string>, signer_id: int, detail: string}
	 */
	public static function plan( int $form_id, array $templates ): array {
		$none = array(
			'outcome'     => 'no_template',
			'template_id' => 0,
			'values'      => array(),
			'signer_id'   => 0,
			'detail'      => '',
		);
		if ( array() === $templates ) {
			return $none;
		}
		$config = get_post_meta( $form_id, '_ffc_form_config', true );
		$layout = is_array( $config ) && is_string( $config['pdf_layout'] ?? null ) ? $config['pdf_layout'] : '';
		if ( '' === trim( $layout ) ) {
			return array_merge( $none, array( 'outcome' => 'no_layout' ) );
		}

		$best       = null;
		$best_depth = -1;
		foreach ( $templates as $template ) {
			$plan = self::plan_against( $form_id, $layout, $template );
			if ( 'match' === $plan['outcome'] ) {
				return $plan;
			}
			if ( $plan['depth'] > $best_depth ) {
				$best       = $plan;
				$best_depth = $plan['depth'];
			}
		}
		if ( null === $best ) {
			return $none;
		}
		unset( $best['depth'] );
		return $best;
	}

	/**
	 * One form against one template.
	 *
	 * @param int    $form_id  Form post id.
	 * @param string $layout   The form's own layout.
	 * @param array  $template Template.
	 * @phpstan-param SharedTemplate $template
	 * @return array{outcome: string, template_id: int, values: array<string, string>, signer_id: int, detail: string, depth: int}
	 */
	private static function plan_against( int $form_id, string $layout, array $template ): array {
		$result              = array(
			'outcome'     => 'match',
			'template_id' => $template['id'],
			'values'      => array(),
			'signer_id'   => 0,
			'detail'      => '',
			'depth'       => 0,
		);
		list( $start, $end ) = EventDate::range( $form_id );
		if ( '' === $start && EventDate::layout_uses_date( $template['html'] ) ) {
			// The template prints the event date the form has not been given.
			return array_merge( $result, array( 'outcome' => 'no_event_date' ) );
		}

		$own_signer = CertificateSigners::for_form( $form_id );
		$before     = self::apply_signer( EventDate::replace_tokens( $layout, $start, $end ), CertificateSigners::token_values( $own_signer ) );
		$subject    = self::normalize( $before );
		$declared   = array_column( $template['fields'], 'key' );

		$pieces = self::pieces( self::normalize( EventDate::replace_tokens( $template['html'], $start, $end ) ), $declared );
		$match  = self::match_pieces( $pieces, $subject );
		if ( null === $match ) {
			$depth = self::matched_depth( $pieces, $subject );
			return array_merge(
				$result,
				array(
					'outcome' => 'no_match',
					'detail'  => self::differs_at( $pieces, $depth, $subject ),
					'depth'   => $depth,
				)
			);
		}
		$result['depth'] = count( $pieces );

		$captured = array();
		foreach ( $match as $slot => $raw ) {
			// A tag inside a value is markup the field would print as text.
			if ( false !== strpos( $raw, '<' ) || false !== strpos( $raw, '>' ) ) {
				return array_merge(
					$result,
					array(
						'outcome' => 'markup_in_value',
						'detail'  => $slot,
					)
				);
			}
			$captured[ $slot ] = self::restore( $raw );
		}

		$model = array();
		foreach ( $captured as $slot => $value ) {
			if ( 0 === strpos( $slot, 'model:' ) ) {
				$model[ substr( $slot, 6 ) ] = $value;
			}
		}
		$values = TemplateFields::sanitize_values( $template['fields'], $model );

		$signer_values = array_fill_keys( CertificateSigners::token_keys(), '' );
		if ( false !== strpos( $template['html'], '{{signer:' ) ) {
			$signer = self::find_signer( $captured, $own_signer );
			if ( null === $signer ) {
				return array_merge(
					$result,
					array(
						'outcome' => 'no_signer',
						'detail'  => $captured['signer:name'] ?? '',
					)
				);
			}
			if ( null !== $own_signer && $own_signer['id'] !== $signer['id'] ) {
				return array_merge( $result, array( 'outcome' => 'other_signer' ) );
			}
			$result['signer_id'] = $signer['id'];
			$signer_values       = CertificateSigners::token_values( $signer );
		}

		$rendered = EventDate::replace_tokens( TemplateFields::replace_tokens( $template['html'], $template['fields'], $values ), $start, $end );
		if ( self::normalize( self::apply_signer( $rendered, $signer_values ) ) !== $subject ) {
			// Name the signer's part when it alone differs, so the fix is on the list.
			foreach ( array( 'signature', 'role' ) as $token ) {
				$key = 'signer:' . $token;
				if ( isset( $captured[ $key ] ) && self::normalize( self::apply_signer( $rendered, array_merge( $signer_values, array( $key => $captured[ $key ] ) ) ) ) === $subject ) {
					return array_merge(
						$result,
						array(
							'outcome' => $token . '_differs',
							'detail'  => $captured[ $key ],
						)
					);
				}
			}
			return array_merge( $result, array( 'outcome' => 'output_differs' ) );
		}
		if ( FormBackground::url( $form_id ) !== $template['bg_image'] || FormBackground::color( $form_id ) !== $template['bg_color'] ) {
			return array_merge( $result, array( 'outcome' => 'background_differs' ) );
		}

		$result['values'] = $values;
		return $result;
	}

	/**
	 * A layout split into literal text and the tokens that capture: a
	 * `{{model:…}}` the template declares, or a `{{signer:…}}`. Any other token
	 * stays literal, as the renderer leaves it.
	 *
	 * @param string             $normalized Normalised template layout.
	 * @param array<int, string> $declared   Keys of the declared fields.
	 * @return array<int, array{slot: string, text: string}>
	 */
	private static function pieces( string $normalized, array $declared ): array {
		$pieces = array();
		$parts  = preg_split( self::SLOT_PATTERN, $normalized, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( is_array( $parts ) ? $parts : array() as $part ) {
			if ( '' === $part ) {
				continue;
			}
			$slot = '';
			if ( 1 === preg_match( '/^\{\{(model|signer):([a-z0-9_]+)/', $part, $m ) ) {
				if ( 'signer' === $m[1] ? in_array( $m[2], CertificateSigners::TOKENS, true ) : in_array( $m[2], $declared, true ) ) {
					$slot = $m[1] . ':' . $m[2];
				}
			}
			if ( '' === $slot && array() !== $pieces && '' === $pieces[ count( $pieces ) - 1 ]['slot'] ) {
				$pieces[ count( $pieces ) - 1 ]['text'] .= $part;
				continue;
			}
			$pieces[] = array(
				'slot' => $slot,
				'text' => $part,
			);
		}
		return $pieces;
	}

	/**
	 * The regex for the first `$count` pieces; a token repeated later must
	 * capture the same text again.
	 *
	 * @param array<int, array{slot: string, text: string}> $pieces Pieces.
	 * @param int                                           $count  How many to use.
	 * @param bool                                          $whole  Anchor at the end too.
	 * @return array{0: string, 1: array<string, string>} Pattern, and group name per slot.
	 */
	private static function regex( array $pieces, int $count, bool $whole ): array {
		$pattern = '';
		$groups  = array();
		foreach ( array_slice( $pieces, 0, $count ) as $piece ) {
			if ( '' === $piece['slot'] ) {
				$pattern .= preg_quote( $piece['text'], '/' );
				continue;
			}
			if ( isset( $groups[ $piece['slot'] ] ) ) {
				$pattern .= '(?P=' . $groups[ $piece['slot'] ] . ')';
				continue;
			}
			$name                     = 'g' . count( $groups );
			$groups[ $piece['slot'] ] = $name;
			$pattern                 .= '(?P<' . $name . '>.+?)';
		}
		return array( '/^' . $pattern . ( $whole ? '$' : '' ) . '/su', $groups );
	}

	/**
	 * The text each token captured, or null when the layout does not follow
	 * the template.
	 *
	 * @param array<int, array{slot: string, text: string}> $pieces  Pieces.
	 * @param string                                        $subject Normalised form layout.
	 * @return array<string, string>|null
	 */
	private static function match_pieces( array $pieces, string $subject ): ?array {
		list( $pattern, $groups ) = self::regex( $pieces, count( $pieces ), true );
		if ( 1 !== preg_match( $pattern, $subject, $m ) ) {
			return null;
		}
		$captured = array();
		foreach ( $groups as $slot => $name ) {
			$captured[ $slot ] = (string) $m[ $name ];
		}
		return $captured;
	}

	/**
	 * How many leading pieces the layout follows.
	 *
	 * @param array<int, array{slot: string, text: string}> $pieces  Pieces.
	 * @param string                                        $subject Normalised form layout.
	 */
	private static function matched_depth( array $pieces, string $subject ): int {
		$depth = 0;
		$total = count( $pieces );
		for ( $count = 1; $count <= $total; $count++ ) {
			list( $pattern ) = self::regex( $pieces, $count, false );
			if ( 1 !== preg_match( $pattern, $subject ) ) {
				break;
			}
			$depth = $count;
		}
		return $depth;
	}

	/**
	 * The template text where the layout stops following it, for the card: from
	 * a little before the first character that differs.
	 *
	 * @param array<int, array{slot: string, text: string}> $pieces  Pieces.
	 * @param int                                           $depth   Pieces followed.
	 * @param string                                        $subject Normalised form layout.
	 */
	private static function differs_at( array $pieces, int $depth, string $subject ): string {
		$piece = $pieces[ $depth ] ?? null;
		if ( null === $piece ) {
			return '';
		}
		if ( '' !== $piece['slot'] ) {
			return '{{' . $piece['slot'] . '}}';
		}
		$offset = 0;
		if ( $depth > 0 ) {
			list( $pattern ) = self::regex( $pieces, $depth, false );
			$offset          = 1 === preg_match( $pattern, $subject, $m ) ? strlen( $m[0] ) : 0;
		}
		$text = $piece['text'];
		$same = 0;
		if ( $depth > 0 && '' !== $pieces[ $depth - 1 ]['slot'] ) {
			// After a token the text may start anywhere: the longest start found.
			$length = strlen( $text );
			while ( $same < $length && false !== strpos( $subject, substr( $text, 0, $same + 1 ), $offset ) ) {
				++$same;
			}
		} else {
			$tail   = substr( $subject, $offset );
			$length = min( strlen( $tail ), strlen( $text ) );
			while ( $same < $length && $tail[ $same ] === $text[ $same ] ) {
				++$same;
			}
		}
		$from     = max( 0, $same - 24 );
		$fragment = mb_strcut( $text, $from, 120, 'UTF-8' );
		$fragment = (string) preg_replace( array( '/^[^<]*>/', '/<[^>]*$/' ), '', $fragment );
		$fragment = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( self::restore( $fragment ) ) ) );
		return self::shorten( '' !== $fragment ? $fragment : self::restore( mb_strcut( $text, $from, 60, 'UTF-8' ) ) );
	}

	/**
	 * Whitespace and character references as the browser renders them. `&lt;`,
	 * `&gt;` and `&amp;` are kept apart from real markup, so a typed `<b>` never
	 * compares equal to a tag.
	 *
	 * @param string $html HTML.
	 */
	private static function normalize( string $html ): string {
		$html = strtr(
			$html,
			array(
				'&lt;'  => "\x01lt;",
				'&gt;'  => "\x01gt;",
				'&amp;' => "\x01amp;",
				'&#60;' => "\x01lt;",
				'&#62;' => "\x01gt;",
				'&#38;' => "\x01amp;",
			)
		);
		$html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$html = (string) preg_replace( '/\s+/u', ' ', $html );
		// A root-relative image is the same file as its absolute address here.
		$origin = self::origin();
		if ( '' !== $origin ) {
			$html = (string) preg_replace( '#(\ssrc=")/(?!/)#', '$1' . $origin . '/', $html );
		}
		return trim( $html );
	}

	/**
	 * Captured text back to the value a field stores.
	 *
	 * @param string $text Normalised text.
	 */
	private static function restore( string $text ): string {
		return strtr(
			$text,
			array(
				"\x01lt;"  => '<',
				"\x01gt;"  => '>',
				"\x01amp;" => '&',
			)
		);
	}

	/**
	 * The site's scheme and host.
	 */
	private static function origin(): string {
		$home   = (string) home_url( '/' );
		$scheme = (string) wp_parse_url( $home, PHP_URL_SCHEME );
		$host   = (string) wp_parse_url( $home, PHP_URL_HOST );
		$port   = wp_parse_url( $home, PHP_URL_PORT );
		if ( '' === $scheme || '' === $host ) {
			return '';
		}
		return $scheme . '://' . $host . ( is_int( $port ) ? ':' . $port : '' );
	}

	/**
	 * Replace the `{{signer:…}}` tokens as the certificate renderer does.
	 *
	 * @param string                $html   Layout.
	 * @param array<string, string> $values Signer token values.
	 */
	private static function apply_signer( string $html, array $values ): string {
		foreach ( $values as $key => $value ) {
			$html = str_replace( '{{' . $key . '}}', nl2br( esc_html( $value ), false ), $html );
		}
		return $html;
	}

	/**
	 * The signer whose name and RF the layout typed. The form's own signer is
	 * tried first.
	 *
	 * @param array<string, string>     $captured   Captured values by slot.
	 * @param array<string, mixed>|null $own_signer The form's signer, if any.
	 * @return array{id: int, user_id: int, role: string, signature_id: int, active: bool, kept_name: string, kept_rf: string, kept_email_hash: string, added_at: int, added_by: int}|null
	 */
	private static function find_signer( array $captured, ?array $own_signer ): ?array {
		$signers = CertificateSigners::all();
		if ( null !== $own_signer && isset( $signers[ $own_signer['id'] ] ) ) {
			$signers = array( $own_signer['id'] => $signers[ $own_signer['id'] ] ) + $signers;
		}
		foreach ( $signers as $signer ) {
			$values = CertificateSigners::token_values( $signer );
			foreach ( array( 'name', 'rf' ) as $token ) {
				if ( isset( $captured[ 'signer:' . $token ] ) && self::normalize( $values[ 'signer:' . $token ] ) !== self::normalize( $captured[ 'signer:' . $token ] ) ) {
					continue 2;
				}
			}
			return $signer;
		}
		return null;
	}

	/**
	 * What the forms were read against: when it changes, the forms left alone
	 * are read again.
	 *
	 * @param array<int, SharedTemplate> $templates Candidate templates.
	 */
	private static function fingerprint( array $templates ): string {
		return md5( (string) wp_json_encode( array( $templates, CertificateSigners::all() ) ) );
	}

	/**
	 * Forms that print their own copy and were not examined against the
	 * current templates and signers.
	 *
	 * @param array{done: array<int, int>, skipped: array<int, array{code: string, detail: string, template: int, fingerprint: string}>} $state       Register.
	 * @param string                                                                                                                     $fingerprint Current fingerprint.
	 * @return array<int, int>
	 */
	private function pending_ids( array $state, string $fingerprint ): array {
		$done    = array_flip( $state['done'] );
		$skipped = $this->current_skips( $state, $fingerprint );
		$pending = array();
		foreach ( $this->form_ids() as $form_id ) {
			if ( isset( $done[ $form_id ] ) || isset( $skipped[ $form_id ] ) || CertificateTemplateLink::stored_template_id( $form_id ) > 0 ) {
				continue;
			}
			$config = get_post_meta( $form_id, '_ffc_form_config', true );
			$layout = is_array( $config ) && is_string( $config['pdf_layout'] ?? null ) ? $config['pdf_layout'] : '';
			if ( '' !== trim( $layout ) ) {
				$pending[] = $form_id;
			}
		}
		return $pending;
	}

	/**
	 * Forms left alone under the current templates and signers, still printing
	 * their own copy.
	 *
	 * @param array{done: array<int, int>, skipped: array<int, array{code: string, detail: string, template: int, fingerprint: string}>} $state       Register.
	 * @param string                                                                                                                     $fingerprint Current fingerprint.
	 * @return array<int, array{code: string, detail: string, template: int, fingerprint: string}>
	 */
	private function current_skips( array $state, string $fingerprint ): array {
		return array_filter(
			$state['skipped'],
			static fn( array $skip, int $form_id ): bool => $fingerprint === $skip['fingerprint'] && 0 === CertificateTemplateLink::stored_template_id( $form_id ),
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Every form that is not in the bin.
	 *
	 * @return array<int, int>
	 */
	private function form_ids(): array {
		$ids = get_posts(
			array(
				'post_type'        => 'ffc_form',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
		return array_map( 'intval', array_filter( $ids, 'is_numeric' ) );
	}

	/**
	 * The register, normalised.
	 *
	 * @return array{done: array<int, int>, skipped: array<int, array{code: string, detail: string, template: int, fingerprint: string}>}
	 */
	private function state(): array {
		$raw     = get_option( self::STATE_OPTION, array() );
		$raw     = is_array( $raw ) ? $raw : array();
		$done    = is_array( $raw['done'] ?? null ) ? array_map( 'intval', $raw['done'] ) : array();
		$skipped = array();
		foreach ( is_array( $raw['skipped'] ?? null ) ? $raw['skipped'] : array() as $id => $skip ) {
			if ( ! is_array( $skip ) ) {
				continue;
			}
			$skipped[ (int) $id ] = array(
				'code'        => (string) ( $skip['code'] ?? '' ),
				'detail'      => (string) ( $skip['detail'] ?? '' ),
				'template'    => (int) ( $skip['template'] ?? 0 ),
				'fingerprint' => (string) ( $skip['fingerprint'] ?? '' ),
			);
		}
		return array(
			'done'    => array_values( $done ),
			'skipped' => $skipped,
		);
	}

	/**
	 * One preview line: the template, the extracted values, the signer and the
	 * outcome.
	 *
	 * @param int                        $form_id   Form post id.
	 * @param array<int, SharedTemplate> $templates Candidate templates.
	 */
	private function preview_line( int $form_id, array $templates ): string {
		$plan = self::plan( $form_id, $templates );
		if ( 'match' !== $plan['outcome'] ) {
			return sprintf(
				'%s — %s',
				$this->form_label( $form_id ),
				$this->reason_text(
					array(
						'code'     => $plan['outcome'],
						'detail'   => $plan['detail'],
						'template' => $plan['template_id'],
					)
				)
			);
		}

		$parts = array();
		foreach ( $templates[ $plan['template_id'] ]['fields'] as $field ) {
			$parts[] = sprintf( '%s: “%s”', $field['label'], self::shorten( $plan['values'][ $field['key'] ] ?? '' ) );
		}
		if ( $plan['signer_id'] > 0 ) {
			$signer  = CertificateSigners::get( $plan['signer_id'] );
			$parts[] = sprintf(
				/* translators: %s: signer name */
				__( 'signer %s', 'ffcertificate' ),
				null !== $signer ? CertificateSigners::name( $signer ) : ''
			);
		}
		return sprintf(
			/* translators: 1: form title and id, 2: template title, 3: extracted values and signer */
			__( '%1$s — switches to “%2$s”: %3$s', 'ffcertificate' ),
			$this->form_label( $form_id ),
			$templates[ $plan['template_id'] ]['title'],
			implode( '; ', $parts )
		);
	}

	/**
	 * A form's title with its id.
	 *
	 * @param int $form_id Form post id.
	 */
	private function form_label( int $form_id ): string {
		return sprintf( '%s (#%d)', get_the_title( $form_id ), $form_id );
	}

	/**
	 * A value cut for a preview line.
	 *
	 * @param string $text Text.
	 */
	private static function shorten( string $text ): string {
		return mb_strlen( $text ) > self::PREVIEW_VALUE_LENGTH ? rtrim( mb_substr( $text, 0, self::PREVIEW_VALUE_LENGTH ) ) . '…' : $text;
	}

	/**
	 * Why a form was left alone, for the card.
	 *
	 * @param array{code: string, detail: string, template: int} $skip The register entry.
	 */
	private function reason_text( array $skip ): string {
		$template = $skip['template'] > 0 ? (string) get_the_title( $skip['template'] ) : '';
		switch ( $skip['code'] ) {
			case 'no_match':
				return sprintf(
					/* translators: 1: template title, 2: the template text where the layout differs */
					__( 'the layout differs from “%1$s” at “%2$s”', 'ffcertificate' ),
					$template,
					$skip['detail']
				);
			case 'no_signer':
				return sprintf(
					/* translators: %s: the signer name typed in the layout */
					__( 'no signer on the list matches “%s”; add them under Certificates → Signers', 'ffcertificate' ),
					$skip['detail']
				);
			case 'signature_differs':
				return sprintf(
					/* translators: %s: the image address the layout shows */
					__( 'the signer\'s signature image is not the one the layout shows (%s); choose that image for the signer', 'ffcertificate' ),
					$skip['detail']
				);
			case 'role_differs':
				return sprintf(
					/* translators: %s: the role typed in the layout */
					__( 'the signer\'s role is not the one the layout types (“%s”)', 'ffcertificate' ),
					$skip['detail']
				);
			case 'other_signer':
				return __( 'the form already names a different signer', 'ffcertificate' );
			case 'no_event_date':
				return __( 'the form has no event date; run the event date card first', 'ffcertificate' );
			case 'markup_in_value':
				return __( 'the text in place of a token holds markup a field would print as text', 'ffcertificate' );
			case 'output_differs':
				return sprintf(
					/* translators: %s: template title */
					__( 'the certificate would print differently with “%s”; left as it is', 'ffcertificate' ),
					$template
				);
			case 'background_differs':
				return sprintf(
					/* translators: %s: template title */
					__( 'the background differs from “%s”', 'ffcertificate' ),
					$template
				);
			case 'no_layout':
				return __( 'the form has no layout of its own', 'ffcertificate' );
			default:
				return __( 'no certificate template uses {{model:…}} or {{signer:…}} tokens', 'ffcertificate' );
		}
	}
}
