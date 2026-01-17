<?php
/**
 * Documentation Tab - COMPLETE VERSION
 * @version 3.0.0 - All original content + improved structure
 */

if (!defined('ABSPATH')) exit;
?>

<div class="ffc-settings-wrap">

<!-- Main Documentation Card with TOC -->
<div class="card">
    <h2>📚 <?php esc_html_e('Complete Plugin Documentation', 'ffc'); ?></h2>
    <p><?php esc_html_e('This plugin allows you to create certificate issuance forms, generate PDFs automatically, and verify authenticity with QR codes.', 'ffc'); ?></p>
    
    <!-- Table of Contents -->
    <div class="ffc-doc-toc">
        <h3><?php esc_html_e('Quick Navigation', 'ffc'); ?></h3>
        <ul class="ffc-doc-toc-list">
            <li><a href="#shortcodes">📌 <?php esc_html_e('1. Shortcodes', 'ffc'); ?></a></li>
            <li><a href="#variables">🏷️ <?php esc_html_e('2. Template Variables', 'ffc'); ?></a></li>
            <li><a href="#qr-code">📱 <?php esc_html_e('3. QR Code Options', 'ffc'); ?></a></li>
            <li><a href="#validation-url">🔗 <?php esc_html_e('4. Validation URL', 'ffc'); ?></a></li>
            <li><a href="#html-styling">🎨 <?php esc_html_e('5. HTML & Styling', 'ffc'); ?></a></li>
            <li><a href="#custom-fields">✏️ <?php esc_html_e('6. Custom Fields', 'ffc'); ?></a></li>
            <li><a href="#features">🎉 <?php esc_html_e('7. Features', 'ffc'); ?></a></li>
            <li><a href="#security">🔒 <?php esc_html_e('8. Security Features', 'ffc'); ?></a></li>
            <li><a href="#examples">📝 <?php esc_html_e('9. Complete Examples', 'ffc'); ?></a></li>
            <li><a href="#troubleshooting">🔧 <?php esc_html_e('10. Troubleshooting', 'ffc'); ?></a></li>
        </ul>
    </div>
</div>

