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
        $branchDetraction = $branchDetraction + (array) Options::getValue('detraction', []);

        $enabled         = (string) $order->get_meta('_billing_apisunat_detraction_enabled') === '1';
        $detraction_type = (string) ($order->get_meta('_billing_apisunat_detraction_tipo') ?: ($branchDetraction['detraction_type'] ?? ''));
        $percentage      = (string) ($order->get_meta('_billing_apisunat_detraction_percentage') ?: ($branchDetraction['percentage'] ?? 12));
        $payment_method   = (string) ($order->get_meta('_billing_apisunat_detraction_payment_method') ?: ($branchDetraction['payment_method'] ?? '001'));
        $cuenta_banco    = (string) ($order->get_meta('_billing_apisunat_detraction_cuenta_banco') ?: ($branchDetraction['bank_account'] ?? ''));
        $monto_total     = $order->get_meta('_billing_apisunat_detraction_monto_total');
        $order_total     = (float) $order->get_total();
        $monto_default   = $order_total > 0 && $percentage > 0 ? round($order_total * (float) $percentage / 100, 2) : 0;
        $monto_total     = $monto_total !== '' ? (string) $monto_total : (string) $monto_default;

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
                <input type="number" id="_billing_apisunat_detraction_percentage" name="_billing_apisunat_detraction_percentage" value="<?= esc_attr($percentage) ?>" step="0.01" min="0" max="100">
            </p>
            <p class="form-field form-field-wide">
                <label for="_billing_apisunat_detraction_cuenta_banco"><?= esc_html__('Cuenta bancaria', 'apisunatv2') ?></label>
                <input type="text" id="_billing_apisunat_detraction_cuenta_banco" name="_billing_apisunat_detraction_cuenta_banco" value="<?= esc_attr($cuenta_banco) ?>" placeholder="<?= esc_attr__('00-000-000000', 'apisunatv2') ?>">
            </p>
            <p class="form-field form-field-wide">
                <label for="_billing_apisunat_detraction_monto_total"><?= esc_html__('Monto total', 'apisunatv2') ?></label>
                <input type="number" id="_billing_apisunat_detraction_monto_total" name="_billing_apisunat_detraction_monto_total" value="<?= esc_attr($monto_total) ?>" step="0.01" min="0">
            </p>
        </div>
        <script>
        (function() {
            var checkbox = document.getElementById('_billing_apisunat_detraction_enabled');
            var fields = document.getElementById('sunat_detraction_fields');
            if (checkbox && fields) {
                checkbox.addEventListener('change', function() {
                    fields.style.display = this.checked ? '' : 'none';
                });
            }
            var tipo = document.getElementById('_billing_apisunat_detraction_tipo');
            var pct = document.getElementById('_billing_apisunat_detraction_percentage');
            var monto = document.getElementById('_billing_apisunat_detraction_monto_total');
            if (tipo && pct) {
                tipo.addEventListener('change', function() {
                    var opt = this.options[this.selectedIndex];
                    if (opt && opt.dataset.percent) {
                        pct.value = opt.dataset.percent;
                        pct.dispatchEvent(new Event('change'));
                    }
                });
            }
            if (pct && monto) {
                pct.addEventListener('change', function() {
                    if (monto.value === '' || parseFloat(monto.value) === 0) {
                        var total = <?= (float) $order_total ?>;
                        var pctVal = parseFloat(this.value) || 0;
                        monto.value = total > 0 && pctVal > 0 ? (total * pctVal / 100).toFixed(2) : '';
                    }
                });
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
                <input type="text" id="_billing_apisunat_id_number" name="_billing_apisunat_id_number" value="<?= esc_attr($idNumber) ?>">
            </p>
        </div>
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
