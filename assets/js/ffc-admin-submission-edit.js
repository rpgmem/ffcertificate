/**
 * FFC Admin Submission Edit
 *
 * JavaScript for submission edit page
 *
 * @since 3.1.0
 * @since 4.3.0 Added user search, unlink confirmation, collapsible consent
 */

jQuery(document).ready(function($) {
    'use strict';

    // Copy magic link to clipboard
    $('.ffc-copy-magic-link').on('click', function(e) {
        e.preventDefault();
        var url = $(this).data('url');
        var $btn = $(this);

        var $temp = $('<input>');
        $('body').append($temp);
        $temp.val(url).select();
        document.execCommand('copy');
        $temp.remove();

        var originalText = $btn.text();
        $btn.text(ffc_submission_edit.copied_text).prop('disabled', true);

        setTimeout(function() {
            $btn.text(originalText).prop('disabled', false);
        }, 2000);
    });

    // ========================================
    // Reveal PII (#739 §3.3) - masked CPF/RF, click to reveal (audited)
    // ========================================
    $('.ffc-reveal-pii').on('click', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var field = $btn.data('field');
        var $input = $btn.closest('td').find('[data-ffc-pii-field="' + field + '"]');

        $btn.prop('disabled', true);

        FFC.request(
            'ffc_reveal_pii',
            { submission_id: $btn.data('submission-id'), field: field },
            { nonce: $btn.data('nonce') }
        )
            .then(function (data) {
                if (data && typeof data.value !== 'undefined') {
                    $input.val(data.value);
                    $btn.remove();
                } else {
                    $btn.prop('disabled', false);
                }
            })
            .catch(function (err) {
                $btn.prop('disabled', false);
                alert((err && err.message) || ffc_submission_edit.reveal_error || 'Unable to reveal this value.');
            });
    });

    // ========================================
    // Unlink User Button - Confirmation
    // ========================================
    $('.ffc-unlink-user-btn').on('click', function(e) {
        e.preventDefault();
        var confirmMsg = $(this).data('confirm');

        if (confirm(confirmMsg)) {
            // Set the hidden input to empty (unlink)
            $('input[name="linked_user_id"]').val('');
            // Submit the form
            $(this).closest('form').submit();
        }
    });

    // ========================================
    // User Search - AJAX
    // ========================================
    var $searchInput = $('#ffc-user-search-input');
    var $searchBtn = $('.ffc-search-user-btn');
    var $spinner = $('#ffc-search-spinner');
    var $resultsContainer = $('#ffc-user-search-results');
    var $selectedPreview = $('#ffc-selected-user-preview');
    var $selectedUserId = $('#ffc-selected-user-id');

    // Search button click
    $searchBtn.on('click', function() {
        performUserSearch();
    });

    // Enter key in search input
    $searchInput.on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            performUserSearch();
        }
    });

    function performUserSearch() {
        var searchTerm = $searchInput.val().trim();

        if (searchTerm.length < 2) {
            alert(ffc_submission_edit.search_min_chars || 'Please enter at least 2 characters.');
            return;
        }

        $spinner.addClass('is-active');
        $resultsContainer.hide();
        $selectedPreview.hide();

        FFC.request('ffc_search_user', { search: searchTerm }, { nonce: $searchBtn.data('nonce') })
            .then(function (data) {
                $spinner.removeClass('is-active');
                if (data && data.users) {
                    displaySearchResults(data.users);
                } else {
                    displayNoResults(ffc_submission_edit.no_users_found || 'No users found.');
                }
            })
            .catch(function (err) {
                $spinner.removeClass('is-active');
                if (err && err.fromServer) {
                    displayNoResults(err.message || ffc_submission_edit.no_users_found || 'No users found.');
                } else {
                    alert(ffc_submission_edit.search_error || 'Error searching for users. Please try again.');
                }
            });
    }

    function displaySearchResults(users) {
        var html = '';

        users.forEach(function(user) {
            html += '<div class="ffc-search-result-item" data-user-id="' + user.id + '" data-display-name="' + escapeHtml(user.display_name) + '" data-email="' + escapeHtml(user.email) + '" data-avatar="' + escapeHtml(user.avatar) + '">';
            html += '<img src="' + escapeHtml(user.avatar) + '" alt="" width="32" height="32">';
            html += '<div class="ffc-search-result-info">';
            html += '<span class="ffc-search-result-name">' + escapeHtml(user.display_name) + '</span>';
            html += '<span class="ffc-search-result-email">' + escapeHtml(user.email) + '</span>';
            html += '<span class="ffc-search-result-id">ID: ' + user.id + '</span>';
            html += '</div>';
            html += '</div>';
        });

        $resultsContainer.html(html).show();
    }

    function displayNoResults(message) {
        $resultsContainer.html('<div class="ffc-no-results">' + escapeHtml(message) + '</div>').show();
    }

    // Select user from results
    $(document).on('click', '.ffc-search-result-item', function() {
        var userId = $(this).data('user-id');
        var displayName = $(this).data('display-name');
        var email = $(this).data('email');
        var avatar = $(this).data('avatar');

        // Set the hidden input value
        $selectedUserId.val(userId);

        // Show selected user preview
        var previewHtml = '<img src="' + escapeHtml(avatar) + '" alt="" width="32" height="32">';
        previewHtml += '<div class="ffc-selected-info">';
        previewHtml += '<strong>' + escapeHtml(displayName) + '</strong> ';
        previewHtml += '<span class="ffc-user-email">(' + escapeHtml(email) + ')</span> ';
        previewHtml += '<span class="ffc-user-id">ID: ' + userId + '</span>';
        previewHtml += '</div>';
        previewHtml += '<span class="ffc-clear-selection">' + (ffc_submission_edit.clear_selection || 'Clear') + '</span>';

        $selectedPreview.html(previewHtml).show();
        $resultsContainer.hide();
        $searchInput.val('');
    });

    // Clear selection
    $(document).on('click', '.ffc-clear-selection', function() {
        $selectedUserId.val('');
        $selectedPreview.hide();
    });

    // ========================================
    // Collapsible Consent Section
    // ========================================
    $('.ffc-consent-header').on('click keypress', function(e) {
        // Handle click or Enter/Space key
        if (e.type === 'keypress' && e.which !== 13 && e.which !== 32) {
            return;
        }

        e.preventDefault();
        var $box = $(this).closest('.ffc-consent-box');
        var $details = $box.find('.ffc-consent-details');
        var isOpen = $box.hasClass('is-open');

        if (isOpen) {
            $details.slideUp(200);
            $box.removeClass('is-open');
            $(this).attr('aria-expanded', 'false');
        } else {
            $details.slideDown(200);
            $box.addClass('is-open');
            $(this).attr('aria-expanded', 'true');
        }
    });

    // ========================================
    // Helper Functions
    // ========================================
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }
});
