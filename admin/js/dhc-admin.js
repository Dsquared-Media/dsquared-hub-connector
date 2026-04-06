/**
 * Dsquared Hub Connector — Admin JavaScript
 * SVG-free version: uses Dashicons to avoid SVG Support plugin conflicts
 */
(function($) {
    'use strict';

    // Tab switching
    $(document).on('click', '.dhc-tab', function() {
        var tab = $(this).data('tab');
        $('.dhc-tab').removeClass('active');
        $(this).addClass('active');
        $('.dhc-tab-content').removeClass('active');
        $('#tab-' + tab).addClass('active');
    });

    // Toggle API key visibility
    $(document).on('click', '#dhc-toggle-key', function() {
        var input = $('#dhc-api-key');
        var icon = $(this).find('.dashicons');
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.css('color', '#5661ff');
        } else {
            input.attr('type', 'password');
            icon.css('color', '');
        }
    });

    // Save & Validate API key
    $(document).on('click', '#dhc-save-key', function() {
        var btn = $(this);
        var status = $('#dhc-key-status');
        var apiKey = $('#dhc-api-key').val().trim();

        if (!apiKey) {
            status.text('Please enter an API key.').removeClass('success').addClass('error');
            return;
        }

        btn.prop('disabled', true).text('Validating...');
        status.text('').removeClass('success error');

        $.post(dhcAdmin.ajaxUrl, {
            action: 'dhc_validate_key',
            nonce: dhcAdmin.nonce,
            api_key: apiKey
        }, function(response) {
            btn.prop('disabled', false).text('Save & Validate');
            if (response.success) {
                status.text(response.data.message || 'Connected!').addClass('success').removeClass('error');
                // Reload after 1.5s to update the page
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                status.text(response.data || 'Validation failed.').addClass('error').removeClass('success');
            }
        }).fail(function() {
            btn.prop('disabled', false).text('Save & Validate');
            status.text('Network error. Please try again.').addClass('error').removeClass('success');
        });
    });

    // Save module settings
    $(document).on('click', '#dhc-save-modules', function() {
        var btn = $(this);
        var status = $('#dhc-modules-status');
        var modules = {};

        $('.dhc-module-toggle').each(function() {
            modules[$(this).data('module')] = $(this).is(':checked') ? 1 : 0;
        });

        btn.prop('disabled', true).text('Saving...');
        status.text('').removeClass('success error');

        $.post(dhcAdmin.ajaxUrl, {
            action: 'dhc_save_settings',
            nonce: dhcAdmin.nonce,
            api_key: $('#dhc-api-key').val().trim(),
            modules: modules
        }, function(response) {
            btn.prop('disabled', false).text('Save Module Settings');
            if (response.success) {
                status.text('Settings saved!').addClass('success').removeClass('error');
                setTimeout(function() { status.text(''); }, 3000);
            } else {
                status.text(response.data || 'Save failed.').addClass('error').removeClass('success');
            }
        }).fail(function() {
            btn.prop('disabled', false).text('Save Module Settings');
            status.text('Network error.').addClass('error').removeClass('success');
        });
    });

    // Save AI Discovery business profile
    $(document).on('click', '#dhc-save-ai-discovery', function() {
        var btn = $(this);
        var status = $('#dhc-ai-discovery-status');

        btn.prop('disabled', true).text('Saving & Generating...');
        status.text('').removeClass('success error');

        $.post(dhcAdmin.ajaxUrl, {
            action: 'dhc_save_ai_discovery',
            nonce: dhcAdmin.nonce,
            business_name: $('#dhc-biz-name').val(),
            description: $('#dhc-biz-desc').val(),
            services_text: $('#dhc-biz-services').val(),
            phone: $('#dhc-biz-phone').val(),
            email: $('#dhc-biz-email').val(),
            address: $('#dhc-biz-address').val(),
            service_areas_text: $('#dhc-biz-areas').val(),
            hours: $('#dhc-biz-hours').val(),
            extra_info: $('#dhc-biz-extra').val()
        }, function(response) {
            btn.prop('disabled', false).text('Save & Generate Files');
            if (response.success) {
                status.text(response.data || 'Profile saved and files generated!').addClass('success').removeClass('error');
                setTimeout(function() { status.text(''); }, 4000);
            } else {
                status.text(response.data || 'Save failed.').addClass('error').removeClass('success');
            }
        }).fail(function() {
            btn.prop('disabled', false).text('Save & Generate Files');
            status.text('Network error.').addClass('error').removeClass('success');
        });
    });

    // Refresh Connection (re-validate API key) — uses Dashicons spinner instead of SVG
    $(document).on('click', '#dhc-refresh-connection', function() {
        var btn = $(this);
        var originalHtml = btn.html();
        btn.prop('disabled', true).html('<span class="dashicons dashicons-update dhc-spin" style="font-size:14px;width:14px;height:14px;line-height:14px;"></span> Refreshing...');

        var apiKey = $('#dhc-api-key').val() || '';
        if (!apiKey) {
            apiKey = $('input[type="password"]').first().val() || '';
        }

        $.post(dhcAdmin.ajaxUrl, {
            action: 'dhc_validate_key',
            nonce: dhcAdmin.nonce,
            api_key: apiKey.trim()
        }, function(response) {
            btn.prop('disabled', false).html(originalHtml);
            if (response.success) {
                // Reload to show updated status
                location.reload();
            } else {
                alert('Refresh failed: ' + (response.data || 'Could not validate key.'));
            }
        }).fail(function() {
            btn.prop('disabled', false).html(originalHtml);
            alert('Network error. Please try again.');
        });
    });

    // Clear activity log
    $(document).on('click', '#dhc-clear-log', function() {
        if (!confirm('Clear all activity log entries?')) return;

        var btn = $(this);
        btn.prop('disabled', true).text('Clearing...');

        $.post(dhcAdmin.ajaxUrl, {
            action: 'dhc_clear_activity_log',
            nonce: dhcAdmin.nonce
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                btn.prop('disabled', false).text('Clear Log');
                alert('Failed to clear log.');
            }
        });
    });

})(jQuery);
