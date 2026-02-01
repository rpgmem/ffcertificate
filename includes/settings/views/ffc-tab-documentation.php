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
    <h2>📚 <?php esc_html_e('Complete Plugin Documentation', 'wp-ffcertificate'); ?></h2>
    <p><?php esc_html_e('This plugin allows you to create certificate issuance forms, generate PDFs automatically, and verify authenticity with QR codes.', 'wp-ffcertificate'); ?></p>
    
    <!-- Table of Contents -->
    <div class="ffc-doc-toc">
        <h3><?php esc_html_e('Quick Navigation', 'wp-ffcertificate'); ?></h3>
        <ul class="ffc-doc-toc-list">
            <li><a href="#shortcodes">📌 <?php esc_html_e('1. Shortcodes', 'wp-ffcertificate'); ?></a></li>
            <li><a href="#variables">🏷️ <?php esc_html_e('2. Template Variables', 'wp-ffcertificate'); ?></a></li>
            <li><a href="#qr-code">📱 <?php esc_html_e('3. QR Code Options', 'wp-ffcertificate'); ?></a></li>
            <li><a href="#validation-url">🔗 <?php esc_html_e('4. Validation URL', 'wp-ffcertificate'); ?></a></li>
            <li><a href="#html-styling">🎨 <?php esc_html_e('5. HTML & Styling', 'wp-ffcertificate'); ?></a></li>
            <li><a href="#custom-fields">✏️ <?php esc_html_e('6. Custom Fields', 'wp-ffcertificate'); ?></a></li>
            <li><a href="#features">🎉 <?php esc_html_e('7. Features', 'wp-ffcertificate'); ?></a></li>
            <li><a href="#security">🔒 <?php esc_html_e('8. Security Features', 'wp-ffcertificate'); ?></a></li>
            <li><a href="#examples">📝 <?php esc_html_e('9. Complete Examples', 'wp-ffcertificate'); ?></a></li>
            <li><a href="#troubleshooting">🔧 <?php esc_html_e('10. Troubleshooting', 'wp-ffcertificate'); ?></a></li>
        </ul>
    </div>
</div>

