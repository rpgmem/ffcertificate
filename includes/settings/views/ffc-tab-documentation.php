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
    <h2 class="ffc-icon-doc"><?php esc_html_e('Complete Plugin Documentation', 'ffcertificate'); ?></h2>
    <p><?php esc_html_e('This plugin allows you to create certificate issuance forms, generate PDFs automatically, and verify authenticity with QR codes.', 'ffcertificate'); ?></p>
    
    <!-- Table of Contents -->
    <div class="ffc-doc-toc">
        <h3><?php esc_html_e('Quick Navigation', 'ffcertificate'); ?></h3>
        <ul class="ffc-doc-toc-list">
            <li><a href="#shortcodes" class="ffc-icon-pin"><?php esc_html_e('1. Shortcodes', 'ffcertificate'); ?></a></li>
            <li><a href="#variables" class="ffc-icon-tag"><?php esc_html_e('2. Template Variables', 'ffcertificate'); ?></a></li>
            <li><a href="#qr-code" class="ffc-icon-phone"><?php esc_html_e('3. QR Code Options', 'ffcertificate'); ?></a></li>
            <li><a href="#validation-url" class="ffc-icon-link"><?php esc_html_e('4. Validation URL', 'ffcertificate'); ?></a></li>
            <li><a href="#html-styling" class="ffc-icon-palette"><?php esc_html_e('5. HTML & Styling', 'ffcertificate'); ?></a></li>
            <li><a href="#custom-fields" class="ffc-icon-edit"><?php esc_html_e('6. Custom Fields', 'ffcertificate'); ?></a></li>
            <li><a href="#features" class="ffc-icon-celebrate"><?php esc_html_e('7. Features', 'ffcertificate'); ?></a></li>
            <li><a href="#security" class="ffc-icon-lock"><?php esc_html_e('8. Security Features', 'ffcertificate'); ?></a></li>
            <li><a href="#examples" class="ffc-icon-note"><?php esc_html_e('9. Complete Examples', 'ffcertificate'); ?></a></li>
            <li><a href="#troubleshooting" class="ffc-icon-wrench"><?php esc_html_e('10. Troubleshooting', 'ffcertificate'); ?></a></li>
        </ul>
    </div>
</div>

