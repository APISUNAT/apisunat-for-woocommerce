(function ($) {
    'use strict';

    var cfg = window.apisunatv2_ajax || {};
    var ajaxUrl = cfg.ajax_url;
    var nonce = cfg.nonce;
    var i18n = cfg.i18n || {};

    if (!ajaxUrl) return;

    function postAction(payload) {
        return $.post(ajaxUrl, $.extend({ nonce: nonce }, payload));
    }

    function reportError(res) {
        var msg = (res && res.data && res.data.message) || i18n.genericError || 'Error';
        window.alert(msg);
    }

    $(document).on('click', '.sunat-emit-btn:not([disabled])', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var orderId = $btn.data('order');
        var label = $btn.text();

        $btn.prop('disabled', true).text(i18n.sending || '');

        var payload = {
            action: 'apisunat_emit_cpe',
            order_id: orderId,
            nonce: nonce,
            _billing_apisunat_detraction_enabled: $('#_billing_apisunat_detraction_enabled').is(':checked') ? '1' : '0',
            _billing_apisunat_detraction_payment_method: $('#_billing_apisunat_detraction_payment_method').val() || '',
            _billing_apisunat_detraction_percentage: $('#_billing_apisunat_detraction_percentage').val() || ''
        };

        postAction(payload)
            .done(function (res) {
                if (res && res.success) {
                    window.location.reload();
                } else {
                    reportError(res);
                    $btn.prop('disabled', false).text(label || i18n.emit || '');
                }
            })
            .fail(function () {
                $btn.prop('disabled', false).text(label || i18n.emit || '');
            });
    });

    $(document).on('click', '#sunatCheckBtn', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var orderId = $btn.data('order');
        var label = $btn.text();

        $btn.prop('disabled', true).text(i18n.verifying || '');

        postAction({ action: 'apisunat_check_status', order_id: orderId })
            .done(function (res) {
                if (res && res.success) {
                    window.location.reload();
                } else {
                    reportError(res);
                    $btn.prop('disabled', false).text(label || i18n.verify || '');
                }
            })
            .fail(function () {
                $btn.prop('disabled', false).text(label || i18n.verify || '');
            });
    });

    $(document).on('click', '#sunatVoidBtn', function (e) {
        e.preventDefault();
        $('#sunatVoidReason').slideToggle();
    });

    $(document).on('click', '#sunatConfirmVoid', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var orderId = $('#sunatVoidBtn').data('order');
        var reason = $.trim($('#sunatReasonInput').val());
        var label = $btn.text();

        if (reason.length < 3) {
            window.alert(i18n.reasonShort || '');
            return;
        }

        $btn.prop('disabled', true).text(i18n.voiding || '');

        postAction({ action: 'apisunat_void_order', order_id: orderId, reason: reason })
            .done(function (res) {
                if (res && res.success) {
                    window.location.reload();
                } else {
                    reportError(res);
                    $btn.prop('disabled', false).text(label || i18n.confirm || '');
                }
            })
            .fail(function () {
                $btn.prop('disabled', false).text(label || i18n.confirm || '');
            });
    });
})(jQuery);
