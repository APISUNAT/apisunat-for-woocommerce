<?php
namespace Atm\Apisunatwp\Admin;

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
            __('API Sunat', 'apisunatv2'),
            [self::class, 'render'],
            null,
            'side',
            'default'
        );
    }

    public static function render($object): void {
        $order_id = is_object($object) ? (int) $object->get_id() : (int) $object;
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
        $docType  = (string) ($order->get_meta('_sunat_cpe_type') ?: '03');
        $idType   = (string) ($order->get_meta('_sunat_id_type') ?: '1');
        $idNumber = (string) $order->get_meta('_sunat_id_number');

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
                <label for="_sunat_cpe_type"><?= esc_html__('Tipo CPE', 'apisunatv2') ?>:</label>
                <select id="_sunat_cpe_type" name="_sunat_cpe_type">
                    <?php foreach ($cpeOptions as $k => $v): ?>
                        <option value="<?= esc_attr($k) ?>" <?= selected($docType, $k, false) ?>><?= esc_html($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="form-field form-field-wide">
                <label for="_sunat_id_type"><?= esc_html__('Tipo Doc.', 'apisunatv2') ?>:</label>
                <select id="_sunat_id_type" name="_sunat_id_type">
                    <?php foreach ($idOptions as $k => $v): ?>
                        <option value="<?= esc_attr($k) ?>" <?= selected($idType, $k, false) ?>><?= esc_html($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="form-field form-field-wide">
                <label for="_sunat_id_number"><?= esc_html__('Nº Doc.', 'apisunatv2') ?>:</label>
                <input type="text" id="_sunat_id_number" name="_sunat_id_number" value="<?= esc_attr($idNumber) ?>">
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

        foreach (['_sunat_cpe_type', '_sunat_id_type', '_sunat_id_number'] as $meta_key) {
            if (isset($_POST[$meta_key])) {
                $order->update_meta_data($meta_key, sanitize_text_field(wp_unslash($_POST[$meta_key])));
            }
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
