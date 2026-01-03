<div class="card ffc-settings-card">
            <h2>📚 <?php esc_html_e( 'Complete Plugin Documentation', 'ffc' ); ?></h2>
            <p><?php esc_html_e( 'This plugin allows you to create certificate issuance forms, generate PDFs automatically, and verify authenticity with QR codes.', 'ffc' ); ?></p>
        
            <!-- TABLE OF CONTENTS -->
            <div style="background: #f0f0f1; padding: 15px; border-left: 4px solid #2271b1; margin: 20px 0;">
                <h3 style="margin-top: 0;"><?php esc_html_e( 'Quick Navigation', 'ffc' ); ?></h3>
                <ul style="column-count: 2; column-gap: 30px;">
                    <li><a href="#shortcodes"><?php esc_html_e( '1. Shortcodes', 'ffc' ); ?></a></li>
                    <li><a href="#variables"><?php esc_html_e( '2. Template Variables', 'ffc' ); ?></a></li>
                    <li><a href="#qr-code"><?php esc_html_e( '3. QR Code Options', 'ffc' ); ?></a></li>
                    <li><a href="#html-styling"><?php esc_html_e( '4. HTML & Styling', 'ffc' ); ?></a></li>
                    <li><a href="#custom-fields"><?php esc_html_e( '5. Custom Fields', 'ffc' ); ?></a></li>
                    <li><a href="#security"><?php esc_html_e( '6. Security Features', 'ffc' ); ?></a></li>
                    <li><a href="#examples"><?php esc_html_e( '7. Complete Examples', 'ffc' ); ?></a></li>
                    <li><a href="#troubleshooting"><?php esc_html_e( '8. Troubleshooting', 'ffc' ); ?></a></li>
                </ul>
            </div>

            <hr>

            <!-- 1. SHORTCODES -->
            <h3 id="shortcodes">📌 <?php esc_html_e( '1. Shortcodes', 'ffc' ); ?></h3>
            <table class="widefat striped ffc-help-table">
                <thead>
                    <tr>
                        <th width="35%"><?php esc_html_e( 'Shortcode', 'ffc' ); ?></th>
                        <th><?php esc_html_e( 'Description', 'ffc' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>[ffc_form id="123"]</code></td>
                        <td>
                            <?php esc_html_e( 'Displays the certificate issuance form.', 'ffc' ); ?><br>
                            <strong><?php esc_html_e( 'Usage:', 'ffc' ); ?></strong> <?php esc_html_e( 'Replace "123" with your Form ID from the "All Forms" list.', 'ffc' ); ?>
                        </td>
                    </tr>
                    <tr>
                        <td><code>[ffc_verification]</code></td>
                        <td>
                            <?php esc_html_e( 'Displays the public verification page.', 'ffc' ); ?><br>
                            <strong><?php esc_html_e( 'Usage:', 'ffc' ); ?></strong> <?php esc_html_e( 'Users can validate certificates by entering the authentication code.', 'ffc' ); ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <br><hr>

            <!-- 2. TEMPLATE VARIABLES -->
            <h3 id="variables">🏷️ <?php esc_html_e( '2. PDF Template Variables', 'ffc' ); ?></h3>
            <p><?php esc_html_e( 'Use these variables in your PDF template (HTML editor). They will be automatically replaced with user data:', 'ffc' ); ?></p>
            
            <table class="widefat striped ffc-help-table">
                <thead>
                    <tr>
                        <th width="30%"><?php esc_html_e( 'Variable', 'ffc' ); ?></th>
                        <th width="45%"><?php esc_html_e( 'Description', 'ffc' ); ?></th>
                        <th width="25%"><?php esc_html_e( 'Example Output', 'ffc' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>{{name}}</code><br><code>{{nome}}</code></td>
                        <td><?php esc_html_e( 'Full name of the participant', 'ffc' ); ?></td>
                        <td><em>John Doe</em></td>
                    </tr>
                    <tr>
                        <td><code>{{cpf_rf}}</code></td>
                        <td><?php esc_html_e( 'ID/CPF/RF entered by user', 'ffc' ); ?></td>
                        <td><em>123.456.789-00</em></td>
                    </tr>
                    <tr>
                        <td><code>{{email}}</code></td>
                        <td><?php esc_html_e( 'User email address', 'ffc' ); ?></td>
                        <td><em>john_doe@example.com</em></td>
                    </tr>
                    <tr>
                        <td><code>{{auth_code}}</code></td>
                        <td><?php esc_html_e( 'Unique authentication code for validation', 'ffc' ); ?></td>
                        <td><em>A1B2-C3D4-E5F6</em></td>
                    </tr>
                    <tr>
                        <td><code>{{form_title}}</code></td>
                        <td><?php esc_html_e( 'Title of the form/event', 'ffc' ); ?></td>
                        <td><em>Workshop 2025</em></td>
                    </tr>
                    <tr>
                        <td><code>{{submission_date}}</code><br><code>{{current_date}}</code></td>
                        <td><?php esc_html_e( 'Date certificate was issued', 'ffc' ); ?></td>
                        <td><em>29/12/2025</em></td>
                    </tr>
                    <tr>
                        <td><code>{{program}}</code></td>
                        <td><?php esc_html_e( 'Program/Course name (if custom field exists)', 'ffc' ); ?></td>
                        <td><em>Advanced Training</em></td>
                    </tr>
                    <tr>
                        <td><code>{{qr_code}}</code></td>
                        <td><?php esc_html_e( 'QR Code image (see section 3 for options)', 'ffc' ); ?></td>
                        <td><em>QRCode Image to Magic Lik</em></td>
                    </tr>
                    <tr>
                        <td><code>{{validation_url}}</code></td>
                        <td><?php esc_html_e( 'Link to page with certificate validation', 'ffc' ); ?></td>
                        <td><em>Link to page with certificate validation</em></td>
                    </tr>
                    <tr>
                        <td><code>{{custom_field}}</code></td>
                        <td><?php esc_html_e( 'Any custom field name you created', 'ffc' ); ?></td>
                        <td><em>[Your Data]</em></td>
                    </tr>
                </tbody>
            </table>

            <br><hr>

            <!-- 3. QR CODE OPTIONS -->
            <h3 id="qr-code">📱 <?php esc_html_e( '3. QR Code Options & Attributes', 'ffc' ); ?></h3>
            <p><?php esc_html_e( 'The QR code can be customized with various attributes:', 'ffc' ); ?></p>
            
            <table class="widefat striped ffc-help-table">
                <thead>
                    <tr>
                        <th width="40%"><?php esc_html_e( 'Usage', 'ffc' ); ?></th>
                        <th><?php esc_html_e( 'Description', 'ffc' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>{{qr_code}}</code></td>
                        <td>
                            <?php esc_html_e( 'Default QR code (uses settings from QR Code tab)', 'ffc' ); ?><br>
                            <strong><?php esc_html_e( 'Default size:', 'ffc' ); ?></strong> 200x200px
                        </td>
                    </tr>
                    <tr>
                        <td><code>{{qr_code:size=150}}</code></td>
                        <td>
                            <?php esc_html_e( 'Custom size (150x150 pixels)', 'ffc' ); ?><br>
                            <strong><?php esc_html_e( 'Range:', 'ffc' ); ?></strong> 100-500px
                        </td>
                    </tr>
                    <tr>
                        <td><code>{{qr_code:margin=0}}</code></td>
                        <td>
                            <?php esc_html_e( 'No white margin around QR code', 'ffc' ); ?><br>
                            <strong><?php esc_html_e( 'Range:', 'ffc' ); ?></strong> 0-10 (default: 2)
                        </td>
                    </tr>
                    <tr>
                        <td><code>{{qr_code:error_level=H}}</code></td>
                        <td>
                            <?php esc_html_e( 'Error correction level', 'ffc' ); ?><br>
                            <strong><?php esc_html_e( 'Options:', 'ffc' ); ?></strong><br>
                            • <code>L</code> = Low (7%)<br>
                            • <code>M</code> = Medium (15%) - default<br>
                            • <code>Q</code> = Quartile (25%)<br>
                            • <code>H</code> = High (30%)
                        </td>
                    </tr>
                    <tr>
                        <td><code>{{qr_code:size=150:margin=0}}</code></td>
                        <td>
                            <?php esc_html_e( 'Multiple attributes combined', 'ffc' ); ?><br>
                            150x150px with no margin
                        </td>
                    </tr>
                    <tr>
                        <td><code>{{qr_code:size=200:margin=1:error_level=H}}</code></td>
                        <td>
                            <?php esc_html_e( 'Full customization:', 'ffc' ); ?><br>
                            200x200px, 1px margin, high error correction
                        </td>
                    </tr>
                </tbody>
            </table>

            <div style="background: #fff8dc; padding: 12px; border-left: 4px solid #ffb900; margin: 15px 0;">
                <strong>💡 <?php esc_html_e( 'Tip:', 'ffc' ); ?></strong> 
                <?php esc_html_e( 'Higher error correction (H) allows the QR code to work even if partially damaged, but makes it more complex.', 'ffc' ); ?>
            </div>

            <br><hr>

            <!-- 4. HTML & STYLING -->
            <h3 id="html-styling">🎨 <?php esc_html_e( '4. HTML & Styling in Templates', 'ffc' ); ?></h3>
            <p><?php esc_html_e( 'You can use HTML and inline CSS to style your certificate:', 'ffc' ); ?></p>

            <h4><?php esc_html_e( 'Supported HTML Tags:', 'ffc' ); ?></h4>
            <table class="widefat striped ffc-help-table">
                <thead>
                    <tr>
                        <th width="25%"><?php esc_html_e( 'Tag', 'ffc' ); ?></th>
                        <th><?php esc_html_e( 'Usage', 'ffc' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>&lt;strong&gt;</code> <code>&lt;b&gt;</code></td>
                        <td><?php esc_html_e( 'Bold text:', 'ffc' ); ?> <code>&lt;strong&gt;{{name}}&lt;/strong&gt;</code></td>
                    </tr>
                    <tr>
                        <td><code>&lt;em&gt;</code> <code>&lt;i&gt;</code></td>
                        <td><?php esc_html_e( 'Italic text:', 'ffc' ); ?> <code>&lt;em&gt;Certificate&lt;/em&gt;</code></td>
                    </tr>
                    <tr>
                        <td><code>&lt;u&gt;</code></td>
                        <td><?php esc_html_e( 'Underline text:', 'ffc' ); ?> <code>&lt;u&gt;Important&lt;/u&gt;</code></td>
                    </tr>
                    <tr>
                        <td><code>&lt;br&gt;</code></td>
                        <td><?php esc_html_e( 'Line break', 'ffc' ); ?></td>
                    </tr>
                    <tr>
                        <td><code>&lt;p&gt;</code></td>
                        <td><?php esc_html_e( 'Paragraph with spacing', 'ffc' ); ?></td>
                    </tr>
                    <tr>
                        <td><code>&lt;div&gt;</code></td>
                        <td><?php esc_html_e( 'Container for sections', 'ffc' ); ?></td>
                    </tr>
                    <tr>
                        <td><code>&lt;table&gt;</code> <code>&lt;tr&gt;</code> <code>&lt;td&gt;</code></td>
                        <td><?php esc_html_e( 'Tables for layout (logos, signatures)', 'ffc' ); ?></td>
                    </tr>
                    <tr>
                        <td><code>&lt;img&gt;</code></td>
                        <td><?php esc_html_e( 'Images (logos, signatures, decorations)', 'ffc' ); ?></td>
                    </tr>
                    <tr>
                        <td><code>&lt;h1&gt;</code> <code>&lt;h2&gt;</code> <code>&lt;h3&gt;</code></td>
                        <td><?php esc_html_e( 'Headers/titles', 'ffc' ); ?></td>
                    </tr>
                    <tr>
                        <td><code>&lt;ul&gt;</code> <code>&lt;ol&gt;</code> <code>&lt;li&gt;</code></td>
                        <td><?php esc_html_e( 'Lists (bullet or numbered)', 'ffc' ); ?></td>
                    </tr>
                </tbody>
            </table>

            <h4 style="margin-top: 25px;"><?php esc_html_e( 'Image Attributes:', 'ffc' ); ?></h4>
            <table class="widefat striped ffc-help-table">
                <thead>
                    <tr>
                        <th width="50%"><?php esc_html_e( 'Example', 'ffc' ); ?></th>
                        <th><?php esc_html_e( 'Result', 'ffc' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>&lt;img src="logo.png" style="width: 140px;"&gt;</code></td>
                        <td><?php esc_html_e( 'Logo with fixed width', 'ffc' ); ?></td>
                    </tr>
                    <tr>
                        <td><code>&lt;img src="signature.png" style="height: 110px; width: auto;"&gt;</code></td>
                        <td><?php esc_html_e( 'Signature with fixed height, proportional width', 'ffc' ); ?></td>
                    </tr>
                    <tr>
                        <td><code>&lt;img src="photo.png" style="width: 300px; height: 200px; object-fit: cover;"&gt;</code></td>
                        <td><?php esc_html_e( 'Photo cropped to fit dimensions', 'ffc' ); ?></td>
                    </tr>
                </tbody>
            </table>

            <h4 style="margin-top: 25px;"><?php esc_html_e( 'Common Inline Styles:', 'ffc' ); ?></h4>
            <table class="widefat striped ffc-help-table">
                <thead>
                    <tr>
                        <th width="35%"><?php esc_html_e( 'Style', 'ffc' ); ?></th>
                        <th><?php esc_html_e( 'Example', 'ffc' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php esc_html_e( 'Font size', 'ffc' ); ?></td>
                        <td><code>style="font-size: 14pt;"</code></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Font weight', 'ffc' ); ?></td>
                        <td><code>style="font-weight: bold;"</code></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Text color', 'ffc' ); ?></td>
                        <td><code>style="color: #333;"</code></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Background color', 'ffc' ); ?></td>
                        <td><code>style="background: #fff;"</code></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Text alignment', 'ffc' ); ?></td>
                        <td><code>style="text-align: center;"</code></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Spacing', 'ffc' ); ?></td>
                        <td><code>style="padding: 30px; margin-bottom: 20px;"</code></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Line height', 'ffc' ); ?></td>
                        <td><code>style="line-height: 1.6;"</code></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Borders', 'ffc' ); ?></td>
                        <td><code>style="border: 1px solid #000;"</code></td>
                    </tr>
                </tbody>
            </table>

            <div style="background: #d4edda; padding: 12px; border-left: 4px solid #28a745; margin: 15px 0;">
                <strong>✅ <?php esc_html_e( 'Best Practice:', 'ffc' ); ?></strong> 
                <?php esc_html_e( 'Always use inline styles (style="...") instead of CSS classes, as external stylesheets are not included in PDF generation.', 'ffc' ); ?>
            </div>

            <br><hr>

            <!-- 5. CUSTOM FIELDS -->
            <h3 id="custom-fields">⚙️ <?php esc_html_e( '5. Custom Fields', 'ffc' ); ?></h3>
            <p><?php esc_html_e( 'You can add custom fields to your form and reference them in templates:', 'ffc' ); ?></p>

            <ol>
                <li><?php esc_html_e( 'Add custom field to form (e.g., field name: "program")', 'ffc' ); ?></li>
                <li><?php esc_html_e( 'Use in template:', 'ffc' ); ?> <code>{{program}}</code></li>
                <li><?php esc_html_e( 'The field value will be replaced automatically', 'ffc' ); ?></li>
            </ol>

            <h4><?php esc_html_e( 'Common Custom Fields:', 'ffc' ); ?></h4>
            <ul>
                <li><code>{{program}}</code> - <?php esc_html_e( 'Program/Course name', 'ffc' ); ?></li>
                <li><code>{{instructor}}</code> - <?php esc_html_e( 'Instructor/Teacher name', 'ffc' ); ?></li>
                <li><code>{{duration}}</code> - <?php esc_html_e( 'Course duration (e.g., "40 hours")', 'ffc' ); ?></li>
                <li><code>{{date}}</code> - <?php esc_html_e( 'Event date', 'ffc' ); ?></li>
                <li><code>{{location}}</code> - <?php esc_html_e( 'Event location', 'ffc' ); ?></li>
                <li><code>{{company}}</code> - <?php esc_html_e( 'Company/Organization name', 'ffc' ); ?></li>
            </ul>

            <br><hr>

            <!-- 6. SECURITY FEATURES -->
            <h3 id="security">🔒 <?php esc_html_e( '6. Security Features', 'ffc' ); ?></h3>
            <table class="widefat striped ffc-help-table">
                <thead>
                    <tr>
                        <th width="25%"><?php esc_html_e( 'Feature', 'ffc' ); ?></th>
                        <th><?php esc_html_e( 'Description', 'ffc' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e( 'Allowlist', 'ffc' ); ?></strong></td>
                        <td>
                            <?php esc_html_e( 'Restrict certificate issuance to specific CPF/RF IDs.', 'ffc' ); ?><br>
                            <?php esc_html_e( 'Only people on the list can request certificates.', 'ffc' ); ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Denylist', 'ffc' ); ?></strong></td>
                        <td>
                            <?php esc_html_e( 'Block specific CPF/RF IDs or ticket codes.', 'ffc' ); ?><br>
                            <?php esc_html_e( 'Blocked entries cannot generate certificates.', 'ffc' ); ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Ticket Mode', 'ffc' ); ?></strong></td>
                        <td>
                            <?php esc_html_e( 'Require unique ticket codes for issuance.', 'ffc' ); ?><br>
                            <?php esc_html_e( 'Each ticket can only be used once and is invalidated after use.', 'ffc' ); ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Math CAPTCHA', 'ffc' ); ?></strong></td>
                        <td>
                            <?php esc_html_e( 'Built-in bot protection with simple math questions.', 'ffc' ); ?><br>
                            <?php esc_html_e( 'Automatically enabled on all forms.', 'ffc' ); ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'QR Code Verification', 'ffc' ); ?></strong></td>
                        <td>
                            <?php esc_html_e( 'Each certificate includes a QR code for instant validation.', 'ffc' ); ?><br>
                            <?php esc_html_e( 'Use [ffc_verification] shortcode to create verification page.', 'ffc' ); ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Unique Auth Codes', 'ffc' ); ?></strong></td>
                        <td>
                            <?php esc_html_e( 'Every certificate gets a unique authentication code.', 'ffc' ); ?><br>
                            <?php esc_html_e( 'Format: A1B2-C3D4-E5F6 (alphanumeric, 16 characters)', 'ffc' ); ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <br><hr>

            <!-- 7. COMPLETE EXAMPLES -->
            <h3 id="examples">📝 <?php esc_html_e( '7. Complete Template Examples', 'ffc' ); ?></h3>

            <h4><?php esc_html_e( 'Example 1: Simple Certificate', 'ffc' ); ?></h4>
            <pre style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd; overflow-x: auto;"><code>&lt;div style="padding: 40px; font-family: Arial; text-align: center;"&gt;
    &lt;h1 style="font-size: 24pt; color: #2271b1;"&gt;CERTIFICADO&lt;/h1&gt;
    
    &lt;p style="font-size: 14pt; margin: 30px 0;"&gt;
        Certificamos que &lt;strong&gt;{{name}}&lt;/strong&gt;, 
        CPF &lt;strong&gt;{{cpf_rf}}&lt;/strong&gt;, 
        participou do evento &lt;strong&gt;{{form_title}}&lt;/strong&gt;.
    &lt;/p&gt;
    
    &lt;p&gt;Data: {{submission_date}}&lt;/p&gt;
    &lt;p&gt;Código: {{auth_code}}&lt;/p&gt;
    
    {{qr_code:size=150}}
&lt;/div&gt;</code></pre>

            <h4 style="margin-top: 25px;"><?php esc_html_e( 'Example 2: Certificate with Header & Footer', 'ffc' ); ?></h4>
            <pre style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd; overflow-x: auto;"><code>&lt;div style="padding: 30px; font-family: Arial;"&gt;
    &lt;!-- Header with logos --&gt;
    &lt;table style="width: 100%; margin-bottom: 30px;"&gt;
        &lt;tr&gt;
            &lt;td style="width: 20%;"&gt;
                &lt;img src="https://example.com/logo-left.png" style="width: 140px;"&gt;
            &lt;/td&gt;
            &lt;td style="width: 60%; text-align: center;"&gt;
                &lt;div style="font-size: 11pt; font-weight: bold;"&gt;
                    ORGANIZATION NAME&lt;br&gt;
                    DEPARTMENT&lt;br&gt;
                    DIVISION
                &lt;/div&gt;
            &lt;/td&gt;
            &lt;td style="width: 20%; text-align: right;"&gt;
                &lt;img src="https://example.com/logo-right.png" style="width: 140px;"&gt;
            &lt;/td&gt;
        &lt;/tr&gt;
    &lt;/table&gt;
    
    &lt;!-- Title --&gt;
    &lt;p style="font-size: 20pt; text-align: center; margin-bottom: 30px;"&gt;
        &lt;strong&gt;CERTIFICATE OF ATTENDANCE&lt;/strong&gt;
    &lt;/p&gt;
    
    &lt;!-- Body --&gt;
    &lt;div style="font-size: 14pt; text-align: justify; line-height: 1.6;"&gt;
        We certify that &lt;strong&gt;{{name}}&lt;/strong&gt;, 
        ID: &lt;strong&gt;{{cpf_rf}}&lt;/strong&gt;, 
        successfully attended the &lt;strong&gt;{{program}}&lt;/strong&gt; program 
        held on December 11, 2025.
    &lt;/div&gt;
    
    &lt;!-- Signature --&gt;
    &lt;table style="width: 100%; margin-top: 40px;"&gt;
        &lt;tr&gt;
            &lt;td style="width: 50%;"&gt;&lt;/td&gt;
            &lt;td style="width: 50%; text-align: right;"&gt;
                &lt;img src="https://example.com/signature.png" style="height: 110px; width: auto;"&gt;&lt;br&gt;
                &lt;strong&gt;Director Name&lt;/strong&gt;&lt;br&gt;
                Position Title
            &lt;/td&gt;
        &lt;/tr&gt;
    &lt;/table&gt;
    
    &lt;!-- Footer with QR Code --&gt;
    &lt;div style="margin-top: 50px; border-top: 1px solid #ccc; padding-top: 10px;"&gt;
        &lt;table style="width: 100%;"&gt;
            &lt;tr&gt;
                &lt;td style="width: 30%;"&gt;
                    {{qr_code:size=150:margin=0}}
                &lt;/td&gt;
                &lt;td style="width: 70%; text-align: right; font-size: 9pt;"&gt;
                    Issued: {{submission_date}}&lt;br&gt;
                    Verify at: https://example.com/verify/&lt;br&gt;
                    Code: &lt;strong&gt;{{auth_code}}&lt;/strong&gt;
                &lt;/td&gt;
            &lt;/tr&gt;
        &lt;/table&gt;
    &lt;/div&gt;
&lt;/div&gt;</code></pre>

            <br><hr>

            <!-- 8. TROUBLESHOOTING -->
            <h3 id="troubleshooting">🔧 <?php esc_html_e( '8. Troubleshooting', 'ffc' ); ?></h3>
            
            <table class="widefat striped ffc-help-table">
                <thead>
                    <tr>
                        <th width="35%"><?php esc_html_e( 'Problem', 'ffc' ); ?></th>
                        <th><?php esc_html_e( 'Solution', 'ffc' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php esc_html_e( 'Variable not replaced (shows {{name}})', 'ffc' ); ?></td>
                        <td>
                            • <?php esc_html_e( 'Check spelling matches exactly', 'ffc' ); ?><br>
                            • <?php esc_html_e( 'Ensure field exists in form', 'ffc' ); ?><br>
                            • <?php esc_html_e( 'Use lowercase for custom fields', 'ffc' ); ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Image not showing in PDF', 'ffc' ); ?></td>
                        <td>
                            • <?php esc_html_e( 'Use absolute URLs (https://...)', 'ffc' ); ?><br>
                            • <?php esc_html_e( 'Check image is publicly accessible', 'ffc' ); ?><br>
                            • <?php esc_html_e( 'Add dimensions: style="width: 140px;"', 'ffc' ); ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'QR Code too large/small', 'ffc' ); ?></td>
                        <td>
                            • <?php esc_html_e( 'Use:', 'ffc' ); ?> <code>{{qr_code:size=150}}</code><br>
                            • <?php esc_html_e( 'Recommended: 100-200px for certificates', 'ffc' ); ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Formatting not showing (bold, italic)', 'ffc' ); ?></td>
                        <td>
                            • <?php esc_html_e( 'Use HTML tags:', 'ffc' ); ?> <code>&lt;strong&gt;</code> <code>&lt;em&gt;</code><br>
                            • <?php esc_html_e( 'Or inline style:', 'ffc' ); ?> <code>style="font-weight: bold;"</code>
                        </td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Layout broken in PDF', 'ffc' ); ?></td>
                        <td>
                            • <?php esc_html_e( 'Use tables for complex layouts', 'ffc' ); ?><br>
                            • <?php esc_html_e( 'Always use inline styles', 'ffc' ); ?><br>
                            • <?php esc_html_e( 'Test with simple content first', 'ffc' ); ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Settings not saving between tabs', 'ffc' ); ?></td>
                        <td>
                            • <?php esc_html_e( 'Update to v2.9.3+', 'ffc' ); ?><br>
                            • <?php esc_html_e( 'Clear WordPress cache', 'ffc' ); ?><br>
                            • <?php esc_html_e( 'Clear browser cache (Ctrl+F5)', 'ffc' ); ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div style="background: #e7f3ff; padding: 12px; border-left: 4px solid #2271b1; margin: 15px 0;">
                <strong>ℹ️ <?php esc_html_e( 'Need More Help?', 'ffc' ); ?></strong><br>
                <?php esc_html_e( 'For additional support, check the plugin repository documentation or contact support.', 'ffc' ); ?>
            </div>

        </div>