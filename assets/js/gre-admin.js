(function ($) {
    'use strict';

    var cfg = window.apisunatv2_ajax || {};
    var ubigeo = window.apisunatv2_ubigeo || { departments: [], provinces: {}, districts: {} };
    var ajaxUrl = cfg.ajax_url;
    var nonce = cfg.nonce;
    var i18n = cfg.i18n || {};

    if (!ajaxUrl) return;

    var $container = $('#sunat-gre-container');
    if (!$container.length) return;

    var orderId = $container.data('order');

    function postAction(payload) {
        return $.post(ajaxUrl, $.extend({ nonce: nonce }, payload));
    }

    function collectFormData() {
        function val(id) { return $('#' + id).val() || ''; }
        function valArr(prefix) {
            var result = [];
            $('[class*="' + prefix + '"]').each(function () {
                result.push($(this).val() || '');
            });
            return result;
        }

        var vehiculoPlacas = [], vehiculoAutorizaciones = [], vehiculoEntidades = [];
        $('#gre-vehiculos-list .gre-vehiculo-item').each(function () {
            vehiculoPlacas.push($(this).find('.gre-veh-placa').val() || '');
            vehiculoAutorizaciones.push($(this).find('.gre-veh-autorizacion').val() || '');
            vehiculoEntidades.push($(this).find('.gre-veh-entidad').val() || '');
        });

        var conductorTipoDoc = [], conductorNumDoc = [], conductorNombres = [], conductorApellidos = [], conductorLicencias = [];
        $('#gre-conductores-list .gre-conductor-item').each(function () {
            conductorTipoDoc.push($(this).find('.gre-con-tipo-doc').val() || '');
            conductorNumDoc.push($(this).find('.gre-con-num-doc').val() || '');
            conductorNombres.push($(this).find('.gre-con-nombres').val() || '');
            conductorApellidos.push($(this).find('.gre-con-apellidos').val() || '');
            conductorLicencias.push($(this).find('.gre-con-licencia').val() || '');
        });

        var itemNombres = [], itemCantidades = [], itemUnidades = [];
        $('#gre-items-list .gre-item-row').each(function () {
            itemNombres.push($(this).find('.gre-item-nombre').val() || '');
            itemCantidades.push($(this).find('.gre-item-cantidad').val() || '1');
            itemUnidades.push($(this).find('.gre-item-unidad').val() || 'ZZ');
        });

        return {
            action: 'apisunat_gre_save_draft',
            order_id: orderId,
            fecha_traslado: val('gre_fecha_traslado'),
            modalidad_transporte: val('gre_modalidad_transporte'),
            vehiculo_categoria: $('#gre_vehiculo_categoria').is(':checked') ? '1' : '',
            customize_items: $('#gre_customize_items').is(':checked') ? '1' : '',
            motivo_traslado: val('gre_motivo_traslado'),
            partida_departamento: val('gre_partida_departamento'),
            partida_provincia: val('gre_partida_provincia'),
            partida_distrito: val('gre_partida_distrito'),
            partida_ubigeo: (val('gre_partida_departamento') + val('gre_partida_provincia') + val('gre_partida_distrito')).slice(-6),
            partida_direccion: val('gre_partida_direccion'),
            llegada_departamento: val('gre_llegada_departamento'),
            llegada_provincia: val('gre_llegada_provincia'),
            llegada_distrito: val('gre_llegada_distrito'),
            llegada_ubigeo: (val('gre_llegada_departamento') + val('gre_llegada_provincia') + val('gre_llegada_distrito')).slice(-6),
            llegada_direccion: val('gre_llegada_direccion'),
            transportista_nombre: val('gre_transportista_nombre'),
            transportista_ruc: val('gre_transportista_ruc'),
            transportista_mtc: val('gre_transportista_mtc'),
            vehiculo_placa: vehiculoPlacas,
            vehiculo_autorizacion: vehiculoAutorizaciones,
            vehiculo_entidad: vehiculoEntidades,
            conductor_tipo_doc: conductorTipoDoc,
            conductor_num_doc: conductorNumDoc,
            conductor_nombres: conductorNombres,
            conductor_apellidos: conductorApellidos,
            conductor_licencia: conductorLicencias,
            item_nombre: itemNombres,
            item_cantidad: itemCantidades,
            item_unidad: itemUnidades,
            peso_bruto: val('gre_peso_bruto'),
            observaciones: val('gre_observaciones'),
        };
    }

    function loadOrderData() {
        var $spinner = $('#sunat-gre-spinner');
        var $fields = $('#sunat-gre-fields');
        $spinner.show();
        $fields.hide();

        postAction({ action: 'apisunat_gre_get_order_data', order_id: orderId })
            .done(function (res) {
                if (res && res.success && res.data) {
                    var d = res.data;
                    if (d.partida_direccion && !$('#gre_partida_direccion').val()) {
                        $('#gre_partida_direccion').val(d.partida_direccion);
                    }
                    if (d.llegada_direccion && !$('#gre_llegada_direccion').val()) {
                        $('#gre_llegada_direccion').val(d.llegada_direccion);
                    }
                    if (d.items && d.items.length && !$('#gre-items-list .gre-item-row').length) {
                        $.each(d.items, function (i, item) {
                            addItemRow(item.nombre, item.cantidad, item.unidad);
                        });
                    }
                }
            })
            .always(function () {
                $spinner.hide();
                $fields.show();
            });
    }

    function reportError(res) {
        var msg = (res && res.data && res.data.message) || i18n.error || 'Error';
        window.alert(msg);
    }

    function initUbigeoSelects() {
        $('.gre-ubigeo-dep').each(function () {
            var $sel = $(this);
            var currentVal = $sel.data('saved') || '';
            $sel.find('option:not(:first)').remove();
            $.each(ubigeo.departments, function (i, dep) {
                $sel.append($('<option>', { value: dep.code, text: dep.name }));
            });
            if (currentVal) {
                $sel.val(currentVal).trigger('change');
            }
        });
    }

    function restoreUbigeo() {
        $('.gre-ubigeo-dep').each(function () {
            var $dep = $(this);
            var depVal = $dep.data('saved') || '';
            if (!depVal) return;
            var prefix = $dep.attr('id').indexOf('partida') !== -1 ? 'partida' : 'llegada';
            var provSaved = $('#gre_' + prefix + '_provincia').data('saved') || '';
            if (provSaved) {
                setTimeout(function () {
                    $('#gre_' + prefix + '_provincia').val(provSaved).trigger('change');
                    var distSaved = $('#gre_' + prefix + '_distrito').data('saved') || '';
                    if (distSaved) {
                        setTimeout(function () {
                            $('#gre_' + prefix + '_distrito').val(distSaved);
                        }, 100);
                    }
                }, 100);
            }
        });
    }

    $(document).on('change', '.gre-ubigeo-dep', function () {
        var depCode = $(this).val();
        var $row = $(this).closest('#sunat-gre-fields').length ? $(this).closest('div[id]') : $(this).parent();
        var $provSel, $distSel;

        if ($(this).attr('id').indexOf('partida') !== -1) {
            $provSel = $('#gre_partida_provincia');
            $distSel = $('#gre_partida_distrito');
        } else {
            $provSel = $('#gre_llegada_provincia');
            $distSel = $('#gre_llegada_distrito');
        }

        $provSel.find('option:not(:first)').remove();
        $distSel.find('option:not(:first)').remove();

        if (depCode && ubigeo.provinces[depCode]) {
            $.each(ubigeo.provinces[depCode], function (i, prov) {
                $provSel.append($('<option>', { value: prov.code, text: prov.name }));
            });
        }
    });

    $(document).on('change', '.gre-ubigeo-prov', function () {
        var provCode = $(this).val();
        var $distSel;

        if ($(this).attr('id').indexOf('partida') !== -1) {
            $distSel = $('#gre_partida_distrito');
        } else {
            $distSel = $('#gre_llegada_distrito');
        }

        $distSel.find('option:not(:first)').remove();

        if (provCode && ubigeo.districts[provCode]) {
            $.each(ubigeo.districts[provCode], function (i, dist) {
                $distSel.append($('<option>', { value: dist.code.slice(-2), text: dist.name }));
            });
        }
    });

    var nextItemIndex = $('#gre-items-list .gre-item-row').length;
    function addItemRow(nombre, cantidad, unidad) {
        var idx = nextItemIndex++;
        var html = '<div class="gre-item-row" data-index="' + idx + '">' +
            '<p class="form-field form-field-wide"><label>' + i18n.product + '</label><input type="text" class="gre-field gre-item-nombre" value="' + (nombre || '') + '"></p>' +
            '<p class="form-field form-field-wide"><label>' + i18n.quantity + '</label><input type="number" class="gre-field gre-item-cantidad" value="' + (cantidad || '1') + '" min="1" step="1"></p>' +
            '<p class="form-field form-field-wide"><label>' + i18n.unit + '</label><input type="text" class="gre-field gre-item-unidad" value="' + (unidad || 'ZZ') + '" placeholder="ZZ"></p>' +
            '<button type="button" class="button gre-remove-item">' + i18n.remove + '</button><hr></div>';
        $('#gre-items-list').append(html);
    }

    $(document).on('click', '#gre-add-item', function (e) {
        e.preventDefault();
        addItemRow('', '1', 'ZZ');
    });

    $(document).on('click', '.gre-remove-item', function () {
        $(this).closest('.gre-item-row').remove();
    });

    function addVehiculoRow(placa, autorizacion, entidad) {
        var idx = Date.now();
        var html = '<div class="gre-vehiculo-item" data-index="' + idx + '">' +
            '<p class="form-field form-field-wide"><label>' + i18n.plate + '</label><input type="text" class="gre-field gre-veh-placa" value="' + (placa || '') + '"></p>' +
            '<p class="form-field form-field-wide"><label>' + i18n.authorization + '</label><input type="text" class="gre-field gre-veh-autorizacion" value="' + (autorizacion || '') + '"></p>' +
            '<p class="form-field form-field-wide"><label>' + i18n.entity + '</label><select class="gre-field gre-veh-entidad">' + entidadOptions(entidad) + '</select></p>' +
            '<button type="button" class="button gre-remove-vehiculo">' + i18n.remove + '</button><hr></div>';
        $('#gre-vehiculos-list').append(html);
    }

    function entidadOptions(selected) {
        var opts = {
            '01': 'SUCAMEC', '02': 'DIGEMID', '03': 'DIGESA', '04': 'SENASA',
            '05': 'SERFOR', '06': 'MTC', '07': 'PRODUCE', '08': 'MIN. AMBIENTE',
            '09': 'SANIPES', '10': 'MML', '11': 'MINSA', '12': 'GR'
        };
        var html = '<option value="">Seleccionar...</option>';
        $.each(opts, function (v, l) {
            html += '<option value="' + v + '" ' + (selected === v ? 'selected' : '') + '>' + l + '</option>';
        });
        return html;
    }

    function addConductorRow(tipoDoc, numDoc, nombres, apellidos, licencia) {
        var idx = Date.now();
        var html = '<div class="gre-conductor-item" data-index="' + idx + '">' +
            '<p class="form-field form-field-wide"><label>' + i18n.docType + '</label><select class="gre-field gre-con-tipo-doc">' + tipoDocOptions(tipoDoc) + '</select></p>' +
            '<p class="form-field form-field-wide"><label>' + i18n.docNum + '</label><input type="text" class="gre-field gre-con-num-doc" value="' + (numDoc || '') + '"></p>' +
            '<p class="form-field form-field-wide"><label>' + i18n.names + '</label><input type="text" class="gre-field gre-con-nombres" value="' + (nombres || '') + '"></p>' +
            '<p class="form-field form-field-wide"><label>' + i18n.lastnames + '</label><input type="text" class="gre-field gre-con-apellidos" value="' + (apellidos || '') + '"></p>' +
            '<p class="form-field form-field-wide"><label>' + i18n.license + '</label><input type="text" class="gre-field gre-con-licencia" value="' + (licencia || '') + '"></p>' +
            '<button type="button" class="button gre-remove-conductor">' + i18n.remove + '</button><hr></div>';
        $('#gre-conductores-list').append(html);
    }

    function tipoDocOptions(selected) {
        var opts = { '-': 'Sin documento', '1': 'DNI', '6': 'RUC', '7': 'Pasaporte', '4': 'Carnet de extranjería' };
        var html = '<option value="">Seleccionar...</option>';
        $.each(opts, function (v, l) {
            html += '<option value="' + v + '" ' + (selected === v ? 'selected' : '') + '>' + l + '</option>';
        });
        return html;
    }

    $(document).on('click', '#gre-add-vehiculo', function (e) {
        e.preventDefault();
        addVehiculoRow('', '', '');
    });

    $(document).on('click', '#gre-add-conductor', function (e) {
        e.preventDefault();
        addConductorRow('1', '', '', '', '');
    });

    $(document).on('click', '.gre-remove-vehiculo', function () {
        $(this).closest('.gre-vehiculo-item').remove();
    });

    $(document).on('click', '.gre-remove-conductor', function () {
        $(this).closest('.gre-conductor-item').remove();
    });

    function updateTransportSections() {
        var modalidad = $('#gre_modalidad_transporte').val();
        var isM1orL = $('#gre_vehiculo_categoria').is(':checked');
        if (modalidad === '01') {
            $('#gre-transportista-section').show();
            $('#gre-vehiculos-section').hide();
            $('#gre-conductores-section').hide();
        } else if (modalidad === '02') {
            $('#gre-transportista-section').hide();
            if (isM1orL) {
                $('#gre-vehiculos-section').hide();
                $('#gre-conductores-section').hide();
            } else {
                $('#gre-vehiculos-section').show();
                $('#gre-conductores-section').show();
            }
        } else {
            $('#gre-transportista-section').hide();
            $('#gre-vehiculos-section').hide();
            $('#gre-conductores-section').hide();
        }
    }

    $(document).on('change', '#gre_modalidad_transporte', updateTransportSections);
    $(document).on('change', '#gre_vehiculo_categoria', updateTransportSections);

    $(document).on('change', '#gre_customize_items', function () {
        $('#gre-items-wrapper').toggle(this.checked);
    });

    $(document).on('click', '#gre-save-draft-btn', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var label = $btn.text();
        $btn.prop('disabled', true).text(i18n.saving || '');

        var data = collectFormData();
        $.extend(data, { nonce: nonce });

        $.post(ajaxUrl, data)
            .done(function (res) {
                if (res && res.success) {
                    $btn.text(i18n.saved || 'Guardado');
                    setTimeout(function () { $btn.text(label).prop('disabled', false); }, 2000);
                } else {
                    reportError(res);
                    $btn.text(label).prop('disabled', false);
                }
            })
            .fail(function () {
                $btn.text(label).prop('disabled', false);
            });
    });

    $(document).on('click', '#gre-emit-btn', function (e) {
        e.preventDefault();
        if (!window.confirm('¿Está seguro de emitir esta Guía de Remisión Electrónica?')) return;

        var $btn = $(this);
        var label = $btn.text();
        $btn.prop('disabled', true).text(i18n.emitting || '');

        var data = collectFormData();
        data.action = 'apisunat_gre_emit';
        $.extend(data, { nonce: nonce });

        $.post(ajaxUrl, data)
            .done(function (res) {
                if (res && res.success) {
                    window.location.reload();
                } else {
                    reportError(res);
                    $btn.text(label).prop('disabled', false);
                }
            })
            .fail(function () {
                $btn.text(label).prop('disabled', false);
            });
    });

    $(document).on('click', '#gre-void-btn', function (e) {
        e.preventDefault();
        $('#gre-void-reason').slideToggle();
    });

    $(document).on('click', '#gre-confirm-void', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var reason = $.trim($('#gre-void-reason-input').val());

        if (reason.length < 3) {
            window.alert(i18n.reasonShort || '');
            return;
        }

        $btn.prop('disabled', true).text(i18n.voiding || '');

        postAction({ action: 'apisunat_gre_void', order_id: orderId, reason: reason })
            .done(function (res) {
                if (res && res.success) {
                    window.location.reload();
                } else {
                    reportError(res);
                    $btn.prop('disabled', false).text(i18n.confirm || 'Confirmar Anulación');
                }
            })
            .fail(function () {
                $btn.prop('disabled', false).text(i18n.confirm || 'Confirmar Anulación');
            });
    });

    if (typeof ubigeo.departments !== 'undefined' && ubigeo.departments.length) {
        initUbigeoSelects();
        setTimeout(restoreUbigeo, 50);
    }

    if (!$('#gre-items-list .gre-item-row').length) {
        loadOrderData();
    }
})(jQuery);
