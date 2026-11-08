<?php
/**
 * Activity log items, grouped as the settings screen lists them (#2340).
 *
 * Included by the Advanced tab inside its Activity Log card. Expects
 * `$ffcertificate_log_on` and `$ffcertificate_dangerzone` from the including
 * view. Every item greys out while the log is off, because it has no effect
 * then; an audit item stays greyed for an account without the Danger Zone
 * capability (`data-ffc-locked`), whatever the log's state.
 *
 * @package FreeFormCertificate\Settings\Views
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffcertificate_items_labels = \FreeFormCertificate\Core\ActivityLogItems::labels();
$ffcertificate_items_groups = array(
	\FreeFormCertificate\Core\ActivityLogItems::GROUP_AUDIT     => array(
		'title'       => __( 'Audit — on by default', 'ffcertificate' ),
		'description' => __( 'Who saw personal data, who deleted or suppressed data and who changed permissions. Recorded whatever the minimum level; switching an item off needs the Danger Zone capability.', 'ffcertificate' ),
		'icon'        => 'ffc-icon-shield',
	),
	\FreeFormCertificate\Core\ActivityLogItems::GROUP_SECONDARY => array(
		'title'       => __( 'Secondary items — off by default', 'ffcertificate' ),
		'description' => __( 'The day-to-day of each module. Switch on only what you will consult; each item adds to the log\'s volume.', 'ffcertificate' ),
		'icon'        => 'ffc-icon-list',
	),
);
?>
<div class="ffc-disabled-target ffc-activity-log-items" data-ffc-master="enable_activity_log">
	<?php if ( ! $ffcertificate_log_on ) : ?>
		<p class="description ffc-icon-info"><?php esc_html_e( 'The activity log is off, so these items have no effect. Switch it on to change them.', 'ffcertificate' ); ?></p>
	<?php endif; ?>
	<?php
	foreach ( $ffcertificate_items_groups as $ffcertificate_group => $ffcertificate_group_meta ) :
		$ffcertificate_is_audit = \FreeFormCertificate\Core\ActivityLogItems::GROUP_AUDIT === $ffcertificate_group;
		$ffcertificate_locked   = $ffcertificate_is_audit && ! $ffcertificate_dangerzone;
		// Section => item keys, in screen order.
		$ffcertificate_sections = array();
		foreach ( \FreeFormCertificate\Core\ActivityLogItems::keys_of( $ffcertificate_group ) as $ffcertificate_item ) {
			$ffcertificate_sections[ \FreeFormCertificate\Core\ActivityLogItems::ITEMS[ $ffcertificate_item ]['section'] ][] = $ffcertificate_item;
		}
		?>
		<h3 class="<?php echo esc_attr( $ffcertificate_group_meta['icon'] ); ?>"><?php echo esc_html( $ffcertificate_group_meta['title'] ); ?></h3>
		<p class="description"><?php echo esc_html( $ffcertificate_group_meta['description'] ); ?></p>
		<div class="ffc-sections">
			<?php
			foreach ( $ffcertificate_sections as $ffcertificate_section => $ffcertificate_section_items ) :
				$ffcertificate_on_count = 0;
				foreach ( $ffcertificate_section_items as $ffcertificate_item ) {
					if ( \FreeFormCertificate\Settings\SettingsReader::activity_log_item_enabled( $ffcertificate_item ) ) {
						++$ffcertificate_on_count;
					}
				}
				$ffcertificate_total = count( $ffcertificate_section_items );
				?>
				<details class="ffc-section" data-ffc-item-count<?php echo \FreeFormCertificate\Core\ActivityLogItems::section_starts_open( $ffcertificate_group, $ffcertificate_on_count, $ffcertificate_total ) ? ' open' : ''; ?>>
					<summary class="ffc-section__summary">
						<span class="ffc-section__text">
							<span class="ffc-section__title"><?php echo esc_html( $ffcertificate_items_labels['sections'][ $ffcertificate_section ] ?? $ffcertificate_section ); ?></span>
						</span>
						<span class="ffc-section__chip<?php echo $ffcertificate_on_count > 0 ? ' is-on' : ''; ?>" data-ffc-count-template="<?php /* translators: 1: items switched on, 2: items in the section */ echo esc_attr__( '%1$d of %2$d on', 'ffcertificate' ); ?>">
							<?php
							/* translators: 1: items switched on, 2: items in the section */
							echo esc_html( sprintf( __( '%1$d of %2$d on', 'ffcertificate' ), $ffcertificate_on_count, $ffcertificate_total ) );
							?>
						</span>
					</summary>
					<div class="ffc-section__body">
						<?php
						foreach ( $ffcertificate_section_items as $ffcertificate_item ) :
							$ffcertificate_key  = \FreeFormCertificate\Core\ActivityLogItems::setting_key( $ffcertificate_item );
							$ffcertificate_data = array( 'ffc-autosave-key' => $ffcertificate_key );
							if ( $ffcertificate_locked ) {
								$ffcertificate_data['ffc-locked'] = '1';
							}
							?>
							<div class="ffc-mb-2xs">
								<?php
								\FreeFormCertificate\Admin\AdminUI::render_toggle(
									array(
										'name'     => 'ffc_settings[' . $ffcertificate_key . ']',
										'id'       => $ffcertificate_key,
										'checked'  => \FreeFormCertificate\Settings\SettingsReader::activity_log_item_enabled( $ffcertificate_item ),
										'disabled' => $ffcertificate_locked || ! $ffcertificate_log_on,
										'label'    => $ffcertificate_items_labels['items'][ $ffcertificate_item ] ?? $ffcertificate_item,
										'data'     => $ffcertificate_data,
									)
								);
								?>
							</div>
						<?php endforeach; ?>
					</div>
				</details>
			<?php endforeach; ?>
		</div>
	<?php endforeach; ?>
</div>
