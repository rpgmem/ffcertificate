<?php
/**
 * Reregistration Custom Fields Page
 *
 * Renders the global list of reregistration fields every audience inherits.
 *
 * @package FreeFormCertificate\Reregistration
 * @since 4.12.13  Extracted from ReregistrationAdmin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Audience\AudienceReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page for reregistration custom fields.
 *
 * Until #2502 it listed the audiences and sent the operator to each one's
 * fields. It now edits the global list -- the level above every audience --
 * with the editor the audience tab uses, and lists in the side column where
 * an audience changed, hid or added a field.
 *
 * @phpstan-import-type AudienceRow from AudienceReader
 */
class ReregistrationCustomFieldsPage {

	/**
	 * Render the Custom Fields page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_reregistration' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ffcertificate' ) );
		}

		$audiences = AudienceReader::get_hierarchical( 'active' );
		$counts    = CustomFieldReader::level_counts();
		$edit_base = admin_url( 'admin.php?page=ffc-scheduling-audiences&action=edit&tab=fields&id=' );

		?>
		<div class="wrap ffc-admin-page ffc-page-custom-fields ffc-settings-wrap">
			<h1><?php esc_html_e( 'Custom Fields', 'ffcertificate' ); ?></h1>

			<div class="ffc-editor">
				<div class="ffc-editor__main">
					<div class="card">
						<h2 class="ffc-icon-globe"><?php esc_html_e( 'Global fields', 'ffcertificate' ); ?></h2>
						<?php \FreeFormCertificate\Audience\AudienceAdminAudienceRenderer::render_custom_fields_section( 0 ); ?>
					</div>
				</div>
				<aside class="ffc-editor__side">
					<div class="card">
						<h2 class="ffc-icon-split"><?php esc_html_e( 'Changed in audiences', 'ffcertificate' ); ?></h2>
						<?php if ( empty( $audiences ) ) : ?>
							<p class="description"><?php esc_html_e( 'No audiences found.', 'ffcertificate' ); ?></p>
						<?php else : ?>
							<ul class="ffc-field-levels">
								<?php foreach ( $audiences as $parent ) : ?>
									<?php self::render_level( $parent, $counts, $edit_base ); ?>
									<?php
									/**
									 * Children list mirrors the parent shape.
									 *
									 * @var list<AudienceRow> $children
									 */
									$children = $parent->children ?? array();
									foreach ( $children as $child ) {
										self::render_level( $child, $counts, $edit_base, true );
									}
									?>
								<?php endforeach; ?>
							</ul>
							<p class="description"><?php esc_html_e( 'Each name opens that audience\'s fields tab.', 'ffcertificate' ); ?></p>
						<?php endif; ?>
					</div>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * One audience in the side card: its name, linked to its fields tab, and
	 * what it keeps of its own.
	 *
	 * @param object                                                 $audience  Audience row.
	 * @param array<int, array{changed: int, hidden: int, own: int}> $counts    {@see CustomFieldReader::level_counts()}.
	 * @param string                                                 $edit_base Base URL of the fields tab.
	 * @param bool                                                   $is_child  Whether this is a child audience.
	 * @phpstan-param AudienceRow $audience
	 * @return void
	 */
	private static function render_level( object $audience, array $counts, string $edit_base, bool $is_child = false ): void {
		$id    = (int) $audience->id;
		$level = $counts[ $id ] ?? array(
			'changed' => 0,
			'hidden'  => 0,
			'own'     => 0,
		);
		$parts = array();
		if ( $level['changed'] > 0 ) {
			/* translators: %d: number of inherited fields the audience changed */
			$parts[] = sprintf( _n( '%d changed', '%d changed', $level['changed'], 'ffcertificate' ), $level['changed'] );
		}
		if ( $level['hidden'] > 0 ) {
			/* translators: %d: number of inherited fields the audience hides */
			$parts[] = sprintf( _n( '%d hidden', '%d hidden', $level['hidden'], 'ffcertificate' ), $level['hidden'] );
		}
		if ( $level['own'] > 0 ) {
			/* translators: %d: number of fields only the audience asks */
			$parts[] = sprintf( _n( '%d own field', '%d own fields', $level['own'], 'ffcertificate' ), $level['own'] );
		}
		?>
		<li class="<?php echo $is_child ? 'ffc-field-level-child' : 'ffc-field-level'; ?>">
			<a href="<?php echo esc_url( $edit_base . $id ); ?>"><?php echo esc_html( (string) $audience->name ); ?></a>
			<span class="description"><?php echo esc_html( empty( $parts ) ? '—' : implode( ' · ', $parts ) ); ?></span>
		</li>
		<?php
	}
}
