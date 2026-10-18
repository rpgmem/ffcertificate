<?php
/**
 * Template: Date Messages — rules list.
 *
 * Included from page.php; see it for the variables in scope.
 *
 * @var bool                                             $can_manage Whether the user may change anything.
 * @var array<int, \FreeFormCertificate\DateMessages\Rule> $rules      Every rule.
 * @var array<int, string>                               $audiences  Audience id => name.
 *
 * @package FreeFormCertificate\DateMessages
 * @since   6.33.0
 */

use FreeFormCertificate\DateMessages\DateMessagesAdminPage;
use FreeFormCertificate\DateMessages\DateSources;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<p class="description">
	<?php esc_html_e( 'Each rule sends its own message on a date in each person\'s profile, or a number of days before it. The daily run sends every active rule; the Send now tab sends one rule over dates you choose.', 'ffcertificate' ); ?>
</p>

<?php if ( array() === $rules ) : ?>
	<p><?php esc_html_e( 'No rules yet.', 'ffcertificate' ); ?></p>
<?php else : ?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Name', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Date', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'When', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Audiences', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th>
				<?php if ( $can_manage ) : ?>
					<th scope="col"><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></th>
				<?php endif; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rules as $ffc_dm_rule ) : ?>
				<?php $ffc_dm_source = DateSources::get( $ffc_dm_rule->source ); ?>
				<tr>
					<td>
						<?php if ( $can_manage ) : ?>
							<a href="
							<?php
							echo esc_url(
								add_query_arg(
									array(
										'page' => DateMessagesAdminPage::MENU_SLUG,
										'rule' => $ffc_dm_rule->id,
									),
									admin_url( 'admin.php' )
								)
							);
							?>
										"><strong><?php echo esc_html( $ffc_dm_rule->name ); ?></strong></a>
						<?php else : ?>
							<strong><?php echo esc_html( $ffc_dm_rule->name ); ?></strong>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( null === $ffc_dm_source ? $ffc_dm_rule->source : $ffc_dm_source->label() ); ?></td>
					<td>
						<?php
						if ( 0 === $ffc_dm_rule->offset_days ) {
							esc_html_e( 'On the day', 'ffcertificate' );
						} elseif ( $ffc_dm_rule->offset_days < 0 ) {
							/* translators: %d: number of days */
							echo esc_html( sprintf( _n( '%d day before', '%d days before', abs( $ffc_dm_rule->offset_days ), 'ffcertificate' ), abs( $ffc_dm_rule->offset_days ) ) );
						} else {
							/* translators: %d: number of days */
							echo esc_html( sprintf( _n( '%d day after', '%d days after', $ffc_dm_rule->offset_days, 'ffcertificate' ), $ffc_dm_rule->offset_days ) );
						}
						?>
					</td>
					<td>
						<?php
						$ffc_dm_names = array();
						foreach ( $ffc_dm_rule->audience_ids as $ffc_dm_audience_id ) {
							// The options are indented for the tree; a list reads the plain name.
							$ffc_dm_names[] = isset( $audiences[ $ffc_dm_audience_id ] ) ? (string) preg_replace( '/^(?:— )+/u', '', $audiences[ $ffc_dm_audience_id ] ) : '#' . $ffc_dm_audience_id;
						}
						echo esc_html( array() === $ffc_dm_names ? __( 'Everyone', 'ffcertificate' ) : implode( ', ', $ffc_dm_names ) );
						?>
					</td>
					<td>
						<?php
						if ( ! $ffc_dm_rule->is_active ) {
							esc_html_e( 'Inactive', 'ffcertificate' );
						} elseif ( ! $ffc_dm_rule->send_to_user ) {
							esc_html_e( 'Active, not sending to people', 'ffcertificate' );
						} else {
							esc_html_e( 'Active', 'ffcertificate' );
						}
						?>
					</td>
					<?php if ( $can_manage ) : ?>
						<td>
							<?php
							// On/off is a state, not an action: the toggle saves the rule's
							// `is_active` the moment it flips, as on the rule editor (#1904).
							\FreeFormCertificate\Admin\AdminUI::render_toggle(
								array(
									'name'       => 'ffc_dm_rule_active_' . $ffc_dm_rule->id,
									'id'         => 'ffc-dm-rule-active-' . $ffc_dm_rule->id,
									'checked'    => $ffc_dm_rule->is_active,
									'label'      => __( 'Active', 'ffcertificate' ),
									'aria_label' => sprintf(
										/* translators: %s: rule name */
										__( 'Active: %s', 'ffcertificate' ),
										$ffc_dm_rule->name
									),
									'data'       => \FreeFormCertificate\Core\RecordAutosave::attrs( 'date_message_rule', $ffc_dm_rule->id, 'is_active' ),
								)
							);
							$ffc_dm_actions = array(
								DateMessagesAdminPage::DUPLICATE_ACTION => array( __( 'Duplicate', 'ffcertificate' ), 'create', 'copy' ),
								DateMessagesAdminPage::DELETE_ACTION    => array( __( 'Delete', 'ffcertificate' ), 'danger', 'delete' ),
							);
							foreach ( $ffc_dm_actions as $ffc_dm_action => $ffc_dm_button ) :
								?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ffc-dm-rule-action"<?php echo DateMessagesAdminPage::DELETE_ACTION === $ffc_dm_action ? ' data-ffc-confirm="' . esc_attr__( 'Delete this rule? What it already sent stays in the history.', 'ffcertificate' ) . '"' : ''; ?>>
									<?php wp_nonce_field( $ffc_dm_action . '_' . $ffc_dm_rule->id ); ?>
									<input type="hidden" name="action" value="<?php echo esc_attr( $ffc_dm_action ); ?>">
									<input type="hidden" name="rule_id" value="<?php echo esc_attr( (string) $ffc_dm_rule->id ); ?>">
									<?php
									\FreeFormCertificate\Admin\AdminUI::render_button(
										array(
											'label'    => $ffc_dm_button[0],
											'category' => $ffc_dm_button[1],
											'icon'     => $ffc_dm_button[2],
											'type'     => 'submit',
										)
									);
									?>
								</form>
							<?php endforeach; ?>
						</td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