<!-- 1. Shortcodes Section -->
<div class="card">
    <h3 id="shortcodes">📌 <?php esc_html_e('1. Shortcodes', 'wp-ffcertificate'); ?></h3>
    
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Shortcode', 'wp-ffcertificate'); ?></th>
                <th><?php esc_html_e('Description', 'wp-ffcertificate'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>[ffc_form id="123"]</code></td>
                <td>
                    <?php esc_html_e('Displays the certificate issuance form.', 'wp-ffcertificate'); ?><br>
                    <strong><?php esc_html_e('Usage:', 'wp-ffcertificate'); ?></strong> <?php esc_html_e('Replace "123" with your Form ID from the "All Forms" list.', 'wp-ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><code>[ffc_verification]</code></td>
                <td>
                    <?php esc_html_e('Displays the public verification page.', 'wp-ffcertificate'); ?><br>
                    <strong><?php esc_html_e('Usage:', 'wp-ffcertificate'); ?></strong> <?php esc_html_e('Users can validate certificates by entering the authentication code.', 'wp-ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><code>[user_dashboard_personal]</code></td>
                <td>
                    <?php esc_html_e('Displays dashboard page.', 'wp-ffcertificate'); ?><br>
                    <strong><?php esc_html_e('Usage:', 'wp-ffcertificate'); ?></strong> <?php esc_html_e('Logged-in users will be able to view all certificates generated for their own CPF/RF (Brazilian tax identification number).', 'wp-ffcertificate'); ?>
                </td>
            </tr>            
        </tbody>
    </table>
</div>

<!-- 2. Template Variables Section -->
<div class="card">
    <h3 id="variables">🏷️ <?php esc_html_e('2. PDF Template Variables', 'wp-ffcertificate'); ?></h3>
    <p><?php esc_html_e('Use these variables in your PDF template (HTML editor). They will be automatically replaced with user data:', 'wp-ffcertificate'); ?></p>
    
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Variable', 'wp-ffcertificate'); ?></th>
                <th><?php esc_html_e('Description', 'wp-ffcertificate'); ?></th>
                <th><?php esc_html_e('Example Output', 'wp-ffcertificate'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>{{name}}</code><br><code>{{nome}}</code></td>
                <td><?php esc_html_e('Full name of the participant', 'wp-ffcertificate'); ?></td>
                <td><em>John Doe</em></td>
            </tr>
            <tr>
                <td><code>{{cpf_rf}}</code></td>
                <td><?php esc_html_e('ID/CPF/RF entered by user', 'wp-ffcertificate'); ?></td>
                <td><em>123.456.789-00</em></td>
            </tr>
            <tr>
                <td><code>{{email}}</code></td>
                <td><?php esc_html_e('User email address', 'wp-ffcertificate'); ?></td>
                <td><em>john_doe@example.com</em></td>
            </tr>
            <tr>
                <td><code>{{auth_code}}</code></td>
                <td><?php esc_html_e('Unique authentication code for validation', 'wp-ffcertificate'); ?></td>
                <td><em>A1B2-C3D4-E5F6</em></td>
            </tr>
            <tr>
                <td><code>{{form_title}}</code></td>
                <td><?php esc_html_e('Title of the form/event', 'wp-ffcertificate'); ?></td>
                <td><em>Workshop 2025</em></td>
            </tr>
            <tr>
                <td><code>{{submission_date}}</code></td>
                <td><?php esc_html_e('Date when submission was created (from database)', 'wp-ffcertificate'); ?></td>
                <td><em>29/12/2025</em></td>
            </tr>
            <tr>
                <td><code>{{print_date}}</code></td>
                <td><?php esc_html_e('Current date/time when PDF is being generated', 'wp-ffcertificate'); ?></td>
                <td><em>20/01/2026</em></td>
            </tr>
            <tr>
                <td><code>{{program}}</code></td>
                <td><?php esc_html_e('Program/Course name (if custom field exists)', 'wp-ffcertificate'); ?></td>
                <td><em>Advanced Training</em></td>
            </tr>
            <tr>
                <td><code>{{qr_code}}</code></td>
                <td><?php esc_html_e('QR Code image (see section 3 for options)', 'wp-ffcertificate'); ?></td>
                <td><em>QRCode Image to Magic Link</em></td>
            </tr>
            <tr>
                <td><code>{{validation_url}}</code></td>
                <td><?php esc_html_e('Link to page with certificate validation', 'wp-ffcertificate'); ?></td>
                <td><em>Link to page with certificate validation</em></td>
            </tr>
            <tr>
                <td><code>{{custom_field}}</code></td>
                <td><?php esc_html_e('Any custom field name you created', 'wp-ffcertificate'); ?></td>
                <td><em>[Your Data]</em></td>
            </tr>
        </tbody>
    </table>
</div>

<!-- 3. QR Code Options Section -->
<div class="card">
    <h3 id="qr-code">📱 <?php esc_html_e('3. QR Code Options & Attributes', 'wp-ffcertificate'); ?></h3>
    <p><?php esc_html_e('The QR code can be customized with various attributes:', 'wp-ffcertificate'); ?></p>
    
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Usage', 'wp-ffcertificate'); ?></th>
                <th><?php esc_html_e('Description', 'wp-ffcertificate'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>{{qr_code}}</code></td>
                <td>
                    <?php esc_html_e('Default QR code (uses settings from QR Code tab)', 'wp-ffcertificate'); ?><br>
                    <strong><?php esc_html_e('Default size:', 'wp-ffcertificate'); ?></strong> 200x200px
                </td>
            </tr>
            <tr>
                <td><code>{{qr_code:size=150}}</code></td>
                <td>
                    <?php esc_html_e('Custom size (150x150 pixels)', 'wp-ffcertificate'); ?><br>
                    <strong><?php esc_html_e('Range:', 'wp-ffcertificate'); ?></strong> <?php esc_html_e('100px at 500px', 'wp-ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><code>{{qr_code:margin=0}}</code></td>
                <td>
                    <?php esc_html_e('No white margin around QR code', 'wp-ffcertificate'); ?><br>
                    <strong><?php esc_html_e('Range:', 'wp-ffcertificate'); ?></strong> 0-10 <?php esc_html_e('(default: 2)', 'wp-ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><code>{{qr_code:error_level=H}}</code></td>
                <td>
                    <?php esc_html_e('Error correction level', 'wp-ffcertificate'); ?><br>
                    <strong><?php esc_html_e('Options:', 'wp-ffcertificate'); ?></strong><br>
                    • <code>L</code> = <?php esc_html_e('Low (7%)', 'wp-ffcertificate'); ?><br>
                    • <code>M</code> = <?php esc_html_e('Medium (15% - recommended)', 'wp-ffcertificate'); ?><br>
                    • <code>Q</code> = <?php esc_html_e('Quartile (25%)', 'wp-ffcertificate'); ?><br>
                    • <code>H</code> = <?php esc_html_e('High (30%)', 'wp-ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><code>{{qr_code:size=200:margin=1:error_level=M}}</code></td>
                <td><?php esc_html_e('Combining multiple attributes (separate with colons)', 'wp-ffcertificate'); ?></td>
            </tr>
        </tbody>
    </table>
</div>

<!-- 4. Validation URL Section -->
<div class="card">
    <h3 id="validation-url">🔗 <?php esc_html_e('4. Validation URL', 'wp-ffcertificate'); ?></h3>
    <p><?php esc_html_e('The Validation URL can be customized with various attributes:', 'wp-ffcertificate'); ?></p>
    
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Usage', 'wp-ffcertificate'); ?></th>
                <th><?php esc_html_e('Description', 'wp-ffcertificate'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>{{validation_url}}</code></td>
                <td>
                    <?php esc_html_e('Default: link to magic, text shows /valid', 'wp-ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><code>{{validation_url link:X>Y}}</code></td>
                <td>
                    <code>{{validation_url link:m>v}}</code> → <?php esc_html_e('Link to magic, text /valid', 'wp-ffcertificate'); ?><br>
                    <code>{{validation_url link:v>v}}</code> → <?php esc_html_e('Link to /valid, text /valid', 'wp-ffcertificate'); ?><br>
                    <code>{{validation_url link:m>m}}</code> → <?php esc_html_e('Link to magic, text magic', 'wp-ffcertificate'); ?><br>
                    <code>{{validation_url link:v>m}}</code> → <?php esc_html_e('Link to /valid, text magic', 'wp-ffcertificate'); ?><br>
                    <code>{{validation_url link:v>"Custom Text"}}</code> → <?php esc_html_e('Link to /valid, custom text', 'wp-ffcertificate'); ?><br>
                    <code>{{validation_url link:m>"Custom Text"}}</code> →  <?php esc_html_e('Link to magic, custom text', 'wp-ffcertificate'); ?><br>
                    <code>{{validation_url link:m>v target:_blank}}</code> → <?php esc_html_e('With target', 'wp-ffcertificate'); ?><br>
                    <code>{{validation_url link:m>v color:blue}}</code> → <?php esc_html_e('With color link', 'wp-ffcertificate'); ?><br>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<!-- 5. HTML & Styling Section -->
<div class="card">
    <h3 id="html-styling">🎨 <?php esc_html_e('5. HTML & Styling', 'wp-ffcertificate'); ?></h3>
    <p><?php esc_html_e('You can use HTML and inline CSS to style your certificate:', 'wp-ffcertificate'); ?></p>

    <h4><?php esc_html_e('Supported HTML Tags:', 'wp-ffcertificate'); ?></h4>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Tag', 'wp-ffcertificate'); ?></th>
                <th><?php esc_html_e('Usage', 'wp-ffcertificate'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>&lt;strong&gt;</code> <code>&lt;b&gt;</code></td>
                <td><?php esc_html_e('Bold text:', 'wp-ffcertificate'); ?> <code>&lt;strong&gt;{{name}}&lt;/strong&gt;</code></td>
            </tr>
            <tr>
                <td><code>&lt;em&gt;</code> <code>&lt;i&gt;</code></td>
                <td><?php esc_html_e('Italic text:', 'wp-ffcertificate'); ?> <code>&lt;em&gt;Certificate&lt;/em&gt;</code></td>
            </tr>
            <tr>
                <td><code>&lt;u&gt;</code></td>
                <td><?php esc_html_e('Underline text:', 'wp-ffcertificate'); ?> <code>&lt;u&gt;Important&lt;/u&gt;</code></td>
            </tr>
            <tr>
                <td><code>&lt;br&gt;</code></td>
                <td><?php esc_html_e('Line break', 'wp-ffcertificate'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;p&gt;</code></td>
                <td><?php esc_html_e('Paragraph with spacing', 'wp-ffcertificate'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;div&gt;</code></td>
                <td><?php esc_html_e('Container for sections', 'wp-ffcertificate'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;table&gt;</code> <code>&lt;tr&gt;</code> <code>&lt;td&gt;</code></td>
                <td><?php esc_html_e('Tables for layout (logos, signatures)', 'wp-ffcertificate'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;img&gt;</code></td>
                <td><?php esc_html_e('Images (logos, signatures, decorations)', 'wp-ffcertificate'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;h1&gt;</code> <code>&lt;h2&gt;</code> <code>&lt;h3&gt;</code></td>
                <td><?php esc_html_e('Headers/titles', 'wp-ffcertificate'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;ul&gt;</code> <code>&lt;ol&gt;</code> <code>&lt;li&gt;</code></td>
                <td><?php esc_html_e('Lists (bullet or numbered)', 'wp-ffcertificate'); ?></td>
            </tr>
        </tbody>
    </table>

    <h4><?php esc_html_e('Image Attributes:', 'wp-ffcertificate'); ?></h4>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Example', 'wp-ffcertificate'); ?></th>
                <th><?php esc_html_e('Result', 'wp-ffcertificate'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>&lt;img src="logo.png" width="200"&gt;</code></td>
                <td><?php esc_html_e('Logo with fixed width', 'wp-ffcertificate'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;img src="signature.png" height="80"&gt;</code></td>
                <td><?php esc_html_e('Signature with fixed height, proportional width', 'wp-ffcertificate'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;img src="photo.png" width="150" height="150"&gt;</code></td>
                <td><?php esc_html_e('Photo cropped to fit dimensions', 'wp-ffcertificate'); ?></td>
            </tr>
        </tbody>
    </table>

    <h4><?php esc_html_e('Common Inline Styles:', 'wp-ffcertificate'); ?></h4>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Style', 'wp-ffcertificate'); ?></th>
                <th><?php esc_html_e('Example', 'wp-ffcertificate'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php esc_html_e('Font size', 'wp-ffcertificate'); ?></td>
                <td><code>style="font-size: 14pt;"</code></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Text color', 'wp-ffcertificate'); ?></td>
                <td><code>style="color: #2271b1;"</code></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Text alignment', 'wp-ffcertificate'); ?></td>
                <td><code>style="text-align: center;"</code></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Background color', 'wp-ffcertificate'); ?></td>
                <td><code>style="background-color: #f0f0f0;"</code></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Margins/padding', 'wp-ffcertificate'); ?></td>
                <td><code>style="margin: 20px; padding: 15px;"</code></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Font family', 'wp-ffcertificate'); ?></td>
                <td><code>style="font-family: Arial, sans-serif;"</code></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Border', 'wp-ffcertificate'); ?></td>
                <td><code>style="border: 2px solid #000;"</code></td>
            </tr>
        </tbody>
    </table>
</div>

<!-- 6. Custom Fields Section -->
<div class="card">
    <h3 id="custom-fields">✏️ <?php esc_html_e('6. Custom Fields', 'wp-ffcertificate'); ?></h3>
    
    <p><?php esc_html_e('Any custom field you create in Form Builder automatically becomes a template variable:', 'wp-ffcertificate'); ?></p>
    
    <div class="ffc-doc-example">
        <h4><?php esc_html_e('How It Works:', 'wp-ffcertificate'); ?></h4>
        <ul>
            <li><strong><?php esc_html_e('Step 1:', 'wp-ffcertificate'); ?></strong> <?php esc_html_e('Create a field in Form Builder (e.g., field name:', 'wp-ffcertificate'); ?> "company"</li>
            <li><strong><?php esc_html_e('Step 2:', 'wp-ffcertificate'); ?></strong> <?php esc_html_e('Use in template:', 'wp-ffcertificate'); ?> <code>{{company}}</code></li>
            <li><strong><?php esc_html_e('Step 3:', 'wp-ffcertificate'); ?></strong> <?php esc_html_e('Value gets replaced automatically in PDF', 'wp-ffcertificate'); ?></li>
        </ul>
    </div>
    
    <div class="ffc-doc-example">
        <h4><?php esc_html_e('Example:', 'wp-ffcertificate'); ?></h4>
        <p><?php esc_html_e('If you create these custom fields:', 'wp-ffcertificate'); ?></p>
        <ul>
            <li><code>company</code> → <?php esc_html_e('Use:', 'wp-ffcertificate'); ?> <code>{{company}}</code></li>
            <li><code>department</code> → <?php esc_html_e('Use:', 'wp-ffcertificate'); ?> <code>{{department}}</code></li>
            <li><code>course_hours</code> → <?php esc_html_e('Use:', 'wp-ffcertificate'); ?> <code>{{course_hours}}</code></li>
        </ul>
    </div>
</div>

<!-- 7. Features Section -->
<div class="card">
    <h3 id="features">🎉 <?php esc_html_e('7. Features', 'wp-ffcertificate'); ?></h3>
    
    <ul class="ffc-doc-list">
        <li>
            <strong><?php esc_html_e('Unique Authentication Codes:', 'wp-ffcertificate'); ?></strong><br> 
            <?php esc_html_e('Every certificate gets a unique 12-character code (e.g., A1B2-C3D4-E5F6)', 'wp-ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('QR Code Validation:', 'wp-ffcertificate'); ?></strong><br> 
            <?php esc_html_e('Scan to instantly verify certificate authenticity', 'wp-ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Magic Links:', 'wp-ffcertificate'); ?></strong><br> 
            <?php esc_html_e('Links that don\'t pass validation on the website. Shared by email and quickly verifying the certificate\'s.', 'wp-ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Reprinting certificates:', 'wp-ffcertificate'); ?></strong><br> 
            <?php esc_html_e('Previously submitted identification information (CPF/RF) does not generate new certificates.', 'wp-ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('CSV Export:', 'wp-ffcertificate'); ?></strong><br> 
            <?php esc_html_e('Generate a CSV list with the submissions already sent.', 'wp-ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Email Notifications:', 'wp-ffcertificate'); ?></strong><br> 
            <?php esc_html_e('Automatic (or not) email sent with certificate PDF attached upon submission', 'wp-ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('PDF Customization:', 'wp-ffcertificate'); ?></strong><br> 
            <?php esc_html_e('Full HTML editor to design your own certificate layout', 'wp-ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Auto-delete:', 'wp-ffcertificate'); ?></strong><br> 
            <?php esc_html_e('Ensure submissions are deleted after "X" days.', 'wp-ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Date Format:', 'wp-ffcertificate'); ?></strong><br>
            <?php esc_html_e('Format used for {{submission_date}} and {{print_date}} placeholders in PDFs and emails.', 'wp-ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Data Migrations:', 'wp-ffcertificate'); ?></strong><br> 
            <?php esc_html_e('Migration of all data from the plugin\'s old infrastructure.', 'wp-ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Form Cache:', 'wp-ffcertificate'); ?></strong><br> 
            <?php esc_html_e('The cache stores form settings to improve performance.', 'wp-ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Multi-language Support:', 'wp-ffcertificate'); ?></strong><br> 
            <?php esc_html_e('Supports Portuguese and English languages', 'wp-ffcertificate'); ?>
        </li>
    </ul>
</div>

<!-- 8. Security Features Section -->
<div class="card">
    <h3 id="security">🔒 <?php esc_html_e('8. Security Features', 'wp-ffcertificate'); ?></h3>
    
    <ul class="ffc-doc-list">
        <li>
            <strong><?php esc_html_e('Single Password:', 'wp-ffcertificate'); ?></strong><br> 
            <?php esc_html_e('The form will have a global password for submission.', 'wp-ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Allowlist/Denylist:', 'wp-ffcertificate'); ?></strong><br>
            <?php esc_html_e('Ensure that the listed IDs are allowed or blocked from retrieving certificates.', 'wp-ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Ticket (Unique Codes):', 'wp-ffcertificate'); ?></strong><br>
            <?php esc_html_e('Require users to have a single-use ticket to generate the certificate (it is consumed after use).', 'wp-ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Rate Limiting:', 'wp-ffcertificate'); ?></strong><br>
            <?php esc_html_e('Prevents abuse with configurable submission limits', 'wp-ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Data Encryption:', 'wp-ffcertificate'); ?></strong><br>
            <?php esc_html_e('Encryption for sensitive data (LGPD compliant)', 'wp-ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Honeypot Fields:', 'wp-ffcertificate'); ?></strong><br>
            <?php esc_html_e('Invisible spam protection', 'wp-ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Math CAPTCHA:', 'wp-ffcertificate'); ?></strong><br>
            <?php esc_html_e('Basic humanity verification', 'wp-ffcertificate'); ?>
        </li>
    </ul>
</div>

<!-- 9. Complete Examples Section -->
<div class="card">
    <h3 id="examples">📝 <?php esc_html_e('9. Complete Template Examples', 'wp-ffcertificate'); ?></h3>

    <div class="ffc-doc-example">
        <h4><?php esc_html_e('Example 1: Simple Certificate', 'wp-ffcertificate'); ?></h4>
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
        <h4><?php esc_html_e('Example 2: Certificate with Header & Footer', 'wp-ffcertificate'); ?></h4>
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
    <h3 id="troubleshooting">🔧 <?php esc_html_e('10. Troubleshooting', 'wp-ffcertificate'); ?></h3>

    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Problem', 'wp-ffcertificate'); ?></th>
                <th><?php esc_html_e('Solution', 'wp-ffcertificate'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php esc_html_e('Variable not replaced', 'wp-ffcertificate'); ?> <code>{{name}}</code></td>
                <td>
                    • <?php esc_html_e('Check spelling matches exactly', 'wp-ffcertificate'); ?><br>
                    • <?php esc_html_e('Ensure field exists in form', 'wp-ffcertificate'); ?><br>
                    • <?php esc_html_e('Use lowercase for custom fields', 'wp-ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e('Image not showing in PDF', 'wp-ffcertificate'); ?></td>
                <td>
                    • <?php esc_html_e('Use absolute URLs (https://...)', 'wp-ffcertificate'); ?><br>
                    • <?php esc_html_e('Check image is publicly accessible', 'wp-ffcertificate'); ?><br>
                    • <?php esc_html_e('Add width/height attributes', 'wp-ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e('QR Code too large/small', 'wp-ffcertificate'); ?></td>
                <td>
                    • <?php esc_html_e('Use:', 'wp-ffcertificate'); ?> <code>{{qr_code:size=150}}</code><br>
                    • <?php esc_html_e('Recommended: 100-200px for certificates', 'wp-ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e('Formatting not showing (bold, italic)', 'wp-ffcertificate'); ?></td>
                <td>
                    • <?php esc_html_e('Use HTML tags:', 'wp-ffcertificate'); ?> <code>&lt;strong&gt;</code> <code>&lt;em&gt;</code><br>
                    • <?php esc_html_e('Or inline style:', 'wp-ffcertificate'); ?> <code>style="font-weight: bold;"</code>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e('Layout broken in PDF', 'wp-ffcertificate'); ?></td>
                <td>
                    • <?php esc_html_e('Use tables for complex layouts', 'wp-ffcertificate'); ?><br>
                    • <?php esc_html_e('Always use inline styles', 'wp-ffcertificate'); ?><br>
                    • <?php esc_html_e('Test with simple content first', 'wp-ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e('Settings not saving between tabs', 'wp-ffcertificate'); ?></td>
                <td>
                    • <?php esc_html_e('Update to latest version', 'wp-ffcertificate'); ?><br>
                    • <?php esc_html_e('Clear WordPress cache', 'wp-ffcertificate'); ?><br>
                    • <?php esc_html_e('Clear browser cache (Ctrl+F5)', 'wp-ffcertificate'); ?>
                </td>
            </tr>
        </tbody>
    </table>

    <div class="ffc-alert ffc-alert-info ffc-mt-20">
        <p>
            <strong>ℹ️ <?php esc_html_e('Need More Help?', 'wp-ffcertificate'); ?></strong><br>
            <?php esc_html_e('For additional support, check the plugin repository documentation or contact support.', 'wp-ffcertificate'); ?>
        </p>
    </div>
</div>

</div><!-- .ffc-settings-wrap -->