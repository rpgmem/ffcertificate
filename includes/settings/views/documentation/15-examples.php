<?php
/**
 * Documentation partial — Section 15: Complete Template Examples.
 *
 * Extracted from `ffc-tab-documentation.php` per S8 of the
 * god-object refactor (rpgmem/ffcertificate#141).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- 15. Complete Examples Section -->
<div class="card">
	<h3 id="examples" class="ffc-icon-note"><?php esc_html_e( '15. Complete Template Examples', 'ffcertificate' ); ?></h3>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Example 1: Simple Certificate', 'ffcertificate' ); ?></h4>
		<pre><code>&lt;div style="text-align: center; font-family: Arial; padding: 50px;"&gt;
	&lt;h1&gt;CERTIFICADO&lt;/h1&gt;
	
	&lt;p&gt;
		Certificamos que &lt;strong&gt;{{name}}&lt;/strong&gt;, 
		CPF &lt;strong&gt;{{cpf_rf}}&lt;/strong&gt;, 
		participou do evento &lt;strong&gt;{{form_title}}&lt;/strong&gt;.
	&lt;/p&gt;
	
	&lt;p&gt;Data: {{submission_date}}&lt;/p&gt;
	&lt;p&gt;Código: {{auth_code}}&lt;/p&gt;
	
	{{qr_code:size=150}}
&lt;/div&gt;</code></pre>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Example 2: Certificate with Header & Footer', 'ffcertificate' ); ?></h4>
		<pre><code>&lt;div style="font-family: Arial; padding: 30px;"&gt;
	&lt;!-- Header with logos --&gt;
	&lt;table width="100%"&gt;
		&lt;tr&gt;
			&lt;td width="25%"&gt;
				&lt;img src="https://example.com/logo-left.png" width="150"&gt;
			&lt;/td&gt;
			&lt;td width="50%" style="text-align: center;"&gt;
				&lt;div style="font-size: 10pt;"&gt;
					ORGANIZATION NAME&lt;br&gt;
					DEPARTMENT&lt;br&gt;
					DIVISION
				&lt;/div&gt;
			&lt;/td&gt;
			&lt;td width="25%" style="text-align: right;"&gt;
				&lt;img src="https://example.com/logo-right.png" width="150"&gt;
			&lt;/td&gt;
		&lt;/tr&gt;
	&lt;/table&gt;
	
	&lt;!-- Title --&gt;
	&lt;p style="text-align: center; margin-top: 40px;"&gt;
		&lt;strong style="font-size: 20pt;"&gt;CERTIFICATE OF ATTENDANCE&lt;/strong&gt;
	&lt;/p&gt;
	
	&lt;!-- Body --&gt;
	&lt;div style="text-align: center; margin: 40px 0; font-size: 12pt;"&gt;
		We certify that &lt;strong&gt;{{name}}&lt;/strong&gt;, 
		ID: &lt;strong&gt;{{cpf_rf}}&lt;/strong&gt;, 
		successfully attended the &lt;strong&gt;{{program}}&lt;/strong&gt; program 
		held on December 11, 2025.
	&lt;/div&gt;
	
	&lt;!-- Signature --&gt;
	&lt;table width="100%" style="margin-top: 60px;"&gt;
		&lt;tr&gt;
			&lt;td width="50%"&gt;&lt;/td&gt;
			&lt;td width="50%" style="text-align: center;"&gt;
				&lt;img src="https://example.com/signature.png" height="60"&gt;&lt;br&gt;
				&lt;div style="border-top: 1px solid #000; width: 200px; margin: 5px auto;"&gt;&lt;/div&gt;
				&lt;strong&gt;Director Name&lt;/strong&gt;&lt;br&gt;
				&lt;span style="font-size: 9pt;"&gt;Position Title&lt;/span&gt;
			&lt;/td&gt;
		&lt;/tr&gt;
	&lt;/table&gt;
	
	&lt;!-- Footer with QR Code --&gt;
	&lt;div style="margin-top: 60px;"&gt;
		&lt;table width="100%"&gt;
			&lt;tr&gt;
				&lt;td width="30%"&gt;
					{{qr_code:size=150:margin=0}}
				&lt;/td&gt;
				&lt;td width="70%" style="font-size: 9pt; vertical-align: middle;"&gt;
					Issued: {{submission_date}}&lt;br&gt;
					Verify at: https://example.com/verify/&lt;br&gt;
					Verification Code: &lt;strong&gt;{{auth_code}}&lt;/strong&gt;
				&lt;/td&gt;
			&lt;/tr&gt;
		&lt;/table&gt;
	&lt;/div&gt;
&lt;/div&gt;</code></pre>
	</div>
</div>
