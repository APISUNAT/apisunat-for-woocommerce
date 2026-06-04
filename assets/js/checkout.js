(function ($) {
    'use strict';

    var cfg = window.apisunatv2_checkout || {};
    var ajaxUrl = cfg.ajax_url || '/wp-admin/admin-ajax.php';
    var nonce = cfg.nonce || '';
    var isBlockCheckout = !!cfg.isBlock || document.querySelector('.wc-block-checkout') !== null;

    var DEBOUNCE_MS = 600;
    var BLOCK_OBSERVER_TIMEOUT_MS = 15000;

    function consultDocument(docType, docNumber) {
        var valid =
            (docType === '6' && /^\d{11}$/.test(docNumber)) ||
            (docType === '1' && /^\d{8}$/.test(docNumber));
        if (!valid) return $.Deferred().resolve(null).promise();

        return $.post(ajaxUrl, {
            action: 'apisunat_consult_document',
            doc_type: docType,
            doc_number: docNumber,
            nonce: nonce
        }).then(function (res) {
            return res && res.success ? res.data : null;
        });
    }

    if (isBlockCheckout) {
        setupBlockCheckout();
    } else {
        setupClassicCheckout();
    }

    function setupClassicCheckout() {
        $(function () {
            var docType = $('#_billing_apisunat_id_type');
            var docNumber = $('#_billing_apisunat_id_number');
            var cpeType = $('#_billing_apisunat_cpe_type');
            var company = $('#billing_company_field');
            var firstName = $('#billing_first_name');
            var lastName = $('#billing_last_name');
            var address1 = $('#billing_address_1');
            var state = $('#billing_state');

            if (!docType.length) return;

            var loading = $('<span class="apisunat-loading" aria-hidden="true"></span>');
            docNumber.parent().append(loading);

            function updatePlaceholders() {
                var t = docType.val();
                docNumber.attr(
                    'placeholder',
                    t === '6' ? 'RUC (11 dígitos)' : t === '1' ? 'DNI (8 dígitos)' : 'Número de documento'
                );
            }

            function toggleCompany() {
                var required = cpeType.val() === '01';
                company.toggle(required);
                company.find('input').prop('required', required);
            }

            var debounce;
            function consult() {
                var t = docType.val();
                var n = $.trim(docNumber.val());
                if (!t || !n) return;
                clearTimeout(debounce);
                debounce = setTimeout(function () {
                    loading.show();
                    consultDocument(t, n).always(function () {
                        loading.hide();
                    }).then(function (d) {
                        if (!d) return;
                        if (d.name) {
                            if (t === '6') {
                                company.find('input').val(d.name).trigger('change');
                            } else {
                                var p = d.name.split(' ');
                                firstName.val(p.shift()).trigger('change');
                                lastName.val(p.join(' ')).trigger('change');
                            }
                        }
                        if (d.address) address1.val(d.address).trigger('change');
                        if (d.state) state.val(d.state).trigger('change');
                    });
                }, DEBOUNCE_MS);
            }

            docType.on('change', function () { updatePlaceholders(); consult(); });
            cpeType.on('change', toggleCompany);
            docNumber.on('input', consult);
            updatePlaceholders();
            toggleCompany();
        });
    }

    function setupBlockCheckout() {
        var debounce;
        var observer;
        var bound = false;

        var FIELD_IDS = {
            docType: 'apisunat/sunat_id_type',
            docNum:  'apisunat/sunat_id_number',
        };

        function findElement(nameOrId) {
            // Try by name, data-key, and id attributes
            var el =
                document.querySelector('[name="' + nameOrId + '"]') ||
                document.querySelector('[name="order[additional][' + nameOrId + ']"]') ||
                document.querySelector('[data-key="' + nameOrId + '"] input, [data-key="' + nameOrId + '"] select') ||
                document.getElementById('order-' + nameOrId.replace(/\//g, '-'));
            return el;
        }

        function getFieldValue(fieldId) {
            var el = findElement(fieldId);
            return el ? el.value : null;
        }

        function setNativeValue(el, value) {
            var proto = el instanceof HTMLSelectElement
                ? window.HTMLSelectElement.prototype
                : window.HTMLInputElement.prototype;
            var setter = Object.getOwnPropertyDescriptor(proto, 'value');
            if (setter && setter.set) {
                setter.set.call(el, value);
            } else {
                el.value = value;
            }
            el.dispatchEvent(new Event('input', { bubbles: true }));
        }

        function setFieldValue(fieldId, value) {
            var el = findElement(fieldId);
            if (!el) {
                var mapped = fieldId.replace('billing_', '');
                el = findElement(mapped);
            }
            if (el) {
                setNativeValue(el, value);
            }
        }

        function consultHandler() {
            var type = getFieldValue(FIELD_IDS.docType);
            var num = getFieldValue(FIELD_IDS.docNum);
            if (!type || !num) return;
            clearTimeout(debounce);
            debounce = setTimeout(function () {
                consultDocument(type, num).then(function (d) {
                    if (!d) return;
                    if (d.name) {
                        if (type === '6') {
                            setFieldValue('billing_company', d.name);
                        } else {
                            var parts = d.name.split(' ');
                            setFieldValue('billing_first_name', parts.shift());
                            setFieldValue('billing_last_name', parts.join(' '));
                        }
                    }
                    if (d.address) setFieldValue('billing_address_1', d.address);
                    if (d.state) setFieldValue('billing_state', d.state);
                });
            }, DEBOUNCE_MS);
        }

        function tryBind() {
            if (bound) return true;
            var docTypeEl = findElement(FIELD_IDS.docType);
            var docNumEl = findElement(FIELD_IDS.docNum);
            if (docTypeEl && docNumEl) {
                docTypeEl.addEventListener('change', consultHandler);
                docNumEl.addEventListener('input', consultHandler);
                bound = true;
                return true;
            }
            return false;
        }

        if (tryBind()) return;

        observer = new MutationObserver(function () {
            if (tryBind() && observer) {
                observer.disconnect();
                observer = null;
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });

        setTimeout(function () {
            if (observer) {
                observer.disconnect();
                observer = null;
            }
        }, BLOCK_OBSERVER_TIMEOUT_MS);
    }
})(jQuery);