<!-- 1. Shortcodes Section -->
<div class="card">
    <h3 id="shortcodes" class="ffc-icon-pin"><?php esc_html_e('1. Shortcodes', 'ffcertificate'); ?></h3>
    
    <table class="widefat striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Shortcode', 'ffcertificate'); ?></th>
                <th scope="col"><?php esc_html_e('Description', 'ffcertificate'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>[ffc_form id="123"]</code></td>
                <td>
                    <?php esc_html_e('Displays the certificate issuance form.', 'ffcertificate'); ?><br>
                    <strong><?php esc_html_e('Usage:', 'ffcertificate'); ?></strong> <?php esc_html_e('Replace "123" with your Form ID from the "All Forms" list.', 'ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><code>[ffc_verification]</code></td>
                <td>
                    <?php esc_html_e('Displays the public verification page.', 'ffcertificate'); ?><br>
                    <strong><?php esc_html_e('Usage:', 'ffcertificate'); ?></strong> <?php esc_html_e('Users can validate certificates by entering the authentication code.', 'ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><code>[user_dashboard_personal]</code></td>
                <td>
                    <?php esc_html_e('Displays dashboard page.', 'ffcertificate'); ?><br>
                    <strong><?php esc_html_e('Usage:', 'ffcertificate'); ?></strong> <?php esc_html_e('Logged-in users will be able to view all certificates generated for their own CPF/RF (Brazilian tax identification number).', 'ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><code>[ffc_self_scheduling id="456"]</code></td>
                <td>
                    <?php esc_html_e('Displays a personal calendar with appointment booking.', 'ffcertificate'); ?><br>
                    <strong><?php esc_html_e('Usage:', 'ffcertificate'); ?></strong> <?php esc_html_e('Replace "456" with your Calendar ID. Users can view available slots and book appointments.', 'ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><code>[ffc_audience]</code></td>
                <td>
                    <?php esc_html_e('Displays the audience scheduling calendar for group bookings.', 'ffcertificate'); ?><br>
                    <strong><?php esc_html_e('Usage:', 'ffcertificate'); ?></strong> <?php esc_html_e('Administrators can schedule activities for audiences (groups) in configured environments.', 'ffcertificate'); ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<!-- 2. Template Variables Section -->
<div class="card">
    <h3 id="variables" class="ffc-icon-tag"><?php esc_html_e('2. PDF Template Variables', 'ffcertificate'); ?></h3>
    <p><?php esc_html_e('Use these variables in your PDF template (HTML editor). They will be automatically replaced with user data:', 'ffcertificate'); ?></p>
    
    <table class="widefat striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Variable', 'ffcertificate'); ?></th>
                <th scope="col"><?php esc_html_e('Description', 'ffcertificate'); ?></th>
                <th scope="col"><?php esc_html_e('Example Output', 'ffcertificate'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>{{name}}</code><br><code>{{nome}}</code></td>
                <td><?php esc_html_e('Full name of the participant', 'ffcertificate'); ?></td>
                <td><em>John Doe</em></td>
            </tr>
            <tr>
                <td><code>{{cpf_rf}}</code></td>
                <td><?php esc_html_e('ID/CPF/RF entered by user', 'ffcertificate'); ?></td>
                <td><em>123.456.789-00</em></td>
            </tr>
            <tr>
                <td><code>{{email}}</code></td>
                <td><?php esc_html_e('User email address', 'ffcertificate'); ?></td>
                <td><em>john_doe@example.com</em></td>
            </tr>
            <tr>
                <td><code>{{auth_code}}</code></td>
                <td><?php esc_html_e('Unique authentication code for validation', 'ffcertificate'); ?></td>
                <td><em>A1B2-C3D4-E5F6</em></td>
            </tr>
            <tr>
                <td><code>{{form_title}}</code></td>
                <td><?php esc_html_e('Title of the form/event', 'ffcertificate'); ?></td>
                <td><em>Workshop 2025</em></td>
            </tr>
            <tr>
                <td><code>{{submission_date}}</code></td>
                <td><?php esc_html_e('Date when submission was created (from database)', 'ffcertificate'); ?></td>
                <td><em>29/12/2025</em></td>
            </tr>
            <tr>
                <td><code>{{print_date}}</code></td>
                <td><?php esc_html_e('Current date/time when PDF is being generated', 'ffcertificate'); ?></td>
                <td><em>20/01/2026</em></td>
            </tr>
            <tr>
                <td><code>{{submission_id}}</code></td>
                <td><?php esc_html_e('Numeric submission ID', 'ffcertificate'); ?></td>
                <td><em>123</em></td>
            </tr>
            <tr>
                <td><code>{{main_address}}</code></td>
                <td><?php esc_html_e('Institutional address from Settings > General', 'ffcertificate'); ?></td>
                <td><em>123 Main St, City</em></td>
            </tr>
            <tr>
                <td><code>{{site_name}}</code></td>
                <td><?php esc_html_e('WordPress site name', 'ffcertificate'); ?></td>
                <td><em>My Organization</em></td>
            </tr>
            <tr>
                <td><code>{{program}}</code></td>
                <td><?php esc_html_e('Program/Course name (if custom field exists)', 'ffcertificate'); ?></td>
                <td><em>Advanced Training</em></td>
            </tr>
            <tr>
                <td><code>{{qr_code}}</code></td>
                <td><?php esc_html_e('QR Code image (see section 3 for options)', 'ffcertificate'); ?></td>
                <td><em>QRCode Image to Magic Link</em></td>
            </tr>
            <tr>
                <td><code>{{validation_url}}</code></td>
                <td><?php esc_html_e('Link to page with certificate validation', 'ffcertificate'); ?></td>
                <td><em>Link to page with certificate validation</em></td>
            </tr>
            <tr>
                <td><code>{{custom_field}}</code></td>
                <td><?php esc_html_e('Any custom field name you created', 'ffcertificate'); ?></td>
                <td><em>[Your Data]</em></td>
            </tr>
        </tbody>
    </table>
</div>

<!-- 3. QR Code Options Section -->
<div class="card">
    <h3 id="qr-code" class="ffc-icon-phone"><?php esc_html_e('3. QR Code Options & Attributes', 'ffcertificate'); ?></h3>
    <p><?php esc_html_e('The QR code can be customized with various attributes:', 'ffcertificate'); ?></p>
    
    <table class="widefat striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Usage', 'ffcertificate'); ?></th>
                <th scope="col"><?php esc_html_e('Description', 'ffcertificate'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>{{qr_code}}</code></td>
                <td>
                    <?php esc_html_e('Default QR code (uses settings from QR Code tab)', 'ffcertificate'); ?><br>
                    <strong><?php esc_html_e('Default size:', 'ffcertificate'); ?></strong> 200x200px
                </td>
            </tr>
            <tr>
                <td><code>{{qr_code:size=150}}</code></td>
                <td>
                    <?php esc_html_e('Custom size (150x150 pixels)', 'ffcertificate'); ?><br>
                    <strong><?php esc_html_e('Range:', 'ffcertificate'); ?></strong> <?php esc_html_e('100px at 500px', 'ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><code>{{qr_code:margin=0}}</code></td>
                <td>
                    <?php esc_html_e('No white margin around QR code', 'ffcertificate'); ?><br>
                    <strong><?php esc_html_e('Range:', 'ffcertificate'); ?></strong> 0-10 <?php esc_html_e('(default: 2)', 'ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><code>{{qr_code:error_level=H}}</code></td>
                <td>
                    <?php esc_html_e('Error correction level', 'ffcertificate'); ?><br>
                    <strong><?php esc_html_e('Options:', 'ffcertificate'); ?></strong><br>
                    • <code>L</code> = <?php esc_html_e('Low (7%)', 'ffcertificate'); ?><br>
                    • <code>M</code> = <?php esc_html_e('Medium (15% - recommended)', 'ffcertificate'); ?><br>
                    • <code>Q</code> = <?php esc_html_e('Quartile (25%)', 'ffcertificate'); ?><br>
                    • <code>H</code> = <?php esc_html_e('High (30%)', 'ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><code>{{qr_code:size=200:margin=1:error_level=M}}</code></td>
                <td><?php esc_html_e('Combining multiple attributes (separate with colons)', 'ffcertificate'); ?></td>
            </tr>
        </tbody>
    </table>
</div>

<!-- 4. Validation URL Section -->
<div class="card">
    <h3 id="validation-url" class="ffc-icon-link"><?php esc_html_e('4. Validation URL', 'ffcertificate'); ?></h3>
    <p><?php esc_html_e('The Validation URL can be customized with various attributes:', 'ffcertificate'); ?></p>
    
    <table class="widefat striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Usage', 'ffcertificate'); ?></th>
                <th scope="col"><?php esc_html_e('Description', 'ffcertificate'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>{{validation_url}}</code></td>
                <td>
                    <?php esc_html_e('Default: link to magic, text shows /valid', 'ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><code>{{validation_url link:X>Y}}</code></td>
                <td>
                    <code>{{validation_url link:m>v}}</code> → <?php esc_html_e('Link to magic, text /valid', 'ffcertificate'); ?><br>
                    <code>{{validation_url link:v>v}}</code> → <?php esc_html_e('Link to /valid, text /valid', 'ffcertificate'); ?><br>
                    <code>{{validation_url link:m>m}}</code> → <?php esc_html_e('Link to magic, text magic', 'ffcertificate'); ?><br>
                    <code>{{validation_url link:v>m}}</code> → <?php esc_html_e('Link to /valid, text magic', 'ffcertificate'); ?><br>
                    <code>{{validation_url link:v>"Custom Text"}}</code> → <?php esc_html_e('Link to /valid, custom text', 'ffcertificate'); ?><br>
                    <code>{{validation_url link:m>"Custom Text"}}</code> →  <?php esc_html_e('Link to magic, custom text', 'ffcertificate'); ?><br>
                    <code>{{validation_url link:m>v target:_blank}}</code> → <?php esc_html_e('With target', 'ffcertificate'); ?><br>
                    <code>{{validation_url link:m>v color:blue}}</code> → <?php esc_html_e('With color link', 'ffcertificate'); ?><br>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<!-- 5. HTML & Styling Section -->
<div class="card">
    <h3 id="html-styling" class="ffc-icon-palette"><?php esc_html_e('5. HTML & Styling', 'ffcertificate'); ?></h3>
    <p><?php esc_html_e('You can use HTML and inline CSS to style your certificate:', 'ffcertificate'); ?></p>

    <h4><?php esc_html_e('Supported HTML Tags:', 'ffcertificate'); ?></h4>
    <table class="widefat striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Tag', 'ffcertificate'); ?></th>
                <th scope="col"><?php esc_html_e('Usage', 'ffcertificate'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>&lt;strong&gt;</code> <code>&lt;b&gt;</code></td>
                <td><?php esc_html_e('Bold text:', 'ffcertificate'); ?> <code>&lt;strong&gt;{{name}}&lt;/strong&gt;</code></td>
            </tr>
            <tr>
                <td><code>&lt;em&gt;</code> <code>&lt;i&gt;</code></td>
                <td><?php esc_html_e('Italic text:', 'ffcertificate'); ?> <code>&lt;em&gt;Certificate&lt;/em&gt;</code></td>
            </tr>
            <tr>
                <td><code>&lt;u&gt;</code></td>
                <td><?php esc_html_e('Underline text:', 'ffcertificate'); ?> <code>&lt;u&gt;Important&lt;/u&gt;</code></td>
            </tr>
            <tr>
                <td><code>&lt;br&gt;</code></td>
                <td><?php esc_html_e('Line break', 'ffcertificate'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;p&gt;</code></td>
                <td><?php esc_html_e('Paragraph with spacing', 'ffcertificate'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;div&gt;</code></td>
                <td><?php esc_html_e('Container for sections', 'ffcertificate'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;table&gt;</code> <code>&lt;tr&gt;</code> <code>&lt;td&gt;</code></td>
                <td><?php esc_html_e('Tables for layout (logos, signatures)', 'ffcertificate'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;img&gt;</code></td>
                <td><?php esc_html_e('Images (logos, signatures, decorations)', 'ffcertificate'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;h1&gt;</code> <code>&lt;h2&gt;</code> <code>&lt;h3&gt;</code></td>
                <td><?php esc_html_e('Headers/titles', 'ffcertificate'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;ul&gt;</code> <code>&lt;ol&gt;</code> <code>&lt;li&gt;</code></td>
                <td><?php esc_html_e('Lists (bullet or numbered)', 'ffcertificate'); ?></td>
            </tr>
        </tbody>
    </table>

    <h4><?php esc_html_e('Image Attributes:', 'ffcertificate'); ?></h4>
    <table class="widefat striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Example', 'ffcertificate'); ?></th>
                <th scope="col"><?php esc_html_e('Result', 'ffcertificate'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>&lt;img src="logo.png" width="200"&gt;</code></td>
                <td><?php esc_html_e('Logo with fixed width', 'ffcertificate'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;img src="signature.png" height="80"&gt;</code></td>
                <td><?php esc_html_e('Signature with fixed height, proportional width', 'ffcertificate'); ?></td>
            </tr>
            <tr>
                <td><code>&lt;img src="photo.png" width="150" height="150"&gt;</code></td>
                <td><?php esc_html_e('Photo cropped to fit dimensions', 'ffcertificate'); ?></td>
            </tr>
        </tbody>
    </table>

    <h4><?php esc_html_e('Common Inline Styles:', 'ffcertificate'); ?></h4>
    <table class="widefat striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Style', 'ffcertificate'); ?></th>
                <th scope="col"><?php esc_html_e('Example', 'ffcertificate'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php esc_html_e('Font size', 'ffcertificate'); ?></td>
                <td><code>style="font-size: 14pt;"</code></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Text color', 'ffcertificate'); ?></td>
                <td><code>style="color: #2271b1;"</code></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Text alignment', 'ffcertificate'); ?></td>
                <td><code>style="text-align: center;"</code></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Background color', 'ffcertificate'); ?></td>
                <td><code>style="background-color: #f0f0f0;"</code></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Margins/padding', 'ffcertificate'); ?></td>
                <td><code>style="margin: 20px; padding: 15px;"</code></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Font family', 'ffcertificate'); ?></td>
                <td><code>style="font-family: Arial, sans-serif;"</code></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Border', 'ffcertificate'); ?></td>
                <td><code>style="border: 2px solid #000;"</code></td>
            </tr>
        </tbody>
    </table>
</div>

<!-- 6. Custom Fields Section -->
<div class="card">
    <h3 id="custom-fields" class="ffc-icon-edit"><?php esc_html_e('6. Custom Fields', 'ffcertificate'); ?></h3>
    
    <p><?php esc_html_e('Any custom field you create in Form Builder automatically becomes a template variable:', 'ffcertificate'); ?></p>
    
    <div class="ffc-doc-example">
        <h4><?php esc_html_e('How It Works:', 'ffcertificate'); ?></h4>
        <ul>
            <li><strong><?php esc_html_e('Step 1:', 'ffcertificate'); ?></strong> <?php esc_html_e('Create a field in Form Builder (e.g., field name:', 'ffcertificate'); ?> "company"</li>
            <li><strong><?php esc_html_e('Step 2:', 'ffcertificate'); ?></strong> <?php esc_html_e('Use in template:', 'ffcertificate'); ?> <code>{{company}}</code></li>
            <li><strong><?php esc_html_e('Step 3:', 'ffcertificate'); ?></strong> <?php esc_html_e('Value gets replaced automatically in PDF', 'ffcertificate'); ?></li>
        </ul>
    </div>
    
    <div class="ffc-doc-example">
        <h4><?php esc_html_e('Example:', 'ffcertificate'); ?></h4>
        <p><?php esc_html_e('If you create these custom fields:', 'ffcertificate'); ?></p>
        <ul>
            <li><code>company</code> → <?php esc_html_e('Use:', 'ffcertificate'); ?> <code>{{company}}</code></li>
            <li><code>department</code> → <?php esc_html_e('Use:', 'ffcertificate'); ?> <code>{{department}}</code></li>
            <li><code>course_hours</code> → <?php esc_html_e('Use:', 'ffcertificate'); ?> <code>{{course_hours}}</code></li>
        </ul>
    </div>
</div>

<!-- 7. Features Section -->
<div class="card">
    <h3 id="features" class="ffc-icon-celebrate"><?php esc_html_e('7. Features', 'ffcertificate'); ?></h3>
    
    <ul class="ffc-doc-list">
        <li>
            <strong><?php esc_html_e('Unique Authentication Codes:', 'ffcertificate'); ?></strong><br> 
            <?php esc_html_e('Every certificate gets a unique 12-character code (e.g., A1B2-C3D4-E5F6)', 'ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('QR Code Validation:', 'ffcertificate'); ?></strong><br> 
            <?php esc_html_e('Scan to instantly verify certificate authenticity', 'ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Magic Links:', 'ffcertificate'); ?></strong><br> 
            <?php esc_html_e('Links that don\'t pass validation on the website. Shared by email and quickly verifying the certificate\'s.', 'ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Reprinting certificates:', 'ffcertificate'); ?></strong><br> 
            <?php esc_html_e('Previously submitted identification information (CPF/RF) does not generate new certificates.', 'ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('CSV Export:', 'ffcertificate'); ?></strong><br> 
            <?php esc_html_e('Generate a CSV list with the submissions already sent.', 'ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Email Notifications:', 'ffcertificate'); ?></strong><br> 
            <?php esc_html_e('Automatic (or not) email sent with certificate PDF attached upon submission', 'ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('PDF Customization:', 'ffcertificate'); ?></strong><br> 
            <?php esc_html_e('Full HTML editor to design your own certificate layout', 'ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Auto-delete:', 'ffcertificate'); ?></strong><br> 
            <?php esc_html_e('Ensure submissions are deleted after "X" days.', 'ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Date Format:', 'ffcertificate'); ?></strong><br>
            <?php esc_html_e('Format used for {{submission_date}} and {{print_date}} placeholders in PDFs and emails.', 'ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Data Migrations:', 'ffcertificate'); ?></strong><br> 
            <?php esc_html_e('Migration of all data from the plugin\'s old infrastructure.', 'ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Form Cache:', 'ffcertificate'); ?></strong><br> 
            <?php esc_html_e('The cache stores form settings to improve performance.', 'ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Multi-language Support:', 'ffcertificate'); ?></strong><br> 
            <?php esc_html_e('Supports Portuguese and English languages', 'ffcertificate'); ?>
        </li>
    </ul>
</div>

<!-- 8. Security Features Section -->
<div class="card">
    <h3 id="security" class="ffc-icon-lock"><?php esc_html_e('8. Security Features', 'ffcertificate'); ?></h3>
    
    <ul class="ffc-doc-list">
        <li>
            <strong><?php esc_html_e('Single Password:', 'ffcertificate'); ?></strong><br> 
            <?php esc_html_e('The form will have a global password for submission.', 'ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Allowlist/Denylist:', 'ffcertificate'); ?></strong><br>
            <?php esc_html_e('Ensure that the listed IDs are allowed or blocked from retrieving certificates.', 'ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Ticket (Unique Codes):', 'ffcertificate'); ?></strong><br>
            <?php esc_html_e('Require users to have a single-use ticket to generate the certificate (it is consumed after use).', 'ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Rate Limiting:', 'ffcertificate'); ?></strong><br>
            <?php esc_html_e('Prevents abuse with configurable submission limits', 'ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Data Encryption:', 'ffcertificate'); ?></strong><br>
            <?php esc_html_e('Encryption for sensitive data (LGPD compliant)', 'ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Honeypot Fields:', 'ffcertificate'); ?></strong><br>
            <?php esc_html_e('Invisible spam protection', 'ffcertificate'); ?>
        </li>
        <li>
            <strong><?php esc_html_e('Math CAPTCHA:', 'ffcertificate'); ?></strong><br>
            <?php esc_html_e('Basic humanity verification', 'ffcertificate'); ?>
        </li>
    </ul>
</div>

<!-- 9. Complete Examples Section -->
<div class="card">
    <h3 id="examples" class="ffc-icon-note"><?php esc_html_e('9. Complete Template Examples', 'ffcertificate'); ?></h3>

    <div class="ffc-doc-example">
        <h4><?php esc_html_e('Example 1: Simple Certificate', 'ffcertificate'); ?></h4>
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
        <h4><?php esc_html_e('Example 2: Certificate with Header & Footer', 'ffcertificate'); ?></h4>
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
    <h3 id="troubleshooting" class="ffc-icon-wrench"><?php esc_html_e('10. Troubleshooting', 'ffcertificate'); ?></h3>

    <table class="widefat striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Problem', 'ffcertificate'); ?></th>
                <th scope="col"><?php esc_html_e('Solution', 'ffcertificate'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php esc_html_e('Variable not replaced', 'ffcertificate'); ?> <code>{{name}}</code></td>
                <td>
                    • <?php esc_html_e('Check spelling matches exactly', 'ffcertificate'); ?><br>
                    • <?php esc_html_e('Ensure field exists in form', 'ffcertificate'); ?><br>
                    • <?php esc_html_e('Use lowercase for custom fields', 'ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e('Image not showing in PDF', 'ffcertificate'); ?></td>
                <td>
                    • <?php esc_html_e('Use absolute URLs (https://...)', 'ffcertificate'); ?><br>
                    • <?php esc_html_e('Check image is publicly accessible', 'ffcertificate'); ?><br>
                    • <?php esc_html_e('Add width/height attributes', 'ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e('QR Code too large/small', 'ffcertificate'); ?></td>
                <td>
                    • <?php esc_html_e('Use:', 'ffcertificate'); ?> <code>{{qr_code:size=150}}</code><br>
                    • <?php esc_html_e('Recommended: 100-200px for certificates', 'ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e('Formatting not showing (bold, italic)', 'ffcertificate'); ?></td>
                <td>
                    • <?php esc_html_e('Use HTML tags:', 'ffcertificate'); ?> <code>&lt;strong&gt;</code> <code>&lt;em&gt;</code><br>
                    • <?php esc_html_e('Or inline style:', 'ffcertificate'); ?> <code>style="font-weight: bold;"</code>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e('Layout broken in PDF', 'ffcertificate'); ?></td>
                <td>
                    • <?php esc_html_e('Use tables for complex layouts', 'ffcertificate'); ?><br>
                    • <?php esc_html_e('Always use inline styles', 'ffcertificate'); ?><br>
                    • <?php esc_html_e('Test with simple content first', 'ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e('Settings not saving between tabs', 'ffcertificate'); ?></td>
                <td>
                    • <?php esc_html_e('Update to latest version', 'ffcertificate'); ?><br>
                    • <?php esc_html_e('Clear WordPress cache', 'ffcertificate'); ?><br>
                    • <?php esc_html_e('Clear browser cache (Ctrl+F5)', 'ffcertificate'); ?>
                </td>
            </tr>
        </tbody>
    </table>

    <div class="ffc-alert ffc-alert-info ffc-mt-20">
        <p>
            <strong class="ffc-icon-info"><?php esc_html_e('Need More Help?', 'ffcertificate'); ?></strong><br>
            <?php esc_html_e('For additional support, check the plugin repository documentation or contact support.', 'ffcertificate'); ?>
        </p>
    </div>
</div>

</div><!-- .ffc-settings-wrap -->