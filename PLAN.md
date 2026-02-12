# v4.9.0 — Info Field, Embed Field, Quiz Mode

## Sprint 1: Info Field (text_block)
New field type `info` for displaying static text (no user input).

### Admin (Form Builder)
- **`ffc-admin-field-builder.js`**: Add `{ value: 'info', label: 'Info / Text Block' }` to `getFieldTypes()`
- **`ffc-admin-field-builder.js`**: In `addFieldToBuilder()`, when type is `info`:
  - Hide Label, Variable Name, Required rows (not needed)
  - Show a `<textarea>` for content with description: "Supports bold, italic, and links (HTML)"
- **`class-ffc-form-editor-metabox-renderer.php`**: Add `info` to type `<select>`, in `render_field_row()`:
  - Show a content textarea instead of label/name/required when type is `info`
  - Store content in `field['content']` (new key)
- **`class-ffc-form-editor-save-handler.php`**: Accept `content` field, sanitize with `wp_kses()` allowing `<b>`, `<i>`, `<strong>`, `<em>`, `<a href>`, `<br>`, `<p>`

### Frontend
- **`class-ffc-shortcodes.php`**: In `render_field()`, when type is `info`:
  - Render `<div class="ffc-form-info-block">{content}</div>`
  - No `<input>`, no `name`, no validation
  - Skip in form processing entirely

### Processor
- **`class-ffc-form-processor.php`**: Skip fields with type `info` in the validation loop

### CSS
- **`ffc-frontend.css`**: Add `.ffc-form-info-block` styles (padding, border-left accent, background)

---

## Sprint 2: Embed Field
New field type `embed` for displaying videos, audio, and images.

### Admin (Form Builder)
- **`ffc-admin-field-builder.js`**: Add `{ value: 'embed', label: 'Embed (Media)' }` to `getFieldTypes()`
- **`ffc-admin-field-builder.js`**: In `addFieldToBuilder()`, when type is `embed`:
  - Hide Label, Variable Name, Required rows
  - Show a URL input field with description: "Paste a URL (YouTube, Vimeo, MP3, image)"
- **`class-ffc-form-editor-metabox-renderer.php`**: Add `embed` to type `<select>`, in `render_field_row()`:
  - Show a URL input instead of label/name/required
  - Store URL in `field['embed_url']` (new key)
- **`class-ffc-form-editor-save-handler.php`**: Accept `embed_url`, sanitize with `esc_url_raw()`

### Frontend
- **`class-ffc-shortcodes.php`**: In `render_field()`, when type is `embed`:
  - Detect URL type:
    - Image extensions (jpg, jpeg, png, gif, webp, svg) → `<img src="..." style="max-width:600px;width:100%">`
    - Audio extensions (mp3, ogg, wav) → `<audio controls src="..." style="width:100%;max-width:600px">`
    - Other URLs → `wp_oembed_get($url, ['width' => 600])` (YouTube, Vimeo, etc.)
  - Wrap in `<div class="ffc-form-embed-block">`
  - No `<input>`, no `name`, no validation

### Processor
- **`class-ffc-form-processor.php`**: Skip fields with type `embed` in the validation loop

### CSS
- **`ffc-frontend.css`**: Add `.ffc-form-embed-block` styles (max-width: 600px, margin, responsive iframe via aspect-ratio)

---

## Sprint 3: Quiz Mode — Admin Config
Checkbox "Quiz Mode" in the form editor config metabox.

### Admin Config
- **`class-ffc-form-editor-metabox-renderer.php`**: Add new metabox section "Quiz Mode" (or within existing Restriction box):
  - Checkbox: "Enable Quiz Mode"
  - Number input: "Passing Score" (minimum points to pass)
  - Number input: "Max Attempts" (0 = unlimited)
  - Checkbox: "Show Score to User"
  - Checkbox: "Show Wrong Answers to User"
- **`class-ffc-form-editor-save-handler.php`**: Save quiz config in `_ffc_form_config`:
  ```php
  'quiz_enabled' => '1',
  'quiz_passing_score' => 70,
  'quiz_max_attempts' => 3,
  'quiz_show_score' => '1',
  'quiz_show_errors' => '0',
  ```

