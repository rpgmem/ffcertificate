<?php
/**
 * Documentation partial — About this project.
 *
 * What the plugin is as a project rather than as software: free software
 * under the AGPLv3, free of charge forever, made by public servants in
 * Brazil (rpgmem/ffcertificate#1857). Every outbound link is its own
 * translatable string, so a translation can point at a page in its own
 * language.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An external link that opens in a new tab.
 *
 * @param string $ffc_url  Destination.
 * @param string $ffc_text Visible text.
 * @return string
 */
$ffc_about_link = static function ( string $ffc_url, string $ffc_text ): string {
	return '<a href="' . esc_url( $ffc_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $ffc_text ) . '</a>';
};

$ffc_about_repo = (string) get_file_data( FFC_PLUGIN_DIR . 'ffcertificate.php', array( 'uri' => 'Plugin URI' ) )['uri'];
?>
<!-- About this project -->
<div class="card">
	<h3 id="about-project" class="ffc-icon-globe"><?php esc_html_e( 'About this project', 'ffcertificate' ); ?></h3>

	<h4><?php esc_html_e( 'Free software, made by public servants', 'ffcertificate' ); ?></h4>
	<p>
		<?php
		echo wp_kses_post(
			sprintf(
				/* translators: 1: link to an article on free software, 2: link to the GNU AGPLv3 license */
				__( 'Free Form Certificate is developed and maintained by public servants in Brazil and distributed as %1$s under the %2$s license. We believe that what is built with public resources should return to the public.', 'ffcertificate' ),
				$ffc_about_link( __( 'https://en.wikipedia.org/wiki/Free_software', 'ffcertificate' ), __( 'free software', 'ffcertificate' ) ),
				$ffc_about_link( 'https://www.gnu.org/licenses/agpl-3.0.html', 'GNU AGPLv3' )
			)
		);
		?>
	</p>

	<ul class="ffc-doc-list">
		<li>
			<strong><?php esc_html_e( 'Free of charge, always.', 'ffcertificate' ); ?></strong>
			<?php esc_html_e( 'There is no paid edition and there never will be: no locked feature, no subscription, no "pro" version. Every function described here is available to any institution.', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Free to use, study and adapt.', 'ffcertificate' ); ?></strong>
			<?php esc_html_e( 'Any public body, school or organisation may install, modify and redistribute it. The AGPL only asks that improvements you distribute, or offer as a network service, stay free as well.', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Built to be replicated.', 'ffcertificate' ); ?></strong>
			<?php esc_html_e( 'Each module can be switched on or off, so another school network or public body can adopt the same solution without depending on a vendor.', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Accessible, and careful with personal data.', 'ffcertificate' ); ?></strong>
			<?php
			echo wp_kses_post(
				sprintf(
					/* translators: 1: link to an article on the WCAG accessibility guidelines, 2: link to an article on Brazil's data protection law (LGPD) */
					__( 'Screens follow the %1$s accessibility guidelines, offer a dark mode and work on a phone. CPF, RF and e-mail are stored encrypted, in line with Brazil\'s data protection law, the %2$s.', 'ffcertificate' ),
					$ffc_about_link( __( 'https://en.wikipedia.org/wiki/Web_Content_Accessibility_Guidelines', 'ffcertificate' ), 'WCAG' ),
					$ffc_about_link( __( 'https://en.wikipedia.org/wiki/General_Personal_Data_Protection_Law', 'ffcertificate' ), 'LGPD' )
				)
			);
			?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Your data stays with you.', 'ffcertificate' ); ?></strong>
			<?php esc_html_e( 'The plugin collects no usage data and shows no advertising. It runs on your own server, and its only outbound calls are the update check and the optional services you choose to enable.', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Transparent by default.', 'ffcertificate' ); ?></strong>
			<?php esc_html_e( 'The source code, every change (the changelog) and the list of known problems are public. Each change goes through automated tests before it is released.', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Open to collaboration.', 'ffcertificate' ); ?></strong>
			<?php
			echo wp_kses_post(
				sprintf(
					/* translators: %s: link to the plugin's source repository */
					__( 'Suggestions, bug reports and contributions are welcome in the %s.', 'ffcertificate' ),
					$ffc_about_link( $ffc_about_repo, __( 'repository', 'ffcertificate' ) )
				)
			);
			?>
		</li>
	</ul>

	<h4><?php esc_html_e( 'Our goal', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'To give public institutions a reliable, accessible and secure way to issue documents, run campaigns and serve people online, at no cost and without vendor lock-in, and to show that public servants can build digital public goods of high technical quality.', 'ffcertificate' ); ?></p>
</div>