<!-- 1. Shortcodes Section -->
<div class="card">
    <h3 id="shortcodes">📌 <?php esc_html_e('1. Shortcodes', 'ffc'); ?></h3>
    
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Shortcode', 'ffc'); ?></th>
                <th><?php esc_html_e('Description', 'ffc'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>[ffc_form id="123"]</code></td>
                <td>
                    <?php esc_html_e('Displays the certificate issuance form.', 'ffc'); ?><br>
                    <strong><?php esc_html_e('Usage:', 'ffc'); ?></strong> <?php esc_html_e('Replace "123" with your Form ID from the "All Forms" list.', 'ffc'); ?>
                </td>
            </tr>
            <tr>
                <td><code>[ffc_verification]</code></td>
                <td>
                    <?php esc_html_e('Displays the public verification page.', 'ffc'); ?><br>
                    <strong><?php esc_html_e('Usage:', 'ffc'); ?></strong> <?php esc_html_e('Users can validate certificates by entering the authentication code.', 'ffc'); ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<!-- 2. Template Variables Section -->
<div class="card">
    <h3 id="variables">🏷️ <?php esc_html_e('2. PDF Template Variables', 'ffc'); ?></h3>
    <p><?php esc_html_e('Use these variables in your PDF template (HTML editor). They will be automatically replaced with user data:', 'ffc'); ?></p>
    
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Variable', 'ffc'); ?></th>
                <th><?php esc_html_e('Description', 'ffc'); ?></th>
                <th><?php esc_html_e('Example Output', 'ffc'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>{{name}}</code><br><code>{{nome}}</code></td>
                <td><?php esc_html_e('Full name of the participant', 'ffc'); ?></td>
                <td><em>John Doe</em></td>
            </tr>
            <tr>
                <td><code>{{cpf_rf}}</code></td>
                <td><?php esc_html_e('ID/CPF/RF entered by user', 'ffc'); ?></td>
                <td><em>123.456.789-00</em></td>
            </tr>
            <tr>
                <td><code>{{email}}</code></td>
                <td><?php esc_html_e('User email address', 'ffc'); ?></td>
                <td><em>john_doe@example.com</em></td>
            </tr>
            <tr>
                <td><code>{{auth_code}}</code></td>
                <td><?php esc_html_e('Unique authentication code for validation', 'ffc'); ?></td>
                <td><em>A1B2-C3D4-E5F6</em></td>
            </tr>
            <tr>
                <td><code>{{form_title}}</code></td>
                <td><?php esc_html_e('Title of the form/event', 'ffc'); ?></td>
                <td><em>Workshop 2025</em></td>
            </tr>
            <tr>
                <td><code>{{submission_date}}</code></td>
                <td><?php esc_html_e('Date certificate was issued', 'ffc'); ?></td>
                <td><em>29/12/2025</em></td>
            </tr>
            <tr>
                <td><code>{{program}}</code></td>
                <td><?php esc_html_e('Program/Course name (if custom field exists)', 'ffc'); ?></td>
                <td><em>Advanced Training</em></td>
            </tr>
            <tr>
                <td><code>{{qr_code}}</code></td>
                <td><?php esc_html_e('QR Code image (see section 3 for options)', 'ffc'); ?></td>
                <td><em>QRCode Image to Magic Link</em></td>
            </tr>
            <tr>
                <td><code>{{validation_url}}</code></td>
                <td><?php esc_html_e('Link to page with certificate validation', 'ffc'); ?></td>
                <td><em>Link to page with certificate validation</em></td>
            </tr>
            <tr>
                <td><code>{{custom_field}}</code></td>
                <td><?php esc_html_e('Any custom field name you created', 'ffc'); ?></td>
                <td><em>[Your Data]</em></td>
            </tr>
        </tbody>
    </table>
</div>

<!-- 3. QR Code Options Section -->
<div class="card">
    <h3 id="qr-code">📱 <?php esc_html_e('3. QR Code Options & Attributes', 'ffc'); ?></h3>
    <p><?php esc_html_e('The QR code can be customized with various attributes:', 'ffc'); ?></p>
    
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Usage', 'ffc'); ?></th>
                <th><?php esc_html_e('Description', 'ffc'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>{{qr_code}}</code></td>
                <td>
                    <?php esc_html_e('Default QR code (uses settings from QR Code tab)', 'ffc'); ?><br>
                    <strong><?php esc_html_e('Default size:', 'ffc'); ?></strong> 200x200px
                </td>
            </tr>
            <tr>
                <td><code>{{qr_code:size=150}}</code></td>
                <td>
                    <?php esc_html_e('Custom size (150x150 pixels)', 'ffc'); ?><br>
                    <strong><?php esc_html_e('Range:', 'ffc'); ?></strong> <?php esc_html_e('100px at 500px', 'ffc'); ?>
                </td>
            </tr>
            <tr>
                <td><code>{{qr_code:margin=0}}</code></td>
                <td>
                    <?php esc_html_e('No white margin around QR code', 'ffc'); ?><br>
                    <strong><?php esc_html_e('Range:', 'ffc'); ?></strong> 0-10 <?php esc_html_e('(default: 2)', 'ffc'); ?>
                </td>
            </tr>
            <tr>
                <td><code>{{qr_code:error_level=H}}</code></td>
                <td>
                    <?php esc_html_e('Error correction level', 'ffc'); ?><br>
                    <strong><?php esc_html_e('Options:', 'ffc'); ?></strong><br>
                    • <code>L</code> = <?php esc_html_e('Low (7%)', 'ffc'); ?><br>
                    • <code>M</code> = <?php esc_html_e('Medium (15% - recommended)', 'ffc'); ?><br>
                    • <code>Q</code> = <?php esc_html_e('Quartile (25%)', 'ffc'); ?><br>
                    • <code>H</code> = <?php esc_html_e('High (30%)', 'ffc'); ?>
                </td>
            </tr>
            <tr>
                <td><code>{{qr_code:size=200:margin=1:error_level=M}}</code></td>
                <td><?php esc_html_e('Combining multiple attributes (separate with colons)', 'ffc'); ?></td>
            </tr>
        </tbody>
    </table>
</div>

<!-- 4. Validation URL Section -->
<div class="card">
    <h3 id="validation-url">🔗 <?php esc_html_e('4. Validation URL', 'ffc'); ?></h3>
    <p><?php esc_html_e('The Validation URL can be customized with various attributes:', 'ffc'); ?></p>
    
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Usage', 'ffc'); ?></th>
                <th><?php esc_html_e('Description', 'ffc'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>{{validation_url}}</code></td>
                <td>
                    <?php esc_html_e('Default: link to magic, text shows /valid', 'ffc'); ?>
                </td>
            </tr>
            <tr>
                <td><code>{{validation_url link:X>Y}}</code></td>
                <td>
                    <code>{{validation_url link:m>v}}</code> → <?php esc_html_e('Link to magic, text /valid', 'ffc'); ?><br>
                    <code>{{validation_url link:v>v}}</code> → <?php esc_html_e('Link to /valid, text /valid', 'ffc'); ?><br>
                    <code>{{validation_url link:m>m}}</code> → <?php esc_html_e('Link to magic, text magic', 'ffc'); ?><br>
                    <code>{{validation_url link:v>m}}</code> → <?php esc_html_e('Link to /valid, text magic', 'ffc'); ?><br>
                    <code>{{validation_url link:v>"Custom Text"}}</code> → <?php esc_html_e('Link to /valid, custom text', 'ffc'); ?><br>
                    <code>{{validation_url link:m>"Custom Text"}}</code> →  <?php esc_html_e('Link to magic, custom text', 'ffc'); ?><br>
                    <code>{{validation_url link:m>v target:_blank}}</code> → <?php esc_html_e('With target', 'ffc'); ?><br>
                    <code>{{validation_url link:m>v color:blue}}</code> → <?php esc_html_e('With color link', 'ffc'); ?><br>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<!-- 5. HTML & Styling Section -->
<div class="card">
    <h3 id="html-styling">🎨 <?php esc_html_e('5. HTML & Styling', 'ffc'); ?></h3>
    <p><?php esc_html_e('You can use HTML and inline CSS to style your certificate:', 'ffc'); ?></p>

    <h4><?php esc_html_e('Supported HTML Tags:', 'ffc'); ?></h4>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Tag', 'ffc'); ?></th>
                <th><?php esc_html_e('Usage', 'ffc'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>&lt;strong&gt;</code> <code>&lt;b&gt;</code></td>
                <td><?php esc_html_e('Bold text:', 'ffc'); ?> <code>&lt;strong&gt;{{name}}&lt;/strong&gt;</code></td>
            </tr>
            <tr>
                <td><code>&lt;em&gt;</code> <code>&lt;i&gt;</code></td>
                <td><?php esc_html_e('Italic text:', 'ffc'); ?> <code>&lt;em&gt;Certificate&lt;/em&gt;</code></td>
            </tr>
            <tr>
                <td><code>&lt;u&gt;</code></td>
                <td><?php esc_html_e('Underline text:', 'ffc'); ?> <code>&lt;u&gt;Important&lt;/u&gt;</code></td>
            </tr>
            <tr>
                <td><code>&lt;br&gt;</code></td>
                <td><?php esc_html_e('Line break', 'ffc'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;p&gt;</code></td>
                <td><?php esc_html_e('Paragraph with spacing', 'ffc'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;div&gt;</code></td>
                <td><?php esc_html_e('Container for sections', 'ffc'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;table&gt;</code> <code>&lt;tr&gt;</code> <code>&lt;td&gt;</code></td>
                <td><?php esc_html_e('Tables for layout (logos, signatures)', 'ffc'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;img&gt;</code></td>
                <td><?php esc_html_e('Images (logos, signatures, decorations)', 'ffc'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;h1&gt;</code> <code>&lt;h2&gt;</code> <code>&lt;h3&gt;</code></td>
                <td><?php esc_html_e('Headers/titles', 'ffc'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;ul&gt;</code> <code>&lt;ol&gt;</code> <code>&lt;li&gt;</code></td>
                <td><?php esc_html_e('Lists (bullet or numbered)', 'ffc'); ?></td>
            </tr>
        </tbody>
    </table>

    <h4><?php esc_html_e('Image Attributes:', 'ffc'); ?></h4>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Example', 'ffc'); ?></th>
                <th><?php esc_html_e('Result', 'ffc'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>&lt;img src="logo.png" width="200"&gt;</code></td>
                <td><?php esc_html_e('Logo with fixed width', 'ffc'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;img src="signature.png" height="80"&gt;</code></td>
                <td><?php esc_html_e('Signature with fixed height, proportional width', 'ffc'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;img src="photo.png" width="150" height="150"&gt;</code></td>
                <td><?php esc_html_e('Photo cropped to fit dimensions', 'ffc'); ?></td>
            </tr>
        </tbody>
    </table>

    <h4><?php esc_html_e('Common Inline Styles:', 'ffc'); ?></h4>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Style', 'ffc'); ?></th>
                <th><?php esc_html_e('Example', 'ffc'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php esc_html_e('Font size', 'ffc'); ?></td>
                <td><code>style="font-size: 14pt;"</code></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Text color', 'ffc'); ?></td>
                <td><code>style="color: #2271b1;"</code></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Text alignment', 'ffc'); ?></td>
                <td><code>style="text-align: center;"</code></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Background color', 'ffc'); ?></td>
                <td><code>style="background-color: #f0f0f0;"</code></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Margins/padding', 'ffc'); ?></td>
                <td><code>style="margin: 20px; padding: 15px;"</code></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Font family', 'ffc'); ?></td>
                <td><code>style="font-family: Arial, sans-serif;"</code></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Border', 'ffc'); ?></td>
                <td><code>style="border: 2px solid #000;"</code></td>
            </tr>
        </tbody>
    </table>
</div>

<!-- 6. Custom Fields Section -->
<div class="card">
    <h3 id="custom-fields">✏️ <?php esc_html_e('6. Custom Fields', 'ffc'); ?></h3>
    
    <p><?php esc_html_e('Any custom field you create in Form Builder automatically becomes a template variable:', 'ffc'); ?></p>
    
    <div class="ffc-doc-example">
        <h4><?php _e('How It Works:', 'ffc'); ?></h4>
        <ul>
            <li><strong><?php _e('Step 1:', 'ffc'); ?></strong> <?php _e('Create a field in Form Builder (e.g., field name:', 'ffc'); ?> "company"</li>
            <li><strong><?php _e('Step 2:', 'ffc'); ?></strong> <?php _e('Use in template:', 'ffc'); ?> <code>{{company}}</code></li>
            <li><strong><?php _e('Step 3:', 'ffc'); ?></strong> <?php _e('Value gets replaced automatically in PDF', 'ffc'); ?></li>
        </ul>
    </div>
    
    <div class="ffc-doc-example">
        <h4><?php _e('Example:', 'ffc'); ?></h4>
        <p><?php _e('If you create these custom fields:', 'ffc'); ?></p>
        <ul>
            <li><code>company</code> → <?php _e('Use:', 'ffc'); ?> <code>{{company}}</code></li>
            <li><code>department</code> → <?php _e('Use:', 'ffc'); ?> <code>{{department}}</code></li>
            <li><code>course_hours</code> → <?php _e('Use:', 'ffc'); ?> <code>{{course_hours}}</code></li>
        </ul>
    </div>
</div>

<!-- 7. Features Section -->
<div class="card">
    <h3 id="features">🎉 <?php esc_html_e('7. Features', 'ffc'); ?></h3>
    
    <ul class="ffc-doc-list">
        <li>
            <strong><?php _e('Unique Authentication Codes:', 'ffc'); ?></strong><br> 
            <?php _e('Every certificate gets a unique 12-character code (e.g., A1B2-C3D4-E5F6)', 'ffc'); ?>
        </li>
        <li>
            <strong><?php _e('QR Code Validation:', 'ffc'); ?></strong><br> 
            <?php _e('Scan to instantly verify certificate authenticity', 'ffc'); ?>
        </li>
        <li>
            <strong><?php _e('Magic Links:', 'ffc'); ?></strong><br> 
            <?php _e('Links that don\'t pass validation on the website. Shared by email and quickly verifying the certificate\'s.', 'ffc'); ?>
        </li>
        <li>
            <strong><?php _e('Reprinting certificates:', 'ffc'); ?></strong><br> 
            <?php _e('Previously submitted identification information (CPF/RF) does not generate new certificates.', 'ffc'); ?>
        </li>
        <li>
            <strong><?php _e('CSV Export:', 'ffc'); ?></strong><br> 
            <?php _e('Generate a CSV list with the submissions already sent.', 'ffc'); ?>
        </li>
        <li>
            <strong><?php _e('Email Notifications:', 'ffc'); ?></strong><br> 
            <?php _e('Automatic (or not) email sent with certificate PDF attached upon submission', 'ffc'); ?>
        </li>
        <li>
            <strong><?php _e('PDF Customization:', 'ffc'); ?></strong><br> 
            <?php _e('Full HTML editor to design your own certificate layout', 'ffc'); ?>
        </li>
        <li>
            <strong><?php _e('Auto-delete:', 'ffc'); ?></strong><br> 
            <?php _e('Ensure submissions are deleted after "X" days.', 'ffc'); ?>
        </li>
        <li>
            <strong><?php _e('Date Format:', 'ffc'); ?></strong><br> 
            <?php _e('Format used for {{submission_date}} placeholder in PDFs and emails.', 'ffc'); ?>
        </li>
        <li>
            <strong><?php _e('Data Migrations:', 'ffc'); ?></strong><br> 
            <?php _e('Migration of all data from the plugin\'s old infrastructure.', 'ffc'); ?>
        </li>
        <li>
            <strong><?php _e('Form Cache:', 'ffc'); ?></strong><br> 
            <?php _e('The cache stores form settings to improve performance.', 'ffc'); ?>
        </li>
        <li>
            <strong><?php _e('Multi-language Support:', 'ffc'); ?></strong><br> 
            <?php _e('Supports Portuguese and English languages', 'ffc'); ?>
        </li>
    </ul>
</div>

<!-- 8. Security Features Section -->
<div class="card">
    <h3 id="security">🔒 <?php esc_html_e('8. Security Features', 'ffc'); ?></h3>
    
    <ul class="ffc-doc-list">
        <li>
            <strong><?php _e('Single Password:', 'ffc'); ?></strong><br> 
            <?php _e('The form will have a global password for submission.', 'ffc'); ?>
        </li>
        <li>
            <strong><?php _e('Allowlist/Denylist:', 'ffc'); ?></strong><br>
            <?php _e('Ensure that the listed IDs are allowed or blocked from retrieving certificates.', 'ffc'); ?>
        </li>
        <li>
            <strong><?php _e('Ticket (Unique Codes):', 'ffc'); ?></strong><br>
            <?php _e('Require users to have a single-use ticket to generate the certificate (it is consumed after use).', 'ffc'); ?>
        </li>
        <li>
            <strong><?php _e('Rate Limiting:', 'ffc'); ?></strong><br>
            <?php _e('Prevents abuse with configurable submission limits', 'ffc'); ?>
        </li>
        <li>
            <strong><?php _e('Data Encryption:', 'ffc'); ?></strong><br>
            <?php _e('Encryption for sensitive data (LGPD compliant)', 'ffc'); ?>
        </li>
        <li>
            <strong><?php _e('Honeypot Fields:', 'ffc'); ?></strong><br>
            <?php _e('Invisible spam protection', 'ffc'); ?>
        </li>
        <li>
            <strong><?php _e('Math CAPTCHA:', 'ffc'); ?></strong><br>
            <?php _e('Basic humanity verification', 'ffc'); ?>
        </li>
    </ul>
</div>

<!-- 9. Complete Examples Section -->
<div class="card">
    <h3 id="examples">📝 <?php esc_html_e('9. Complete Template Examples', 'ffc'); ?></h3>

    <div class="ffc-doc-example">
        <h4><?php esc_html_e('Example 1: Simple Certificate', 'ffc'); ?></h4>
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
        <h4><?php esc_html_e('Example 2: Certificate with Header & Footer', 'ffc'); ?></h4>
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

<!-- 10. Troubleshooting Section -->
<div class="card">
    <h3 id="troubleshooting">🔧 <?php esc_html_e('10. Troubleshooting', 'ffc'); ?></h3>

    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Problem', 'ffc'); ?></th>
                <th><?php esc_html_e('Solution', 'ffc'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php esc_html_e('Variable not replaced', 'ffc'); ?> <code>{{name}}</code></td>
                <td>
                    • <?php esc_html_e('Check spelling matches exactly', 'ffc'); ?><br>
                    • <?php esc_html_e('Ensure field exists in form', 'ffc'); ?><br>
                    • <?php esc_html_e('Use lowercase for custom fields', 'ffc'); ?>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e('Image not showing in PDF', 'ffc'); ?></td>
                <td>
                    • <?php esc_html_e('Use absolute URLs (https://...)', 'ffc'); ?><br>
                    • <?php esc_html_e('Check image is publicly accessible', 'ffc'); ?><br>
                    • <?php esc_html_e('Add width/height attributes', 'ffc'); ?>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e('QR Code too large/small', 'ffc'); ?></td>
                <td>
                    • <?php esc_html_e('Use:', 'ffc'); ?> <code>{{qr_code:size=150}}</code><br>
                    • <?php esc_html_e('Recommended: 100-200px for certificates', 'ffc'); ?>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e('Formatting not showing (bold, italic)', 'ffc'); ?></td>
                <td>
                    • <?php esc_html_e('Use HTML tags:', 'ffc'); ?> <code>&lt;strong&gt;</code> <code>&lt;em&gt;</code><br>
                    • <?php esc_html_e('Or inline style:', 'ffc'); ?> <code>style="font-weight: bold;"</code>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e('Layout broken in PDF', 'ffc'); ?></td>
                <td>
                    • <?php esc_html_e('Use tables for complex layouts', 'ffc'); ?><br>
                    • <?php esc_html_e('Always use inline styles', 'ffc'); ?><br>
                    • <?php esc_html_e('Test with simple content first', 'ffc'); ?>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e('Settings not saving between tabs', 'ffc'); ?></td>
                <td>
                    • <?php esc_html_e('Update to latest version', 'ffc'); ?><br>
                    • <?php esc_html_e('Clear WordPress cache', 'ffc'); ?><br>
                    • <?php esc_html_e('Clear browser cache (Ctrl+F5)', 'ffc'); ?>
                </td>
            </tr>
        </tbody>
    </table>

    <div class="ffc-alert ffc-alert-info ffc-mt-20">
        <p>
            <strong>ℹ️ <?php esc_html_e('Need More Help?', 'ffc'); ?></strong><br>
            <?php esc_html_e('For additional support, check the plugin repository documentation or contact support.', 'ffc'); ?>
        </p>
    </div>
</div>

</div><!-- .ffc-settings-wrap -->