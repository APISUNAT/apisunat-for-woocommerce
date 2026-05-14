<?php
namespace Atm\Apisunatwp\Admin;

use Atm\Apisunatwp\Config\Catalogs;
use Atm\Apisunatwp\Config\Options;
use Atm\Apisunatwp\Services\ApiSunatService;

class OrderMetaBox {

    public static function register(): void {
        add_action('add_meta_boxes_shop_order',                       [self::class, 'metaBox']);
        add_action('add_meta_boxes_woocommerce_page_wc-orders',       [self::class, 'metaBox']);
        add_action('add_meta_boxes_shop_order',                       [self::class, 'detraccionMetaBox']);
        add_action('add_meta_boxes_woocommerce_page_wc-orders',       [self::class, 'detraccionMetaBox']);

        add_action('woocommerce_admin_order_data_after_billing_address', [self::class, 'billingMeta']);
        add_action('woocommerce_process_shop_order_meta',                [self::class, 'saveOrderMeta']);
    }

    public static function metaBox(): void {
        add_meta_box(
            'sunat_cpe_meta',
            __('API Sunat', 'apisunatv2'),
            [self::class, 'render'],
            null,
            'side',
            'default'
        );
    }

    public static function detraccionMetaBox(): void {
        add_meta_box(
            'sunat_detraccion_meta',
            __('Detracción SUNAT', 'apisunatv2'),
            [self::class, 'renderDetraccion'],
            null,
            'side',
            'default'
        );
    }

    public static function renderDetraccion($object): void {
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

        $enabled         = (string) $order->get_meta('_billing_apisunat_detraccion_enabled') === '1';
        $tipo_de_detraccion = (string) ($order->get_meta('_billing_apisunat_detraccion_tipo') ?: Options::getValue('detraccion.tipo_de_detraccion', ''));
        $porcentaje      = (string) ($order->get_meta('_billing_apisunat_detraccion_porcentaje') ?: Options::getValue('detraccion.porcentaje', 12));
        $medio_de_pago   = (string) ($order->get_meta('_billing_apisunat_detraccion_medio_de_pago') ?: Options::getValue('detraccion.medio_de_pago', '001'));
        $cuenta_banco    = (string) ($order->get_meta('_billing_apisunat_detraccion_cuenta_banco') ?: Options::getValue('detraccion.cuenta_bancaria', ''));

        $tipos_de_detraccion = Catalogs::tiposDeDetraccion();
        $medios_de_pago      = Catalogs::mediosDePago();
        ?>
        <p class="form-field form-field-wide">
            <label for="_billing_apisunat_detraccion_enabled">
                <input type="checkbox" id="_billing_apisunat_detraccion_enabled" name="_billing_apisunat_detraccion_enabled" value="1" <?php checked($enabled, true); ?>>
                <?= esc_html__('Aplicar detracción manual', 'apisunatv2') ?>
            </label>
        </p>
        <div id="sunat_detraccion_fields" style="<?= $enabled ? '' : 'display:none;' ?>">
            <p class="form-field form-field-wide">
                <label for="_billing_apisunat_detraccion_tipo"><?= esc_html__('Tipo de detracción', 'apisunatv2') ?></label>
                <select id="_billing_apisunat_detraccion_tipo" name="_billing_apisunat_detraccion_tipo">
                    <?php foreach ($tipos_de_detraccion as $k => $v): ?>
                        <option value="<?= esc_attr($k) ?>" <?= selected($tipo_de_detraccion, $k, false) ?> data-percent="<?= esc_attr($v['percent'] ?? '') ?>"><?= esc_html($v['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="form-field form-field-wide">
                <label for="_billing_apisunat_detraccion_medio_de_pago"><?= esc_html__('Medio de pago', 'apisunatv2') ?></label>
                <select id="_billing_apisunat_detraccion_medio_de_pago" name="_billing_apisunat_detraccion_medio_de_pago">
                    <?php foreach ($medios_de_pago as $k => $v): ?>
                        <option value="<?= esc_attr($k) ?>" <?= selected($medio_de_pago, $k, false) ?>><?= esc_html($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="form-field form-field-wide">
                <label for="_billing_apisunat_detraccion_porcentaje"><?= esc_html__('% Detracción', 'apisunatv2') ?></label>
                <input type="number" id="_billing_apisunat_detraccion_porcentaje" name="_billing_apisunat_detraccion_porcentaje" value="<?= esc_attr($porcentaje) ?>" step="0.01" min="0" max="100">
            </p>
            <p class="form-field form-field-wide">
                <label for="_billing_apisunat_detraccion_cuenta_banco"><?= esc_html__('Cuenta bancaria', 'apisunatv2') ?></label>
                <input type="text" id="_billing_apisunat_detraccion_cuenta_banco" name="_billing_apisunat_detraccion_cuenta_banco" value="<?= esc_attr($cuenta_banco) ?>" placeholder="<?= esc_attr__('00-000-000000', 'apisunatv2') ?>">
            </p>
        </div>
        <script>
        (function() {
            var checkbox = document.getElementById('_billing_apisunat_detraccion_enabled');
            var fields = document.getElementById('sunat_detraccion_fields');
            if (checkbox && fields) {
                checkbox.addEventListener('change', function() {
                    fields.style.display = this.checked ? '' : 'none';
                });
            }
            var tipo = document.getElementById('_billing_apisunat_detraccion_tipo');
            var pct = document.getElementById('_billing_apisunat_detraccion_porcentaje');
            if (tipo && pct) {
                tipo.addEventListener('change', function() {
                    var opt = this.options[this.selectedIndex];
                    if (opt && opt.dataset.percent) {
                        pct.value = opt.dataset.percent;
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
            '1' => __('DNI', 'apisunatv2'),
            '6' => __('RUC', 'apisunatv2'),
            '7' => __('Pasaporte', 'apisunatv2'),
            'B' => __('Otro', 'apisunatv2'),
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

        $order->update_meta_data('_billing_apisunat_detraccion_enabled', isset($_POST['_billing_apisunat_detraccion_enabled']) ? '1' : '0');
        if (isset($_POST['_billing_apisunat_detraccion_medio_de_pago'])) {
            $order->update_meta_data('_billing_apisunat_detraccion_medio_de_pago', sanitize_text_field(wp_unslash($_POST['_billing_apisunat_detraccion_medio_de_pago'])));
        }
        if (isset($_POST['_billing_apisunat_detraccion_porcentaje'])) {
            $order->update_meta_data('_billing_apisunat_detraccion_porcentaje', sanitize_text_field(wp_unslash($_POST['_billing_apisunat_detraccion_porcentaje'])));
        }
        if (isset($_POST['_billing_apisunat_detraccion_tipo'])) {
            $order->update_meta_data('_billing_apisunat_detraccion_tipo', sanitize_text_field(wp_unslash($_POST['_billing_apisunat_detraccion_tipo'])));
        }
        if (isset($_POST['_billing_apisunat_detraccion_cuenta_banco'])) {
            $order->update_meta_data('_billing_apisunat_detraccion_cuenta_banco', sanitize_text_field(wp_unslash($_POST['_billing_apisunat_detraccion_cuenta_banco'])));
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
        $estado_config = \Atm\Apisunatwp\Config\Options::getValue('emision.estado_emision', 'wc-completed');
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