### Admin Field Builder — Points per Option
- **`class-ffc-form-editor-metabox-renderer.php`**: In `render_field_row()`, for `select`/`radio` fields when quiz mode is enabled:
  - Show "Points" input next to the options field
  - Store as `field['points']` — comma-separated values matching options (e.g., options: "A, B, C" / points: "10, 0, 5")
- **`ffc-admin-field-builder.js`**:
  - Add points input that shows/hides alongside options
  - Toggle visibility based on quiz mode checkbox
  - Include points in `updateFieldsJSON()`
- **`class-ffc-form-editor-save-handler.php`**: Accept `points` field, sanitize as comma-separated integers

---

## Sprint 4: Quiz Mode — Frontend Scoring & Attempt Control
Process quiz submissions: calculate score, manage attempts, show results.

### Processor
- **`class-ffc-form-processor.php`**: When quiz_enabled:
  1. After collecting `$submission_data`, calculate score:
     - Loop through fields with `points` defined
     - Match user answer to option → get corresponding point value
     - Sum points → `$quiz_score`; sum all max points → `$quiz_max_score`
  2. Check for existing in-progress attempt:
     - Query submissions table: `WHERE form_id = %d AND cpf_rf = %s AND status = 'quiz_in_progress'`
     - If found: this is a retry → `$attempts = existing.quiz_attempts + 1`
     - If not found: first attempt → `$attempts = 1`
  3. Determine result:
     - If `$quiz_score >= $passing_score` → **passed**
     - If failed AND `$attempts < $max_attempts` (or max=0) → **can retry**
     - If failed AND `$attempts >= $max_attempts` → **final fail**
  4. Store quiz data in `data` JSON:
     ```json
     {
       "quiz_score": 70,
       "quiz_max_score": 100,
       "quiz_passed": true,
       "quiz_attempts": 2,
       "quiz_answers": { "question1": "Answer B", "question2": "Answer A" }
     }
     ```
  5. Actions:
     - **Passed**: UPDATE/INSERT status → `publish`, generate certificate, return success
     - **Can retry**: UPDATE/INSERT status → `quiz_in_progress`, return error with retry message
     - **Final fail**: UPDATE status → `quiz_failed`, return error with final message

### Frontend JS
- **`ffc-frontend.js`**: Handle quiz response:
  - On retry: show message (score if enabled, wrong answers if enabled), re-enable form
  - On pass: normal certificate flow
  - On final fail: show failure message, disable form

### Submission Handler
- **`class-ffc-submission-handler.php`**: Support `quiz_in_progress` status, UPDATE existing row on retry

---

## Sprint 5: Quiz Mode — Admin Views & Certificate Tags
Show quiz data in admin and add certificate placeholders.

### Admin Submissions List
- **`class-ffc-submissions-list.php`**:
  - Add "Score" column showing `quiz_score / quiz_max_score`
  - Add "Status" badge: Passed (green) / Failed (red) / In Progress (yellow)
  - Add filter dropdown: All / Passed / Failed / In Progress
  - Only show these when any form has quiz_enabled

### Certificate Placeholders
- **`class-ffc-pdf-generator.php`**: Add new tags:
  - `{{score}}` → quiz_score value
  - `{{max_score}}` → quiz_max_score value
  - `{{score_percent}}` → percentage (e.g., "85%")
  - These are optional — template works without them

### Documentation
- **`class-ffc-form-editor-metabox-renderer.php`**: Add quiz placeholders to the layout hints/documentation

---

## Sprint 6: Build, Version Bump, Readme & Translations
Finalize release.

### Version
- **`ffcertificate.php`**: Bump `FFC_VERSION` to `4.9.0`
- **`readme.txt`**: Update `Stable tag: 4.9.0`

### Changelog
- **`readme.txt`**: Add v4.9.0 changelog entry with all features
- **`readme.txt`**: Add upgrade notice

### Translations
- **`languages/ffcertificate-pt_BR.po`**: Add all new strings
- Compile `.mo` file

### Build
- `npm run build` (minify all JS/CSS)

### Commit & Push
