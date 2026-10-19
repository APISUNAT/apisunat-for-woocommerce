(function ($) {
    'use strict';

    var cfg = window.apisunatv2_admin || {};
    var ajaxUrl = cfg.ajax_url;
    var nonce = cfg.nonce;
    var i18n = cfg.i18n || {};

    if (!ajaxUrl) return;

    $(function () {
        var $syncBtn = $('#sync-pending-btn');
        var $statPending = $('#stat-pending');
        var $statAccepted = $('#stat-accepted');
        var $statApi = $('#stat-api');

        // Test API
        $(document).on('click', '.apisunat-test-api', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $creds = $btn.closest('.apisunat-credentials');
            var personaId = $creds.find('input[name*="[personaId]"]').val();
            var token = $creds.find('input[name*="[personaToken]"]').val();
            var $result = $creds.find('.apisunat-api-result');

            if (!personaId || !token) {
                $result.removeClass('success error').addClass('error').text(i18n.credsMissing || '');
                return;
            }

            $btn.prop('disabled', true);
            $result.removeClass('success error').text(i18n.verifying || '');

            $.post(ajaxUrl, {
                action: 'apisunat_test_api',
                nonce: nonce,
                personaId: personaId,
                personaToken: token
            }).done(function (res) {
                if (res && res.success) {
                    var msg = res.data.message + ' - ' + (res.data.persona || '');
                    $result.removeClass('error').addClass('success').text(msg);
                } else {
                    var err = (res && res.data && res.data.message) || i18n.connError || '';
                    $result.removeClass('success').addClass('error').text(err);
                }
            }).fail(function () {
                $result.removeClass('success').addClass('error').text(i18n.connError || '');
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });

        function applyStats(data) {
            if (!data) return;
            $statPending.text(typeof data.pendiente !== 'undefined' ? data.pendiente : '—');
            $statAccepted.text(typeof data.aceptado !== 'undefined' ? data.aceptado : '—');
            $statApi.text('OK');
        }

        function fetchStats(showSyncing) {
            if (showSyncing) {
                $syncBtn.prop('disabled', true).text(i18n.syncing || '');
            }
            $.post(ajaxUrl, {
                action: 'apisunat_sync_pending',
                nonce: nonce
            }).done(function (res) {
                if (res && res.success) {
                    applyStats(res.data);
                } else {
                    $statApi.text('ERR').addClass('apisunat-stat-error');
                }
            }).fail(function () {
                $statApi.text('ERR').addClass('apisunat-stat-error');
            }).always(function () {
                if (showSyncing) {
                    $syncBtn.prop('disabled', false).text(i18n.sync || '');
                }
            });
        }

        $syncBtn.on('click', function (e) {
            e.preventDefault();
            fetchStats(true);
        });

        var $sendPendingBtn = $('#send-pending-btn');
        var $sendResult = $('#send-pending-result');

        $sendPendingBtn.on('click', function (e) {
            e.preventDefault();
            var $btn = $(this);
            $btn.prop('disabled', true);
            $sendResult.text(i18n.sending || '');

            $.post(ajaxUrl, {
                action: 'apisunat_send_pending',
                nonce: nonce
            }).done(function (res) {
                if (res && res.success) {
                    $sendResult.removeClass('error').addClass('success').text(res.data.message);
                    applyStats(res.data);
                } else {
                    var err = (res && res.data && res.data.message) || i18n.connError || '';
                    $sendResult.removeClass('success').addClass('error').text(err);
                }
            }).fail(function () {
                $sendResult.removeClass('success').addClass('error').text(i18n.connError || '');
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });

        // Enable taxes
        $(document).on('click', '#apisunat-enable-tax', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $result = $('#apisunat-enable-tax-result');
            $btn.prop('disabled', true);
            $result.text(i18n.verifying || '');

            $.post(ajaxUrl, {
                action: 'apisunat_enable_tax',
                nonce: nonce
            }).done(function (res) {
                if (res && res.success) {
                    $result.removeClass('error').addClass('success').text(res.data.message);
                    setTimeout(function () { location.reload(); }, 1000);
                } else {
                    $result.removeClass('success').addClass('error').text((res && res.data && res.data.message) || i18n.connError || '');
                }
            }).fail(function () {
                $result.removeClass('success').addClass('error').text(i18n.connError || '');
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });

        // Create tax classes
        $(document).on('click', '#apisunat-create-classes', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $result = $('#apisunat-classes-result');
            $btn.prop('disabled', true);
            $result.text(i18n.verifying || '');

            $.post(ajaxUrl, {
                action: 'apisunat_create_tax_classes',
                nonce: nonce
            }).done(function (res) {
                if (res && res.success) {
                    $result.removeClass('error').addClass('success').text(res.data.message);
                    setTimeout(function () { location.reload(); }, 1000);
                } else {
                    $result.removeClass('success').addClass('error').text((res && res.data && res.data.message) || i18n.connError || '');
                }
            }).fail(function () {
                $result.removeClass('success').addClass('error').text(i18n.connError || '');
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });

        // Delete tax rate
        $(document).on('click', '.apisunat-delete-tax', function (e) {
            e.preventDefault();
            var $btn = $(this);
            if (!confirm(i18n.confirmDelete || '¿Eliminar esta tasa?')) {
                return;
            }
            var rateId = $btn.data('rate-id');
            $btn.prop('disabled', true);

            $.post(ajaxUrl, {
                action: 'apisunat_delete_tax_rate',
                nonce: nonce,
                rate_id: rateId
            }).done(function (res) {
                if (res && res.success) {
                    location.reload();
                } else {
                    alert((res && res.data && res.data.message) || i18n.connError || '');
                    $btn.prop('disabled', false);
                }
            }).fail(function () {
                alert(i18n.connError || '');
                $btn.prop('disabled', false);
            });
        });

        $(document).on('click', '.apisunat-toggle-token', function (e) {
            e.preventDefault();
            var $input = $(this).siblings('.apisunat-token-input');
            if (!$input.length) {
                $input = $(this).closest('.apisunat-credentials').find('.apisunat-token-input');
            }
            if (!$input.length) return;
            $input.attr('type', $input.attr('type') === 'password' ? 'text' : 'password');
        });

        // Toggle multi_branch_key visibility based on multi_branch checkbox
        var $multiBranchCb = $('#apisunatv2-settings-multi_branch');
        var $multiBranchKeyRow = $('label[for="apisunatv2-settings-multi_branch_key"]').closest('tr');
        if ($multiBranchCb.length && $multiBranchKeyRow.length) {
            $multiBranchKeyRow.toggle($multiBranchCb.is(':checked'));
            $multiBranchCb.on('change', function () {
                $multiBranchKeyRow.toggle(this.checked);
            });
        }

        fetchStats(false);
    });
})(jQuery);
