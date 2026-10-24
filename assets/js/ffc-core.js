/**
 * FFC Core Module
 * v3.1.0 - Added centralized helpers
 *
 * Global namespace initialization and shared constants
 * This file should be loaded FIRST before all other FFC modules
 *
 * Changelog:
 * v3.1.0: Added FFC.ajax() and FFC.toggleFields() centralized helpers
 * v3.0.0: Modular Architecture
 *
 * @since 3.0.0
 */

(function(window) {
    'use strict';

    /**
     * What each message kind puts on the message and on its control (#1844).
     * The classes are written out whole so a scan for who emits them finds
     * them; `Core\FieldMessage` carries the same map for the server side.
     */
    var FIELD_KINDS = {
        error: { message: 'is-error', icon: 'ffc-icon-error', control: 'is-invalid', role: 'alert', invalid: true },
        warning: { message: 'is-warning', icon: 'ffc-icon-warning', control: 'is-warning', role: 'status', invalid: false }
    };

    var fieldMessageCount = 0;

    /**
     * The message id for a control: `ffc-msg-` plus its id, else its name,
     * with anything an id cannot hold turned into a hyphen — the rule
     * `Core\FieldMessage::id()` applies, so a server-printed message is found.
     */
    function fieldMessageId(key) {
        return 'ffc-msg-' + String(key).replace(/[^A-Za-z0-9_-]/g, '-');
    }

    function controlMessageId(control) {
        var key = control.id || control.getAttribute('name') || '';
        if (!key) {
            fieldMessageCount += 1;
            key = 'field-' + fieldMessageCount;
        }
        return fieldMessageId(key);
    }

    function placeFieldMessage(message, control, options) {
        var within = options.within && jQuery(options.within)[0];
        if (within) {
            within.appendChild(message);
            return;
        }
        var anchor = options.after && jQuery(options.after)[0];
        if (!anchor) {
            anchor = control;
            if (control.type === 'checkbox' || control.type === 'radio') {
                anchor = control.closest('.ffc-radio-group, .ffc-checkbox-group, [role="group"], [role="radiogroup"]')
                    || control.closest('label')
                    || control;
            }
        }
        if (anchor.parentNode) {
            anchor.parentNode.insertBefore(message, anchor.nextSibling);
        }
    }

    function addIdToken(el, attr, id) {
        var tokens = (el.getAttribute(attr) || '').split(/\s+/).filter(Boolean);
        if (tokens.indexOf(id) === -1) {
            tokens.push(id);
        }
        el.setAttribute(attr, tokens.join(' '));
    }

    function removeIdToken(el, attr, id) {
        var tokens = (el.getAttribute(attr) || '').split(/\s+/).filter(function(token) {
            return token && token !== id;
        });
        if (tokens.length) {
            el.setAttribute(attr, tokens.join(' '));
        } else {
            el.removeAttribute(attr);
        }
    }

    /**
     * What the visitor's browser is, asked in one place (#2030).
     *
     * The success card, the pre-flight banners, the GPS timings and the PDF
     * download each used to carry their own copy of these patterns. Every
     * answer reads the user agent at call time, so a test (or a browser that
     * changes it) is answered as it is now. Each test is one capability or
     * one context, never a brand: Samsung Internet is named only because its
     * PDF download needs the placeholder tab.
     */
    var Platform = {
        /** @returns {string} The user agent, or '' where there is none. */
        ua: function() {
            return (typeof navigator !== 'undefined' && navigator.userAgent) || '';
        },

        /**
         * iPhone, iPod or iPad. iPadOS 13+ reports a Mac but has touch.
         *
         * @returns {boolean}
         */
        ios: function() {
            var ua = this.ua();
            return /iPad|iPhone|iPod/.test(ua)
                || (/Macintosh/.test(ua) && typeof navigator !== 'undefined' && navigator.maxTouchPoints > 1);
        },

        /** @returns {'ios'|'android'|'desktop'} */
        os: function() {
            if (this.ios()) {
                return 'ios';
            }
            return /Android/i.test(this.ua()) ? 'android' : 'desktop';
        },

        /**
         * WebKit's rules apply: iOS, where every browser is WebKit, and
         * desktop Safari. Decides the GPS timings.
         *
         * @returns {boolean}
         */
        webkit: function() {
            return this.ios() || /^((?!chrome|android).)*safari/i.test(this.ua());
        },

        /** @returns {boolean} An Android WebView (`; wv)` or `; wv;`). */
        androidWebView: function() {
            var ua = this.ua();
            return /\bwv\)/.test(ua) || /; wv;/.test(ua);
        },

        /**
         * An app's embedded browser: an Android WebView, or an iOS app that
         * adds its own marker to the user agent. Safari and Chrome on iOS
         * match none of these.
         *
         * @returns {boolean}
         */
        inApp: function() {
            var ua = this.ua();
            return this.androidWebView()
                || /(FBAN|FBAV|FBIOS)/i.test(ua)
                || /Instagram/i.test(ua)
                || /TwitterIOS\/|Twitter for iPhone/i.test(ua)
                || /WhatsApp/i.test(ua)
                || /LinkedInApp/i.test(ua)
                || /BytedanceWebView|musical_ly/i.test(ua)
                || /Line\/\d/i.test(ua);
        },

        /** @returns {boolean} Samsung Internet. */
        samsung: function() {
            return /SamsungBrowser/i.test(this.ua());
        },

        /** @returns {boolean} A phone or tablet, by the user agent alone. */
        mobile: function() {
            return /Mobi|Android|iPhone|iPad|iPod/i.test(this.ua());
        }
    };

    /**
     * Initialize global FFC namespace
     */
    var core = {

        Platform: Platform,

        /**
         * Plugin version
         */
        version: (window.ffcCoreConfig && window.ffcCoreConfig.version) || '0.0.0',
        
        /**
         * Shared configuration.
         *
         * 6.6.3 — `nonce` and `strings` were captured once at IIFE load
         * time, which broke on full-page-cached sites: `ffc-dynamic-fragments.js`
         * refreshes `window.ffc_ajax.nonce` to the per-visitor session
         * value AFTER the cached HTML lands, but `FFC.config.nonce` kept
         * the stale snapshot from the cached HTML. `FFC.request` then
         * sent the stale nonce and the server rejected with "Security
         * check failed. Please refresh the page." even on a fresh
         * reload (the cache served the same stale HTML again).
         *
         * Getters here resolve `window.ffc_ajax` on every read, so the
         * dynamic-fragments refresh propagates without callers needing
         * to know about it.
         */
        config: {
            debug: false,
            ajaxUrl: window.ffc_ajax?.ajax_url || '/wp-admin/admin-ajax.php',
            get nonce() { return (window.ffc_ajax && window.ffc_ajax.nonce) || ''; },
            get strings() { return (window.ffc_ajax && window.ffc_ajax.strings) || {}; }
        },
        
        /**
         * Check if a module is loaded
         * 
         * @param {string} moduleName - Module name (e.g., 'Utils', 'Frontend', 'Admin')
         * @return {boolean} True if module is loaded
         */
        isModuleLoaded: function(moduleName) {
            return typeof this[moduleName] !== 'undefined' && this[moduleName] !== null;
        },
        
        /**
         * Debug logger (only logs if debug mode is enabled)
         * 
         * @param {string} message - Message to log
         * @param {*} data - Optional data to log
         */
        log: function(message, data) {
            if (this.config.debug) {
                if (typeof data !== 'undefined') {
                    console.log('[FFC Debug]', message, data);
                } else {
                    console.log('[FFC Debug]', message);
                }
            }
        },
        
        /**
         * Error logger (always logs)
         * 
         * @param {string} message - Error message
         * @param {*} error - Optional error object
         */
        error: function(message, error) {
            if (typeof error !== 'undefined') {
                console.error('[FFC Error]', message, error);
            } else {
                console.error('[FFC Error]', message);
            }
        },
        
        /**
         * Warning logger (always logs)
         * 
         * @param {string} message - Warning message
         */
        warn: function(message) {
            console.warn('[FFC Warning]', message);
        },
        
        /**
         * Get AJAX URL
         * 
         * @return {string} AJAX URL
         */
        getAjaxUrl: function() {
            return this.config.ajaxUrl;
        },
        
        /**
         * Get nonce
         * 
         * @return {string} Nonce
         */
        getNonce: function() {
            return this.config.nonce;
        },
        
        /**
         * Get translated string
         *
         * @param {string} key - String key
         * @param {string} defaultValue - Default value if key not found
         * @return {string} Translated string
         */
        getString: function(key, defaultValue) {
            return this.config.strings[key] || defaultValue || key;
        },

        /**
         * Centralized AJAX helper
         *
         * @param {Object} options - AJAX configuration
         * @param {string} options.action - WordPress AJAX action name
         * @param {Object} options.data - Additional data to send
         * @param {Function} options.success - Success callback
         * @param {Function} options.error - Error callback
         * @param {string} options.method - HTTP method (default: 'POST')
         * @param {boolean} options.includeNonce - Include nonce automatically (default: true)
         * @return {jqXHR} jQuery AJAX object
         */
        ajax: function(options) {
            if (!options.action) {
                this.error('FFC.ajax: action parameter is required');
                return;
            }

            var ajaxData = options.data || {};
            ajaxData.action = options.action;

            // Include nonce by default
            if (options.includeNonce !== false && this.config.nonce) {
                ajaxData.nonce = this.config.nonce;
            }

            var ajaxOptions = {
                url: this.config.ajaxUrl,
                type: options.method || 'POST',
                data: ajaxData,
                success: options.success || function() {},
                error: options.error || function(xhr, status, error) {
                    FFC.error('AJAX request failed', {
                        action: options.action,
                        status: status,
                        error: error
                    });
                }
            };

            this.log('AJAX request', { action: options.action, data: ajaxData });

            return jQuery.ajax(ajaxOptions);
        },

        /**
         * Promise-based AJAX helper (admin-ajax convention).
         *
         * Wraps `jQuery.post` so the entire admin/frontend codebase has a
         * single chokepoint for cross-cutting concerns (nonce injection,
         * response unwrapping, error normalisation). Returns a native
         * Promise resolving with `response.data` on success or rejecting
         * with an `Error` whose message comes from `response.data.message`.
         *
         * Why this lives alongside the legacy `FFC.ajax`:
         *   - `FFC.ajax` is callback-based with options object — fine but
         *     verbose and inconsistent with modern code.
         *   - New code should use `FFC.request` for cleaner `.then/.catch`
         *     ergonomics. Old call-sites keep using `FFC.ajax`; migrations
         *     happen opportunistically when the file is touched.
         *
         * @param {string} action          WordPress AJAX action.
         * @param {Object} [data]          Payload (action + nonce injected).
         * @param {Object} [options]
         * @param {string} [options.nonce] Override the default nonce.
         * @param {string} [options.ajaxUrl] Override admin-ajax.php URL.
         * @returns {Promise<*>} Resolves with `response.data` on success.
         */
        request: function(action, data, options) {
            options = options || {};
            // Nonce resolution order: explicit options.nonce wins (used
            // by the locations CRUD style callers); a nonce baked into
            // `data` wins next (used by callers localized via their own
            // wp_localize_script that don't go through ffc_ajax — eg.
            // ffc-form-list-features.js, ffc-admin-autosave.js); the
            // global ffc_ajax nonce is the last-resort default.
            //
            // Live-read pattern: `this.config.nonce` is a getter that
            // resolves `window.ffc_ajax.nonce` every read (6.6.2 fix),
            // so the inner send() below picks up a mid-flight refresh.
            var self = this;
            function resolveNonce() {
                return options.nonce
                    || (data && typeof data === 'object' && data.nonce)
                    || self.config.nonce
                    || '';
            }
            function buildPayload(nonce) {
                // Callers may pass `data` as either an object (the usual
                // case) or a pre-serialised URL-encoded string — typically
                // the result of `$form.serialize()`. Both forms get action
                // + nonce appended.
                if (typeof data === 'string') {
                    return data
                        + '&action=' + encodeURIComponent(action)
                        + '&nonce=' + encodeURIComponent(nonce);
                }
                return jQuery.extend({}, data, {
                    action: action,
                    nonce: nonce,
                });
            }
            var url = options.ajaxUrl || this.config.ajaxUrl || '/wp-admin/admin-ajax.php';
            // jQuery.post doesn't accept a timeout. When callers want one,
            // fall through to jQuery.ajax({ url, type:'POST', data, timeout })
            // which returns the same jqXHR-with-.done/.fail interface.
            return new Promise(function(resolve, reject) {
                function send() {
                    var payload = buildPayload(resolveNonce());
                    var jqXHR = options.timeout
                        ? jQuery.ajax({ url: url, type: 'POST', data: payload, timeout: options.timeout })
                        : jQuery.post(url, payload);
                    jqXHR
                        .done(function(res) {
                            if (!res || !res.success) {
                                // Server may send `data` as either an object
                                // ({message: '...'}) or a bare string — both
                                // are valid wp_send_json_error shapes.
                                var serverMsg = (res && typeof res.data === 'string')
                                    ? res.data
                                    : (res && res.data && res.data.message);
                                var msg = serverMsg
                                    || (FFC.config.strings && FFC.config.strings.error)
                                    || 'Request failed';
                                var err = new Error(msg);
                                err.fromServer = !!serverMsg;
                                err.data = res && res.data;

                                // 6.6.3 — stale-nonce auto-recovery.
                                // When wp_verify_nonce() rejects on the
                                // server it now returns refresh_nonce + a
                                // fresh new_nonce bound to the visitor's
                                // current session cookie. Push the new
                                // value into window.ffc_ajax.nonce (the
                                // FFC.config.nonce getter reads it live)
                                // and re-issue from inside the same outer
                                // Promise so callers see exactly one
                                // resolution / rejection — no extra
                                // microtask depth.
                                //
                                // Covers iOS Safari ITP/Private Relay
                                // rotating cookies mid-session, cached
                                // HTML carrying another visitor's nonce,
                                // and ffc-dynamic-fragments silently
                                // failing on restrictive networks.
                                if (
                                    !options._ffcNonceRetried
                                    && err.data
                                    && err.data.refresh_nonce
                                    && err.data.new_nonce
                                ) {
                                    if (typeof window.ffc_ajax !== 'undefined' && window.ffc_ajax) {
                                        window.ffc_ajax.nonce = err.data.new_nonce;
                                    }
                                    if (typeof console !== 'undefined' && console.warn) {
                                        console.warn('[FFC] Stale nonce auto-recovered; retrying ' + action);
                                    }
                                    options._ffcNonceRetried = true;
                                    send();
                                    return;
                                }
                                // Announce every server-side rejection on a
                                // single wire. A proof-of-work captcha has to
                                // be reset whenever the server refuses a
                                // submission — its solution is spent whether
                                // or not the captcha is what failed — and
                                // wiring that into each rejection site would
                                // mean finding them all, and finding every
                                // future one. Listeners are optional; nothing
                                // here depends on anyone subscribing.
                                if (typeof document !== 'undefined' && typeof window.CustomEvent === 'function') {
                                    document.dispatchEvent(new CustomEvent('ffc:request-rejected', {
                                        detail: { action: action, data: err.data }
                                    }));
                                }

                                reject(err);
                                return;
                            }
                            resolve(res.data);
                        })
                        .fail(function(xhr) {
                            var msg = (FFC.config.strings && FFC.config.strings.connectionError)
                                || 'Connection error';
                            var err = new Error(msg);
                            err.fromServer = false;
                            // Expose the jqXHR so callers can inspect
                            // responseJSON / status / etc. when the failure
                            // is an HTTP error (eg. 429 with a rate_limit
                            // payload in the body).
                            err.xhr = xhr;
                            if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                                err.data = xhr.responseJSON.data;
                            }
                            reject(err);
                        });
                }
                send();
            });
        },

        /**
         * Promise-based WP REST API helper.
         *
         * Sibling of `FFC.request` for endpoints that live on the WP REST
         * surface instead of `admin-ajax.php`. Centralises the verbose
         * jQuery.ajax pattern that user-dashboard / audience callers
         * spell out by hand:
         *   - `X-WP-Nonce` header injection (REST convention, not body param);
         *   - JSON body encoding for write methods (POST/PUT/PATCH/DELETE);
         *   - error normalisation that surfaces `response.message` from
         *     a WP_REST_Response error body when available.
         *
         * @param {string} url Full URL. Callers pass
         *                     `ffcDashboard.restUrl + 'user/profile'` etc.
         *                     The helper does not build the URL, so FFC.config
         *                     stays decoupled from the dashboard/audience
         *                     localized vars.
         * @param {Object} [options]
         * @param {string} [options.method='GET'] HTTP verb.
         * @param {Object} [options.data] Payload. GET/HEAD → query string;
         *                                anything else → JSON body.
         * @param {string} [options.nonce] X-WP-Nonce override. Defaults to
         *                                 `FFC.config.restNonce` when set.
         * @returns {Promise<*>} Resolves with the parsed response body;
         *                       rejects with an `Error` (its `.xhr` field
         *                       keeps the jqXHR for introspection).
         */
        rest: function(url, options) {
            options = options || {};
            var method = (options.method || 'GET').toUpperCase();
            var nonce = options.nonce || (this.config && this.config.restNonce) || '';

            var ajaxOpts = {
                url: url,
                method: method,
                beforeSend: function(xhr) {
                    if (nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', nonce);
                    }
                }
            };

            if (typeof options.timeout === 'number') {
                ajaxOpts.timeout = options.timeout;
            }

            if (typeof options.data !== 'undefined' && options.data !== null) {
                if (method === 'GET' || method === 'HEAD') {
                    ajaxOpts.data = options.data;
                } else {
                    ajaxOpts.contentType = 'application/json';
                    ajaxOpts.data = JSON.stringify(options.data);
                }
            }

            return new Promise(function(resolve, reject) {
                // Pass success/error inside ajaxOpts (instead of chaining
                // .done/.fail on the return value) so test doubles that
                // intercept $.ajax can drive resolution by invoking
                // opts.success / opts.error directly.
                ajaxOpts.success = function(response) { resolve(response); };
                ajaxOpts.error = function(xhr) {
                    var msg = (xhr && xhr.responseJSON && xhr.responseJSON.message)
                        || (FFC.config.strings && FFC.config.strings.connectionError)
                        || 'Request failed';
                    var err = new Error(msg);
                    err.xhr = xhr;
                    reject(err);
                };
                jQuery.ajax(ajaxOpts);
            });
        },

        /**
         * Carry `required` with a container's visibility.
         *
         * Constraint validation does not care whether a control is on
         * screen: an empty `required` field inside a `display: none`
         * block still blocks the submit, and the browser reports it
         * against an element nobody can see — in Chrome, "An invalid form
         * control with name='…' is not focusable", with no way for the
         * operator to act on it. Only `disabled` bars a control from
         * validation, and disabling would drop the field from the POST,
         * which changes what gets saved.
         *
         * So the attribute is removed while the block is hidden and put
         * back when it returns. The marker attribute is what makes that
         * reversible: without it a second call would promote every field
         * in the container to required, including the ones that never
         * were (#1117).
         *
         * @param {jQuery|string} $container Wrapper whose fields follow its visibility.
         * @param {boolean}       on         True when the block is visible.
         */
        setRequiredWithin: function($container, on) {
            jQuery($container).find('[required], [data-ffc-required-off]').each(function() {
                var $field = jQuery(this);
                if (on) {
                    if (typeof $field.attr('data-ffc-required-off') !== 'undefined') {
                        $field.prop('required', true).removeAttr('data-ffc-required-off');
                    }
                } else if (this.required) {
                    $field.prop('required', false).attr('data-ffc-required-off', '');
                }
            });
        },

        /**
         * Show a message under a field and put the field in its state (#1844).
         *
         * One shape for every field the plugin draws: the label, the control,
         * this message, then the hint and the counter. An error marks the
         * control `is-invalid` with `aria-invalid` and is announced with
         * `role="alert"`; a warning marks it `is-warning`, is announced
         * politely with `role="status"` and does not block anything; `valid`
         * removes any message and marks the control `is-valid`.
         *
         * The message's id is APPENDED to `aria-describedby`, so a hint the
         * control already names keeps being read; clearing removes that id
         * and nothing else. The text goes in as `textContent`, never as HTML.
         *
         * The message goes after the control, or after the group that holds
         * a checkbox or radio, so it never lands between a box and its label.
         * `options.after` names another element to follow, and
         * `options.within` a container to append it to, for a field whose
         * layout the default cannot read (a table row, a cascade of selects);
         * `options.id` keys a message several controls share, as the server
         * keys it.
         * A message the server already printed — same id, see
         * `Core\FieldMessage` — is reused where it stands.
         *
         * @param {Element|jQuery|string} input   The control, or every control of one group.
         * @param {string}                kind    'error', 'warning' or 'valid'.
         * @param {string}                [text]  The message, plain text.
         * @param {Object}                [options] `after` or `within` (an Element or jQuery), `id`.
         * @return {Element|null} The message element; null for 'valid' or no control.
         */
        fieldMessage: function(input, kind, text, options) {
            var $controls = jQuery(input);
            if (!$controls.length) {
                return null;
            }
            var spec = FIELD_KINDS[kind];
            if (!spec) {
                this.clearFieldMessage($controls);
                if (kind === 'valid') {
                    $controls.addClass('is-valid');
                }
                return null;
            }

            options = options || {};
            var first = $controls[0];
            var id = options.id
                ? fieldMessageId(options.id)
                : (first.getAttribute('data-ffc-message-id') || controlMessageId(first));
            var message = document.getElementById(id);
            if (!message || !message.classList.contains('ffc-field-message')) {
                message = document.createElement('span');
                message.id = id;
                placeFieldMessage(message, first, options);
            }

            message.className = 'ffc-field-message ' + spec.message + ' ' + spec.icon;
            message.setAttribute('role', spec.role);
            message.removeAttribute('hidden');
            message.textContent = text === null || typeof text === 'undefined' ? '' : String(text);

            $controls.each(function() {
                this.classList.remove('is-invalid', 'is-warning', 'is-valid');
                this.classList.add(spec.control);
                if (spec.invalid) {
                    this.setAttribute('aria-invalid', 'true');
                } else {
                    this.removeAttribute('aria-invalid');
                }
                this.setAttribute('data-ffc-message-id', id);
                addIdToken(this, 'aria-describedby', id);
            });

            return message;
        },

        /**
         * Remove a field's message and state, as `fieldMessage()` set them.
         *
         * Only the message's own id leaves `aria-describedby`; a hint id the
         * control carried before stays. A message shared by several controls
         * is removed with the last of them.
         *
         * @param {Element|jQuery|string} input The control, or every control of one group.
         */
        clearFieldMessage: function(input) {
            jQuery(input).each(function() {
                var id = this.getAttribute('data-ffc-message-id');
                if (id) {
                    removeIdToken(this, 'aria-describedby', id);
                    this.removeAttribute('data-ffc-message-id');
                    // A message several controls share stays while any of
                    // them still points at it.
                    var message = document.getElementById(id);
                    if (message && message.classList.contains('ffc-field-message') && message.parentNode
                        && !document.querySelector('[data-ffc-message-id="' + id + '"]')) {
                        message.parentNode.removeChild(message);
                    }
                }
                this.classList.remove('is-invalid', 'is-warning', 'is-valid');
                this.removeAttribute('aria-invalid');
            });
        },

        /**
         * The localized "This field is required." every field message uses
         * for an empty required control.
         *
         * @return {string}
         */
        requiredMessage: function() {
            var strings = (window.ffcCoreConfig && window.ffcCoreConfig.strings) || {};
            return strings.fieldRequired || 'This field is required.';
        },

        /**
         * The button categories (#1874), the same list as `Core\Button::CATEGORIES`.
         */
        buttonCategories: ['primary', 'neutral', 'create', 'caution', 'danger'],

        /**
         * Build a button of the `.ffc-btn` family (#1874): the JS twin of
         * `Core\Button::html()`, printing the same markup, so a button a
         * script builds looks like one the server printed.
         *
         * The label lands through `textContent`; the icon is a `.ffc-icon-*`
         * class on the button, so rewriting the label later keeps it.
         *
         * @param {Object} opts
         * @param {string} opts.label  Visible text; required.
         * @param {string} [opts.category]  primary | neutral | create | caution | danger; anything else is neutral.
         * @param {string} [opts.icon]  A `.ffc-icon-*` class name without the prefix (`plus`).
         * @param {string} [opts.href]  Makes the button a link.
         * @param {string} [opts.type]  button (default) | submit | reset.
         * @param {string} [opts.id]  Element id.
         * @param {string} [opts.className]  Extra classes: the hooks scripts read.
         * @param {boolean} [opts.disabled]  Disabled (`aria-disabled` + `.disabled` on a link).
         * @param {Object} [opts.attrs]  Further attributes; `true` prints a bare one, `false`/`null` drops it.
         * @param {boolean} [opts.iconOnly]  No visible text (#1879): the label becomes
         *        `aria-label` and `title`, and an icon is required.
         * @return {HTMLElement|null}  Null without a label, or icon-only without an icon.
         */
        button: function(opts) {
            var o = opts || {};
            if (o.label === null || typeof o.label === 'undefined' || o.label === '') {
                return null;
            }
            var hasIcon = typeof o.icon === 'string' && /^[a-z0-9-]+$/.test(o.icon);
            if (o.iconOnly && !hasIcon) {
                return null;
            }
            var category = this.buttonCategories.indexOf(o.category) !== -1 ? o.category : 'neutral';
            var isLink = typeof o.href === 'string';
            var el = document.createElement(isLink ? 'a' : 'button');
            var classes = ['ffc-btn', 'ffc-btn-' + category];
            if (o.iconOnly) {
                classes.push('ffc-btn-icon');
            }
            if (hasIcon) {
                classes.push('ffc-icon-' + o.icon);
            }
            String(o.className || '').split(/\s+/).forEach(function(name) {
                if (name && classes.indexOf(name) === -1) {
                    classes.push(name);
                }
            });
            if (isLink) {
                el.setAttribute('href', o.href);
                if (o.disabled) {
                    classes.push('disabled');
                    el.setAttribute('aria-disabled', 'true');
                }
            } else {
                el.type = ['button', 'submit', 'reset'].indexOf(o.type) !== -1 ? o.type : 'button';
                if (o.disabled) {
                    el.disabled = true;
                }
            }
            el.className = classes.join(' ');
            if (o.id) {
                el.id = String(o.id);
            }
            if (o.iconOnly) {
                el.setAttribute('aria-label', String(o.label));
                el.setAttribute('title', String(o.label));
            }
            var attrs = o.attrs || {};
            Object.keys(attrs).forEach(function(key) {
                var value = attrs[key];
                var name = String(key).toLowerCase();
                if (!/^[a-z][a-z0-9_:.-]*$/.test(name) || name.indexOf('on') === 0 || el.hasAttribute(name)) {
                    return;
                }
                if (value === true) {
                    el.setAttribute(name, '');
                } else if (value !== false && value !== null && typeof value !== 'undefined') {
                    el.setAttribute(name, String(value));
                }
            });
            if (!o.iconOnly) {
                el.textContent = String(o.label);
            }
            return el;
        },

        /**
         * {@link FFC.button} as an HTML string, for code that builds markup
         * by concatenation. Every value is escaped by the DOM that built it.
         *
         * @param {Object} opts  See {@link FFC.button}.
         * @return {string}
         */
        buttonHtml: function(opts) {
            var el = this.button(opts);
            return el ? el.outerHTML : '';
        },

        /**
         * Build a system notice (#1842): the JS twin of `Core\Notice::render()`,
         * printing the same markup, so no script hand-writes it.
         *
         * Every string lands through `textContent`, never `innerHTML`, so a
         * message from the server or a translation cannot become markup.
         *
         * @param {string} tone  info | warning | danger | success | neutral; anything else is info.
         * @param {string} icon  A `.ffc-icon-*` class name without the prefix (`clock`).
         * @param {string|string[]} text  The message; a list prints one paragraph each.
         * @param {string} [title]  Optional bold title.
         * @param {Object} [options]
         * @param {string} [options.role]  status (default) | alert | note.
         * @param {string} [options.live]  polite | assertive.
         * @param {string} [options.hook]  A `data-ffc-notice` value a script finds it by.
         * @param {Node[]} [options.children]  Nodes appended after the text (a button).
         * @return {HTMLElement}
         */
        notice: function(tone, icon, text, title, options) {
            var fallbacks = { info: 'info', warning: 'warning', danger: 'error', success: 'success', neutral: 'info' };
            var opts = options || {};
            if (!Object.prototype.hasOwnProperty.call(fallbacks, tone)) {
                tone = 'info';
            }
            if (typeof icon !== 'string' || !/^[a-z0-9-]+$/.test(icon)) {
                icon = fallbacks[tone];
            }

            var notice = document.createElement('div');
            notice.className = 'ffc-notice ffc-notice-' + tone;
            notice.setAttribute('role', ['status', 'alert', 'note'].indexOf(opts.role) !== -1 ? opts.role : 'status');
            if (opts.live === 'polite' || opts.live === 'assertive') {
                notice.setAttribute('aria-live', opts.live);
            }
            if (typeof opts.hook === 'string' && opts.hook !== '') {
                notice.setAttribute('data-ffc-notice', opts.hook);
            }

            var mark = document.createElement('span');
            mark.className = 'ffc-notice-icon ffc-icon-' + icon;
            mark.setAttribute('aria-hidden', 'true');

            var body = document.createElement('div');
            body.className = 'ffc-notice-body';
            if (title) {
                var strong = document.createElement('strong');
                strong.className = 'ffc-notice-title';
                strong.textContent = String(title);
                body.appendChild(strong);
            }
            (Array.isArray(text) ? text : [text]).forEach(function(paragraph) {
                if (paragraph === null || typeof paragraph === 'undefined' || paragraph === '') {
                    return;
                }
                var p = document.createElement('p');
                p.className = 'ffc-notice-text';
                p.textContent = String(paragraph);
                body.appendChild(p);
            });
            (opts.children || []).forEach(function(child) {
                if (child) {
                    body.appendChild(child);
                }
            });

            notice.appendChild(mark);
            notice.appendChild(body);
            return notice;
        },

        /**
         * Confirm a booking cancellation. A booking that covers a
         * range is cancelled whole, so the dialog states every day it covers
         * before the confirm button can be pressed. The confirm is the
         * danger button and waits out the same countdown as the override
         * warning.
         *
         * @param {Object}   options
         * @param {number}   options.count    Days the booking covers (1 for a single day).
         * @param {string}   options.label    The booking's dates, already formatted.
         * @param {Object}   options.strings  `AudienceBookingRules::cancel_strings()`.
         * @return {Promise<boolean>} True when the person agreed.
         */
        confirmRangeCancel: function(options) {
            var opts = options || {};
            var s = opts.strings || {};
            var count = parseInt(opts.count, 10) || 1;
            var label = String(opts.label || '');
            var sentence = count > 1
                ? String(s.range || '').replace('%1$d', count).replace('%2$s', label)
                : String(s.single || '').replace('%s', label);

            return core.overrideConfirm({
                reasons: [sentence],
                strings: s,
                agreeCategory: 'danger'
            });
        },

        /**
         * The override warning (#1859): one dialog listing every rule a
         * booking breaks, with an "I agree" button that stays disabled for a
         * countdown.
         *
         * Audience calendars and personal calendars both open it when a
         * bypass holder books outside the rules. It is the plugin's modal
         * (`.ffc-shortcode.ffc-modal`, appended to `<body>`) carrying a
         * warning notice, so it needs no style of its own. The countdown is
         * shown on the button every second and announced twice through a
         * polite live region — when it starts and when it ends — so a screen
         * reader is told what to wait for without hearing every tick. Cancel,
         * Escape and the backdrop answer at once; focus starts on Cancel,
         * stays inside the dialog, and returns where it was.
         *
         * @param {Object}   options
         * @param {Array}    options.reasons  `{code, message}` entries or plain strings.
         * @param {Object}   [options.strings] Localized words (`OverrideWarning::strings()`).
         * @param {number}   [options.seconds] Countdown length; `strings.seconds`, else 15.
         * @param {string}   [options.agreeCategory] Button category of the confirm; `primary` by default.
         * @return {Promise<boolean>} True when the person agreed.
         */
        overrideConfirm: function(options) {
            var opts = options || {};
            var s = opts.strings || {};
            var seconds = parseInt(opts.seconds || s.seconds, 10);
            if (!(seconds >= 0)) {
                seconds = 15;
            }
            var words = {
                title: s.title || 'Book outside the rules?',
                intro: s.intro || 'This booking breaks the following rules:',
                logged: s.logged || '',
                agree: s.agree || 'I agree',
                agreeIn: s.agreeIn || 'I agree (%d)',
                cancel: s.cancel || 'Cancel',
                countdownStart: s.countdownStart || 'You can confirm in %d seconds.',
                countdownEnd: s.countdownEnd || 'You can confirm now.'
            };
            var reasons = (opts.reasons || []).map(function(r) {
                return (r && typeof r === 'object') ? String(r.message || r.code || '') : String(r);
            }).filter(function(text) { return text !== ''; });

            var uid = 'ffc-override-' + Date.now().toString(36);
            var previous = document.activeElement;

            var dialog = document.createElement('div');
            dialog.className = 'ffc-shortcode ffc-modal ffc-override-dialog';
            dialog.setAttribute('role', 'alertdialog');
            dialog.setAttribute('aria-modal', 'true');
            dialog.setAttribute('aria-labelledby', uid + '-title');
            dialog.setAttribute('aria-describedby', uid + '-body');

            var backdrop = document.createElement('div');
            backdrop.className = 'ffc-modal-backdrop';

            var content = document.createElement('div');
            content.className = 'ffc-modal-content';

            var header = document.createElement('div');
            header.className = 'ffc-modal-header';
            var title = document.createElement('h3');
            title.id = uid + '-title';
            title.textContent = words.title;
            header.appendChild(title);

            var body = document.createElement('div');
            body.className = 'ffc-modal-body';
            body.id = uid + '-body';

            var list = document.createElement('ul');
            list.className = 'ffc-override-reasons';
            reasons.forEach(function(text) {
                var item = document.createElement('li');
                item.textContent = text;
                list.appendChild(item);
            });
            body.appendChild(this.notice('warning', 'warning', words.logged, words.intro, {
                role: 'note',
                hook: 'override-reasons',
                children: [list]
            }));

            var live = document.createElement('p');
            live.className = 'ffc-override-countdown';
            live.setAttribute('role', 'status');
            live.setAttribute('aria-live', 'polite');
            body.appendChild(live);

            var footer = document.createElement('div');
            footer.className = 'ffc-modal-footer';
            var cancel = core.button({
                label: words.cancel,
                category: 'neutral',
                icon: 'cross',
                attrs: { 'data-ffc-override': 'cancel' }
            });
            // The label is the countdown, which label() below rewrites.
            var agree = core.button({
                label: words.agree,
                category: opts.agreeCategory || 'primary',
                icon: 'checkmark',
                attrs: { 'data-ffc-override': 'agree' }
            });
            footer.appendChild(cancel);
            footer.appendChild(agree);

            content.appendChild(header);
            content.appendChild(body);
            content.appendChild(footer);
            dialog.appendChild(backdrop);
            dialog.appendChild(content);
            document.body.appendChild(dialog);

            return new Promise(function(resolve) {
                var left = seconds;
                var timer = null;
                var done = false;

                function label() {
                    agree.textContent = left > 0 ? words.agreeIn.replace('%d', left) : words.agree;
                    agree.disabled = left > 0;
                    agree.setAttribute('aria-disabled', left > 0 ? 'true' : 'false');
                }

                function finish(answer) {
                    if (done) {
                        return;
                    }
                    done = true;
                    if (timer) {
                        clearInterval(timer);
                    }
                    document.removeEventListener('keydown', onKey, true);
                    if (dialog.parentNode) {
                        dialog.parentNode.removeChild(dialog);
                    }
                    if (previous && typeof previous.focus === 'function') {
                        previous.focus();
                    }
                    resolve(answer);
                }

                function onKey(e) {
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        e.stopPropagation();
                        finish(false);
                        return;
                    }
                    if (e.key !== 'Tab') {
                        return;
                    }
                    var focusable = [cancel, agree].filter(function(el) { return !el.disabled; });
                    var first = focusable[0];
                    var last = focusable[focusable.length - 1];
                    if (focusable.indexOf(document.activeElement) === -1) {
                        e.preventDefault();
                        first.focus();
                    } else if (e.shiftKey && document.activeElement === first) {
                        e.preventDefault();
                        last.focus();
                    } else if (!e.shiftKey && document.activeElement === last) {
                        e.preventDefault();
                        first.focus();
                    }
                }

                cancel.addEventListener('click', function() { finish(false); });
                backdrop.addEventListener('click', function() { finish(false); });
                agree.addEventListener('click', function() {
                    if (!agree.disabled) {
                        finish(true);
                    }
                });
                document.addEventListener('keydown', onKey, true);

                label();
                if (left > 0) {
                    live.textContent = words.countdownStart.replace('%d', left);
                    timer = setInterval(function() {
                        left -= 1;
                        label();
                        if (left <= 0) {
                            clearInterval(timer);
                            timer = null;
                            live.textContent = words.countdownEnd;
                        }
                    }, 1000);
                } else {
                    live.textContent = words.countdownEnd;
                }

                cancel.focus();
            });
        },

        /**
         * Set a status line beside a control (#1843): `.ffc-status` plus one
         * state class, a registry icon and the text, announced politely.
         *
         * One look for every "saving… / saved / failed" next to a button or
         * a field, in the admin and on the dashboard. The text lands through
         * `textContent`, so a server message cannot become markup. A call
         * with no state and no text clears the line and hides it.
         *
         * @param {HTMLElement|jQuery|string} target The line, or a selector for it.
         * @param {string} [state] busy | success | error | warning; anything else is plain.
         * @param {string} [text]  The text.
         * @return {HTMLElement|null} The line.
         */
        status: function(target, state, text) {
            var el = target && target.jquery ? target[0] : (typeof target === 'string' ? document.querySelector(target) : target);
            if (!el || !el.classList) {
                return null;
            }
            var icons = { busy: 'sync', success: 'success', error: 'error', warning: 'warning' };
            var known = Object.prototype.hasOwnProperty.call(icons, state);
            var value = (text === null || typeof text === 'undefined') ? '' : String(text);

            el.classList.add('ffc-status');
            el.classList.remove('is-busy', 'is-success', 'is-error', 'is-warning');
            if (!el.hasAttribute('role')) {
                el.setAttribute('role', 'status');
            }
            if (!el.hasAttribute('aria-live')) {
                el.setAttribute('aria-live', 'polite');
            }
            while (el.firstChild) {
                el.removeChild(el.firstChild);
            }

            if (!known && value === '') {
                el.setAttribute('hidden', 'hidden');
                return el;
            }

            if (known) {
                el.classList.add('is-' + state);
                var mark = document.createElement('span');
                mark.className = 'ffc-status-icon ffc-icon-' + icons[state] + ('busy' === state ? ' ffc-icon-spin' : '');
                mark.setAttribute('aria-hidden', 'true');
                el.appendChild(mark);
            }
            var label = document.createElement('span');
            label.className = 'ffc-status-text';
            label.textContent = value;
            el.appendChild(label);
            el.removeAttribute('hidden');
            return el;
        },

        /**
         * Centralized field toggle helper
         *
         * @param {jQuery|string} $trigger - Trigger element (checkbox, radio, select)
         * @param {jQuery|string} $target - Target element(s) to show/hide
         * @param {*} showValue - Value that should show the target (default: true for checkboxes, first option for others)
         * @param {Object} options - Additional options
         * @param {boolean} options.useSlide - Use slideDown/slideUp animation (default: false)
         * @param {number} options.duration - Animation duration in ms (default: 200)
         * @param {boolean} options.invertLogic - Invert the show/hide logic (default: false)
         */
        toggleFields: function($trigger, $target, showValue, options) {
            $trigger = jQuery($trigger);
            $target = jQuery($target);
            options = options || {};

            if ($trigger.length === 0 || $target.length === 0) {
                this.warn('toggleFields: trigger or target not found');
                return;
            }

            var self = this;
            var useSlide = options.useSlide || false;
            var duration = options.duration || 200;
            var invertLogic = options.invertLogic || false;

            // Determine the show value if not provided
            if (typeof showValue === 'undefined') {
                if ($trigger.is(':checkbox')) {
                    showValue = true; // Checked = show
                } else if ($trigger.is('select')) {
                    showValue = $trigger.find('option:first').val();
                }
            }

            // Function to check if target should be visible
            var shouldShow = function() {
                var currentValue;

                if ($trigger.is(':checkbox')) {
                    currentValue = $trigger.is(':checked');
                } else if ($trigger.is(':radio')) {
                    currentValue = $trigger.filter(':checked').val();
                } else {
                    currentValue = $trigger.val();
                }

                var matches = (currentValue === showValue);
                return invertLogic ? !matches : matches;
            };

            // Function to update visibility
            var updateVisibility = function() {
                var show = shouldShow();

                if (useSlide) {
                    if (show) {
                        $target.slideDown(duration);
                    } else {
                        $target.slideUp(duration);
                    }
                } else {
                    $target.toggle(show);
                }

                self.log('toggleFields: visibility updated', { show: show });
            };

            // Bind change event
            $trigger.on('change', updateVisibility);

            // Initial update
            updateVisibility();

            this.log('toggleFields: initialized', {
                trigger: $trigger.length + ' element(s)',
                target: $target.length + ' element(s)',
                showValue: showValue
            });
        },

        /**
         * Enable debug mode
         */
        enableDebug: function() {
            this.config.debug = true;
        },

        /**
         * Disable debug mode
         */
        disableDebug: function() {
            this.config.debug = false;
        }
    };

    // A script printed before this one may already have created the
    // namespace for its own module (`window.FFC = window.FFC || {}`).
    // Keep what it put there, but always install the core API: skipping
    // it because the name was taken left FFC.request undefined and every
    // form submission dead (#1739).
    var prior = window.FFC;
    if (prior && typeof prior === 'object') {
        Object.keys(prior).forEach(function(key) {
            if (!Object.prototype.hasOwnProperty.call(core, key)) {
                core[key] = prior[key];
            }
        });
    }
    window.FFC = core;

    /**
     * Module registry for tracking loaded modules. A module registered
     * before the core loaded keeps its entry.
     */
    window.FFC._modules = Array.isArray(window.FFC._modules) ? window.FFC._modules : [];
    
    /**
     * Register a module
     * 
     * @param {string} name - Module name
     * @param {string} version - Module version
     */
    window.FFC.registerModule = function(name, version) {
        this._modules.push({
            name: name,
            version: version,
            loadedAt: new Date()
        });
    };
    
    /**
     * Get all registered modules
     * 
     * @return {Array} Array of registered modules
     */
    window.FFC.getModules = function() {
        return this._modules;
    };
    
    /**
     * Copy-to-clipboard buttons. Any `.ffc-copy-link` carrying
     * `data-ffc-copy-target="<selector>"` writes the .val() of the matched
     * input to the clipboard, falling back to execCommand where
     * navigator.clipboard is unavailable. Delegated on document, so it is
     * bound once at load and covers markup added later.
     */
    if (typeof jQuery !== 'undefined') {
        jQuery(document).on('click', '.ffc-copy-link[data-ffc-copy-target]', function(e) {
            e.preventDefault();
            var $btn    = jQuery(this);
            var $source = jQuery($btn.data('ffc-copy-target'));
            if (!$source.length) { return; }
            var text = $source.val();
            var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};
            var copiedText = strings.copied || 'Copied!';
            var copyFailedText = strings.copyFailed || 'Copy failed';
            var done = function(ok) {
                var original = $btn.data('ffc-copy-original') || $btn.text();
                $btn.data('ffc-copy-original', original);
                $btn.text(ok ? copiedText : copyFailedText);
                setTimeout(function() { $btn.text(original); }, 1500);
            };
            if (window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText) {
                window.navigator.clipboard.writeText(text).then(
                    function() { done(true); },
                    function() { done(false); }
                );
            } else {
                // Legacy fallback for non-secure contexts / older browsers.
                try {
                    $source[0].select();
                    document.execCommand('copy');
                    done(true);
                } catch (err) {
                    done(false);
                }
            }
        });
    }

    /**
     * Initialize on DOM ready
     */
    if (typeof jQuery === 'undefined') {
        console.warn('[FFC Core] jQuery not found. Some features may not work.');
    } else {
        jQuery(function($) {
            // Delegated confirm dialog for destructive actions
            $(document).on('click', '[data-confirm]', function(e) {
                if (!confirm($(this).attr('data-confirm'))) {
                    e.preventDefault();
                }
            });
        });
    }

})(window);