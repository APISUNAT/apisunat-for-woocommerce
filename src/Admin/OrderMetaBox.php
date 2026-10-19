<?php
namespace Atm\Apisunatwp\Admin;

use Atm\Apisunatwp\Config\Catalogs;
use Atm\Apisunatwp\Config\Options;
use Atm\Apisunatwp\Services\ApiSunatService;

class OrderMetaBox {

    public static function register(): void {
        add_action('add_meta_boxes_shop_order',                       [self::class, 'metaBox']);
        add_action('add_meta_boxes_woocommerce_page_wc-orders',       [self::class, 'metaBox']);
        add_action('woocommerce_admin_order_data_after_billing_address', [self::class, 'billingMeta']);
        add_action('woocommerce_process_shop_order_meta',                [self::class, 'saveOrderMeta']);
    }

    public static function metaBox(): void {
        add_meta_box(
            'sunat_cpe_meta',
            __('APISUNAT', 'apisunatv2'),
            [self::class, 'render'],
            null,
            'side',
            'default'
        );
    }

    public static function renderDetraction($object): void {
        if (is_object($object) && method_exists($object, 'get_id')) {
            $order_id = (int) $object->get_id();
        } elseif (is_object($object) && isset($object->ID)) {
            $order_id = (int) $object->ID;
        } else {
            $order_id = (int) $object;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $savedConfig = get_option(Options::OPTION_KEY . '_detraction', null);
        $globalConfig = is_array($savedConfig) ? $savedConfig : [];

        $branchDetraction = [];
        $branchKey = Options::getValue('settings.multi_branch_key', '');
        if ($branchKey !== '') {
            $branchId = (string) $order->get_meta($branchKey);
            if ($branchId !== '') {
                $branch = Options::getBranch($branchId);
                if ($branch !== null && isset($branch['detraction'])) {
                    $branchDetraction = $branch['detraction'];
                }
            }
        }
        $defaults = $branchDetraction + $globalConfig;

        $enabled         = (string) $order->get_meta('_billing_apisunat_detraction_enabled') === '1';
        $detraction_type = (string) ($order->get_meta('_billing_apisunat_detraction_tipo') ?: ($defaults['detraction_type'] ?? ''));
        $percentage      = (string) ($order->get_meta('_billing_apisunat_detraction_percentage') ?: ($defaults['percentage'] ?? ''));
        $payment_method   = (string) ($order->get_meta('_billing_apisunat_detraction_payment_method') ?: ($defaults['payment_method'] ?? ''));
        $cuenta_banco    = (string) ($order->get_meta('_billing_apisunat_detraction_cuenta_banco') ?: ($defaults['bank_account'] ?? ''));
        $monto_total     = $order->get_meta('_billing_apisunat_detraction_monto_total');
        $order_total     = (float) $order->get_total();
        $pctVal          = $percentage !== '' ? (float) $percentage : 0;
        $monto_default   = $order_total > 0 && $pctVal > 0 ? round($order_total * $pctVal / 100, 2) : 0;
        $monto_total     = $monto_total !== '' ? (string) $monto_total : (string) number_format((float) $monto_default, 2, '.', '');

        $tipos_de_detraction = Catalogs::tiposDeDetraction();
        $medios_de_pago      = Catalogs::mediosDePago();
        ?>
        <p class="form-field form-field-wide">
            <label for="_billing_apisunat_detraction_enabled">
                <input type="checkbox" id="_billing_apisunat_detraction_enabled" name="_billing_apisunat_detraction_enabled" value="1" <?php checked($enabled, true); ?>>
                <?= esc_html__('Aplicar Detracción', 'apisunatv2') ?>
            </label>
        </p>
        <div id="sunat_detraction_fields" style="<?= $enabled ? '' : 'display:none;' ?>">
            <p class="form-field form-field-wide">
                <label for="_billing_apisunat_detraction_tipo"><?= esc_html__('Tipo de detracción', 'apisunatv2') ?></label>
                <select id="_billing_apisunat_detraction_tipo" name="_billing_apisunat_detraction_tipo">
                    <?php foreach ($tipos_de_detraction as $k => $v): ?>
                        <option value="<?= esc_attr($k) ?>" <?= selected($detraction_type, $k, false) ?> data-percent="<?= esc_attr($v['percent'] ?? '') ?>"><?= esc_html($v['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="form-field form-field-wide">
                <label for="_billing_apisunat_detraction_payment_method"><?= esc_html__('Medio de pago', 'apisunatv2') ?></label>
                <select id="_billing_apisunat_detraction_payment_method" name="_billing_apisunat_detraction_payment_method">
                    <?php foreach ($medios_de_pago as $k => $v): ?>
                        <option value="<?= esc_attr($k) ?>" <?= selected($payment_method, $k, false) ?>><?= esc_html($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="form-field form-field-wide">
                <label for="_billing_apisunat_detraction_percentage"><?= esc_html__('% Detracción', 'apisunatv2') ?></label>
                <input type="number" id="_billing_apisunat_detraction_percentage" name="_billing_apisunat_detraction_percentage" value="<?= esc_attr($percentage) ?>" step="1" min="0" max="100" inputmode="numeric" pattern="[0-9]*">
            </p>
            <p class="form-field form-field-wide">
                <label for="_billing_apisunat_detraction_cuenta_banco"><?= esc_html__('Cuenta bancaria', 'apisunatv2') ?></label>
                <input type="text" id="_billing_apisunat_detraction_cuenta_banco" name="_billing_apisunat_detraction_cuenta_banco" value="<?= esc_attr($cuenta_banco) ?>" placeholder="<?= esc_attr__('00-000-000000', 'apisunatv2') ?>">
            </p>
            <p class="form-field form-field-wide">
                <label for="_billing_apisunat_detraction_monto_total"><?= esc_html__('Monto total (S/)', 'apisunatv2') ?></label>
                <span style="display:inline-flex;align-items:center;gap:2px;width:100%;">
                    <span style="font-weight:600;font-size:14px;">S/</span>
                    <input type="number" id="_billing_apisunat_detraction_monto_total" name="_billing_apisunat_detraction_monto_total" value="<?= esc_attr($monto_total) ?>" step="0.01" min="0" style="flex:1;">
                </span>
            </p>
        </div>
        <script>
        (function() {
            var defaults = {
                detraction_type: '<?= esc_js($defaults['detraction_type'] ?? '') ?>',
                percentage: '<?= esc_js($defaults['percentage'] ?? '') ?>',
                payment_method: '<?= esc_js($defaults['payment_method'] ?? '') ?>',
                bank_account: '<?= esc_js($defaults['bank_account'] ?? '') ?>',
            };
            var checkbox = document.getElementById('_billing_apisunat_detraction_enabled');
            var fields = document.getElementById('sunat_detraction_fields');
            if (checkbox && fields) {
                checkbox.addEventListener('change', function() {
                    fields.style.display = this.checked ? '' : 'none';
                    if (this.checked) {
                        var tipo = document.getElementById('_billing_apisunat_detraction_tipo');
                        var pct = document.getElementById('_billing_apisunat_detraction_percentage');
                        var method = document.getElementById('_billing_apisunat_detraction_payment_method');
                        var banco = document.getElementById('_billing_apisunat_detraction_cuenta_banco');
                        if (tipo) tipo.value = defaults.detraction_type;
                        if (pct) pct.value = defaults.percentage;
                        if (method) method.value = defaults.payment_method;
                        if (banco) banco.value = defaults.bank_account;
                        if (tipo) {
                            var evt = document.createEvent('HTMLEvents');
                            evt.initEvent('change', true, false);
                            tipo.dispatchEvent(evt);
                        }
                    }
                });
            }
            var tipo = document.getElementById('_billing_apisunat_detraction_tipo');
            var pct = document.getElementById('_billing_apisunat_detraction_percentage');
            var monto = document.getElementById('_billing_apisunat_detraction_monto_total');
            var total = <?= (float) $order_total ?>;
            function recalcMonto() {
                var pctVal = parseInt(pct.value, 10) || 0;
                monto.value = total > 0 && pctVal > 0 ? (total * pctVal / 100).toFixed(2) : '0.00';
            }
            if (tipo && pct) {
                tipo.addEventListener('change', function() {
                    var opt = this.options[this.selectedIndex];
                    if (opt && opt.dataset.percent) {
                        pct.value = opt.dataset.percent;
                    } else {
                        pct.value = '';
                    }
                    recalcMonto();
                });
            }
            if (pct && monto) {
                pct.addEventListener('input', recalcMonto);
                pct.addEventListener('change', recalcMonto);
            }
        })();
        </script>
        <?php
    }

    public static function render($object): void {
        if (is_object($object) && method_exists($object, 'get_id')) {
            $order_id = (int) $object->get_id();
        } elseif (is_object($object) && isset($object->ID)) {
            $order_id = (int) $object->ID;
        } else {
            $order_id = (int) $object;
        }
        $order    = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $status = (string) $order->get_meta('_apisunat_document_status', true);

        if ($status === '') {
            echo '<p>' . esc_html__('Sin enviar', 'apisunatv2') . '</p>';
        } else {
            printf(
                '<p><span class="sunat-status %s" id="sunatStatusBadge">%s</span></p>',
                esc_attr(strtolower($status)),
                esc_html($status)
            );
        }

        if ($status === 'ACEPTADO') {
            self::renderVoidSection($order_id, $order);
        }

        if ($status === 'PENDIENTE') {
            printf(
                '<p><button type="button" id="sunatCheckBtn" data-order="%d" class="button">%s</button></p>',
                $order_id,
                esc_html__('Verificar estado', 'apisunatv2')
            );
        }

        echo '<hr style="border-top:1px solid #ddd; margin:12px 0;">';

        self::renderDetraction($object);

        echo '<hr style="border-top:1px solid #ddd; margin:12px 0;">';

        if ($status === '' || in_array($status, ['ERROR', 'EXCEPCION'], true)) {
            self::emitBtn($order_id, $order->get_status());
        }
    }

    public static function billingMeta(\WC_Order $order): void {
        $docType  = (string) ($order->get_meta('_billing_apisunat_cpe_type') ?: '03');
        $idType   = (string) ($order->get_meta('_billing_apisunat_id_type') ?: '1');
        $idNumber = (string) $order->get_meta('_billing_apisunat_id_number');
        $creditCuotas = (string) $order->get_meta('_billing_apisunat_credit_cuotas');

        $cpeOptions = ['03' => __('Boleta', 'apisunatv2'), '01' => __('Factura', 'apisunatv2')];
        $idOptions  = [
            '-' => __('Sin documento', 'apisunatv2'),
            '1' => __('DNI', 'apisunatv2'),
            '6' => __('RUC', 'apisunatv2'),
            'H' => __('CPP - Carné Temporal de Permanencia', 'apisunatv2'),
            '7' => __('Pasaporte', 'apisunatv2'),
            '4' => __('Carnet de extranjería', 'apisunatv2'),
            'E' => __('TAM - Tarjeta Andina de Migración', 'apisunatv2'),
            'A' => __('Cédula Diplomática', 'apisunatv2'),
            'G' => __('Salvoconducto', 'apisunatv2'),
            'C' => __('TIN - Tax Identification Number', 'apisunatv2'),
            'D' => __('IN - Identification Number', 'apisunatv2'),
            'B' => __('ID. PERS. NAT. (no domiciliado)', 'apisunatv2'),
            '0' => __('DOC. TRIB. (no domiciliado)', 'apisunatv2'),
        ];
        ?>
        <div class="order_sunat_fields">
            <p class="form-field form-field-wide">
                <label for="_billing_apisunat_cpe_type"><?= esc_html__('Tipo CPE', 'apisunatv2') ?>:</label>
                <select id="_billing_apisunat_cpe_type" name="_billing_apisunat_cpe_type">
                    <?php foreach ($cpeOptions as $k => $v): ?>
                        <option value="<?= esc_attr($k) ?>" <?= selected($docType, $k, false) ?>><?= esc_html($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="form-field form-field-wide">
                <label for="_billing_apisunat_id_type"><?= esc_html__('Tipo Doc.', 'apisunatv2') ?>:</label>
                <select id="_billing_apisunat_id_type" name="_billing_apisunat_id_type">
                    <?php foreach ($idOptions as $k => $v): ?>
                        <option value="<?= esc_attr($k) ?>" <?= selected($idType, $k, false) ?>><?= esc_html($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="form-field form-field-wide">
                <label for="_billing_apisunat_id_number"><?= esc_html__('Nº Doc.', 'apisunatv2') ?>:</label>
                <span style="display:inline-flex;align-items:center;gap:4px;width:100%;">
                    <input type="text" id="_billing_apisunat_id_number" name="_billing_apisunat_id_number" value="<?= esc_attr($idNumber) ?>" style="flex:1;">
                    <span class="apisunat-search-icon" id="admin-doc-search" title="<?= esc_attr__('Consultar', 'apisunatv2') ?>"></span>
                </span>
            </p>
            <div id="sunat_credit_section" class="form-field form-field-wide" style="<?= $docType === '01' ? '' : 'display:none;' ?>">
                <label>
                    <input type="checkbox" id="_billing_apisunat_credit_enabled" name="_billing_apisunat_credit_enabled" value="1" <?php checked($creditCuotas !== ''); ?>>
                    <?= esc_html__('Pago al Crédito', 'apisunatv2') ?>
                </label>
                <div id="sunat_credit_cuotas" style="<?= $creditCuotas !== '' ? 'margin-top:6px;' : 'display:none;margin-top:6px;' ?>">
                    <label for="_billing_apisunat_credit_cuotas"><?= esc_html__('N° de Cuotas', 'apisunatv2') ?>:</label>
                    <input type="number" id="_billing_apisunat_credit_cuotas" name="_billing_apisunat_credit_cuotas" value="<?= esc_attr($creditCuotas ?: '1') ?>" min="1" step="1" style="width:80px;">
                </div>
            </div>
        </div>
        <script>
        (function() {
            var cpe = document.getElementById('_billing_apisunat_cpe_type');
            var creditSection = document.getElementById('sunat_credit_section');
            var creditCheck = document.getElementById('_billing_apisunat_credit_enabled');
            var creditCuotas = document.getElementById('sunat_credit_cuotas');
            if (cpe && creditSection) {
                cpe.addEventListener('change', function() {
                    creditSection.style.display = this.value === '01' ? '' : 'none';
                });
            }
            if (creditCheck && creditCuotas) {
                creditCheck.addEventListener('change', function() {
                    creditCuotas.style.display = this.checked ? '' : 'none';
                });
            }
            // Doc search icon
            var docSearch = document.getElementById('admin-doc-search');
            var docType = document.getElementById('_billing_apisunat_id_type');
            var docNum = document.getElementById('_billing_apisunat_id_number');
            if (docSearch && docType && docNum) {
                docSearch.addEventListener('click', function() {
                    var t = docType.value;
                    var n = docNum.value.trim();
                    if (!t || !n) return;
                    if ((t === '6' && !/^\d{11}$/.test(n)) || (t === '1' && !/^\d{8}$/.test(n))) return;
                    var icon = this;
                    icon.classList.add('apisunat-loading');
                    var data = new FormData();
                    data.append('action', 'apisunat_consult_document');
                    data.append('doc_type', t);
                    data.append('doc_number', n);
                    data.append('nonce', '<?= esc_js(wp_create_nonce(\Atm\Apisunatwp\Hooks\CheckoutHooks::NONCE_ACTION)) ?>');
                    fetch(ajaxurl, { method: 'POST', body: data })
                        .then(function(r) { return r.json(); })
                        .then(function(res) {
                            if (res && res.success && res.data) {
                                var d = res.data;
                                if (d.name) {
                                    if (t === '6') {
                                        var co = document.getElementById('billing_company');
                                        if (co) co.value = d.name;
                                    } else {
                                        var parts = d.name.split(' ');
                                        var fn = document.getElementById('billing_first_name');
                                        var ln = document.getElementById('billing_last_name');
                                        if (fn) fn.value = parts.shift() || '';
                                        if (ln) ln.value = parts.join(' ');
                                    }
                                }
                                if (d.address) {
                                    var addr = document.getElementById('billing_address_1');
                                    if (addr) addr.value = d.address;
                                }
                            }
                        })
                        .finally(function() {
                            icon.classList.remove('apisunat-loading');
                        });
                });
            }
        })();
        </script>
        <?php
    }

    public static function saveOrderMeta(int|string $order_id): void {
        $order = wc_get_order((int) $order_id);
        if (!$order) {
            return;
        }
        if (!current_user_can('edit_shop_orders')) {
            return;
        }

        foreach (['_billing_apisunat_cpe_type', '_billing_apisunat_id_type', '_billing_apisunat_id_number'] as $meta_key) {
            if (isset($_POST[$meta_key])) {
                $order->update_meta_data($meta_key, sanitize_text_field(wp_unslash($_POST[$meta_key])));
            }
        }

        $order->update_meta_data('_billing_apisunat_detraction_enabled', isset($_POST['_billing_apisunat_detraction_enabled']) ? '1' : '0');
        if (isset($_POST['_billing_apisunat_detraction_payment_method'])) {
            $order->update_meta_data('_billing_apisunat_detraction_payment_method', sanitize_text_field(wp_unslash($_POST['_billing_apisunat_detraction_payment_method'])));
        }
        if (isset($_POST['_billing_apisunat_detraction_percentage'])) {
            $order->update_meta_data('_billing_apisunat_detraction_percentage', sanitize_text_field(wp_unslash($_POST['_billing_apisunat_detraction_percentage'])));
        }
        if (isset($_POST['_billing_apisunat_detraction_tipo'])) {
            $order->update_meta_data('_billing_apisunat_detraction_tipo', sanitize_text_field(wp_unslash($_POST['_billing_apisunat_detraction_tipo'])));
        }
        if (isset($_POST['_billing_apisunat_detraction_cuenta_banco'])) {
            $order->update_meta_data('_billing_apisunat_detraction_cuenta_banco', sanitize_text_field(wp_unslash($_POST['_billing_apisunat_detraction_cuenta_banco'])));
        }
        if (isset($_POST['_billing_apisunat_detraction_monto_total'])) {
            $order->update_meta_data('_billing_apisunat_detraction_monto_total', sanitize_text_field(wp_unslash($_POST['_billing_apisunat_detraction_monto_total'])));
        }

        if (isset($_POST['_billing_apisunat_credit_cuotas']) && !empty($_POST['_billing_apisunat_credit_enabled'])) {
            $order->update_meta_data('_billing_apisunat_credit_cuotas', absint($_POST['_billing_apisunat_credit_cuotas']));
        } else {
            $order->delete_meta_data('_billing_apisunat_credit_cuotas');
        }

        $order->save();
    }

    private static function renderVoidSection(int $order_id, \WC_Order $order): void {
        $docId    = (string) $order->get_meta('_apisunat_document_id');
        $filename = (string) $order->get_meta('_apisunat_document_filename');
        $parts    = explode('-', $filename);
        $number   = ($parts[2] ?? '') . '-' . ($parts[3] ?? '');

        printf(
            '<p><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
            esc_url(ApiSunatService::BASE_URL . '/documents/' . rawurlencode($docId) . '/getPDF/default/' . rawurlencode($filename) . '.pdf'),
            esc_html($number)
        );

        printf(
            '<button type="button" id="sunatVoidBtn" data-order="%d" class="button apisunat-void-btn">%s</button>',
            $order_id,
            esc_html__('Anular con NC', 'apisunatv2')
        );
        ?>
        <div id="sunatVoidReason" hidden>
            <label class="screen-reader-text" for="sunatReasonInput"><?= esc_html__('Motivo', 'apisunatv2') ?></label>
            <textarea id="sunatReasonInput" rows="3" placeholder="<?= esc_attr__('Motivo...', 'apisunatv2') ?>"></textarea>
            <button type="button" id="sunatConfirmVoid" class="button button-primary"><?= esc_html__('Confirmar', 'apisunatv2') ?></button>
        </div>
        <?php
    }

    private static function emitBtn(int $order_id, string $status): void {
        $estado_config = \Atm\Apisunatwp\Config\Options::getValue('issue.trigger_status', 'wc-completed');
        $estado_actual = 'wc-' . $status;
        $disabled = $estado_actual === $estado_config ? '' : 'disabled';
        printf(
            '<button type="button" data-order="%d" class="button sunat-emit-btn" %s>%s</button>',
            $order_id,
            esc_attr($disabled),
            esc_html__('Emitir', 'apisunatv2')
        );
    }
}
