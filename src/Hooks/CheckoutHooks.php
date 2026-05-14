<?php
namespace Atm\Apisunatwp\Hooks;

use Atm\Apisunatwp\Config\Options;
use Atm\Apisunatwp\Services\DocumentConsultaService;

class CheckoutHooks {

    public const NONCE_ACTION = 'apisunatv2_checkout_nonce';

    public static function register(): void {
        if (Options::getValue('advanced.custom_checkout')) {
            return;
        }

        if (self::isBlockCheckout()) {
            self::registerBlockCheckout();
        } else {
            self::registerClassicCheckout();
        }

        add_action('wp_enqueue_scripts',                   [self::class, 'enqueueScripts']);
        add_action('wp_ajax_apisunat_consult_document',    [self::class, 'ajaxConsultDocument']);
        add_action('wp_ajax_nopriv_apisunat_consult_document', [self::class, 'ajaxConsultDocument']);
    }

    private static function isBlockCheckout(): bool {
        if (!function_exists('has_block')) {
            return false;
        }
        $checkoutPageId = wc_get_page_id('checkout');
        if (!$checkoutPageId) {
            return false;
        }
        $content = get_post_field('post_content', $checkoutPageId);
        return has_block('woocommerce/checkout', $content);
    }

    private static function registerBlockCheckout(): void {
        add_action('woocommerce_init',                                       [self::class, 'registerBlockFields']);
        add_action('woocommerce_blocks_validate_location_order_fields',      [self::class, 'validateBlockFields'], 10, 3);
        add_action('woocommerce_new_order',                                  [self::class, 'syncBlockFields'], 20, 1);
    }

    public static function registerBlockFields(): void {
        if (!function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }

        woocommerce_register_additional_checkout_field([
            'id'       => 'apisunat/sunat_cpe_type',
            'label'    => __('Comprobante', 'apisunatv2'),
            'location' => 'order',
            'type'     => 'select',
            'options'  => [
                ['value' => '03', 'label' => __('Boleta de Venta', 'apisunatv2')],
                ['value' => '01', 'label' => __('Factura', 'apisunatv2')],
            ],
            'required' => true,
        ]);

        woocommerce_register_additional_checkout_field([
            'id'       => 'apisunat/sunat_id_type',
            'label'    => __('Tipo de documento', 'apisunatv2'),
            'location' => 'order',
            'type'     => 'select',
            'options'  => [
                ['value' => '1', 'label' => __('DNI', 'apisunatv2')],
                ['value' => '6', 'label' => __('RUC', 'apisunatv2')],
                ['value' => '7', 'label' => __('Pasaporte', 'apisunatv2')],
                ['value' => 'B', 'label' => __('Otro (extranjero)', 'apisunatv2')],
            ],
            'required' => true,
        ]);

        woocommerce_register_additional_checkout_field([
            'id'       => 'apisunat/sunat_id_number',
            'label'    => __('Número de documento', 'apisunatv2'),
            'location' => 'order',
            'type'     => 'text',
            'required' => true,
        ]);
    }

    public static function validateBlockFields($errors, $fields, $group): void {
        if ($group !== 'other') {
            return;
        }

        $docType   = (string) ($fields['apisunat/sunat_id_type'] ?? '');
        $docNumber = (string) ($fields['apisunat/sunat_id_number'] ?? '');

        if ($docType !== '' && $docNumber !== '') {
            if (!preg_match(self::documentPattern($docType), $docNumber)) {
                $errors->add('apisunat_document', self::documentErrorLabel($docType));
            }
        }

        $cpeType = (string) ($fields['apisunat/sunat_cpe_type'] ?? '');
        if ($cpeType === '01' && $docType !== '6') {
            $errors->add('apisunat_ruc_required', __('RUC requerido para Factura', 'apisunatv2'));
        }
    }

    public static function syncBlockFields(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $mapping = [
            '_wc_other/apisunat/sunat_cpe_type'  => '_billing_apisunat_cpe_type',
            '_wc_other/apisunat/sunat_id_type'   => '_billing_apisunat_id_type',
            '_wc_other/apisunat/sunat_id_number' => '_billing_apisunat_id_number',
        ];

        $updated = false;
        foreach ($mapping as $blockKey => $metaKey) {
            $value = $order->get_meta($blockKey, true);
            if ($value !== '' && $value !== null && !$order->get_meta($metaKey, true)) {
                $order->update_meta_data($metaKey, sanitize_text_field((string) $value));
                $updated = true;
            }
        }
        if ($updated) {
            $order->save();
        }
    }

    private static function registerClassicCheckout(): void {
        add_action('woocommerce_before_checkout_billing_form',     [self::class, 'renderClassicFields']);
        add_action('woocommerce_after_checkout_validation',        [self::class, 'validate'], 10, 2);
        add_action('woocommerce_checkout_create_order',            [self::class, 'save'], 10, 2);
        add_filter('woocommerce_billing_fields',                   [self::class, 'optionalNameFields']);
    }

    public static function optionalNameFields(array $fields): array {
        $fields['billing_first_name']['required'] = false;
        $fields['billing_last_name']['required']  = false;
        return $fields;
    }

    public static function renderClassicFields(): void {
        $cpeType  = (string) (WC()->checkout->get_value('_billing_apisunat_cpe_type') ?: '03');
        $idType   = (string) (WC()->checkout->get_value('_billing_apisunat_id_type') ?: '1');
        $idNumber = (string) WC()->checkout->get_value('_billing_apisunat_id_number');
        ?>
        <div class="sunat-checkout-fields">
            <p class="form-row form-row-wide" id="_billing_apisunat_cpe_type_field">
                <label for="_billing_apisunat_cpe_type"><?= esc_html__('Comprobante', 'apisunatv2') ?>&nbsp;<abbr class="required" title="required">*</abbr></label>
                <select name="_billing_apisunat_cpe_type" id="_billing_apisunat_cpe_type" class="select" required>
                    <option value="03" <?= selected($cpeType, '03', false) ?>><?= esc_html__('Boleta de Venta', 'apisunatv2') ?></option>
                    <option value="01" <?= selected($cpeType, '01', false) ?>><?= esc_html__('Factura', 'apisunatv2') ?></option>
                </select>
            </p>
            <p class="form-row form-row-wide" id="_billing_apisunat_id_type_field">
                <label for="_billing_apisunat_id_type"><?= esc_html__('Tipo de documento', 'apisunatv2') ?>&nbsp;<abbr class="required" title="required">*</abbr></label>
                <select name="_billing_apisunat_id_type" id="_billing_apisunat_id_type" class="select" required>
                    <option value="1" <?= selected($idType, '1', false) ?>><?= esc_html__('DNI', 'apisunatv2') ?></option>
                    <option value="6" <?= selected($idType, '6', false) ?>><?= esc_html__('RUC', 'apisunatv2') ?></option>
                    <option value="7" <?= selected($idType, '7', false) ?>><?= esc_html__('Pasaporte', 'apisunatv2') ?></option>
                    <option value="B" <?= selected($idType, 'B', false) ?>><?= esc_html__('Otro (extranjero)', 'apisunatv2') ?></option>
                </select>
            </p>
            <p class="form-row form-row-wide" id="_billing_apisunat_id_number_field">
                <label for="_billing_apisunat_id_number"><?= esc_html__('Número de documento', 'apisunatv2') ?>&nbsp;<abbr class="required" title="required">*</abbr></label>
                <input type="text" name="_billing_apisunat_id_number" id="_billing_apisunat_id_number" class="input-text" value="<?= esc_attr($idNumber) ?>" placeholder="<?= esc_attr__('DNI o RUC', 'apisunatv2') ?>" required autocomplete="off">
            </p>
        </div>
        <?php
    }

    private static function documentPattern(string $type): string {
        return match ($type) {
            '6'     => '/^(10|15|17|20)\d{9}$/',
            '1'     => '/^\d{8}$/',
            default => '/^[a-zA-Z0-9]{1,15}$/',
        };
    }

    private static function isValidRuc(string $ruc): bool
    {
        if (!preg_match('/^(10|15|17|20)\d{9}$/', $ruc)) {
            return false;
        }

        $factors = [5,4,3,2,7,6,5,4,3,2];
        $sum = 0;

        for ($i = 0; $i < 10; $i++) {
            $sum += (int)$ruc[$i] * $factors[$i];
        }

        $rest = 11 - ($sum % 11);
        $digit = $rest === 10 ? 0 : ($rest === 11 ? 1 : $rest);

        return (int)$ruc[10] === $digit;
    }

    private static function documentErrorLabel(string $type): string {
        return match ($type) {
            '1'     => __('DNI debe tener 8 dígitos', 'apisunatv2'),
            '6'     => __('RUC formato inválido', 'apisunatv2'),
            default => __('Documento formato inválido', 'apisunatv2'),
        };
    }

    public static function validate($data, $errors): void {
        $docType   = isset($_POST['_billing_apisunat_id_type'])   ? sanitize_text_field(wp_unslash($_POST['_billing_apisunat_id_type']))   : '';
        $docNumber = isset($_POST['_billing_apisunat_id_number']) ? sanitize_text_field(wp_unslash($_POST['_billing_apisunat_id_number'])) : '';
        $cpeType   = isset($_POST['_billing_apisunat_cpe_type'])  ? sanitize_text_field(wp_unslash($_POST['_billing_apisunat_cpe_type']))  : '';

        if ($docType !== '' && $docNumber !== '') {
            if (!preg_match(self::documentPattern($docType), $docNumber)) {
                $errors->add('apisunat_document', self::documentErrorLabel($docType));
            } elseif ($docType === '6' && !self::isValidRuc($docNumber)) {
                $errors->add('apisunat_document', __('RUC inválido', 'apisunatv2'));
            }
        }

        if ($cpeType === '01') {
            if ($docType !== '6') {
                $errors->add('apisunat_ruc_required', __('RUC requerido para Factura', 'apisunatv2'));
            }
            // Solo exigir billing_company si el campo está presente en el formulario
            if (isset($_POST['billing_company'])) {
                $company = sanitize_text_field(wp_unslash($_POST['billing_company']));
                if ($company === '') {
                    $errors->add('apisunat_company', __('Empresa requerida para Factura', 'apisunatv2'));
                }
            }
        }

        if ($cpeType === '03') {
            $first = isset($_POST['billing_first_name']) ? sanitize_text_field(wp_unslash($_POST['billing_first_name'])) : '';
            $last  = isset($_POST['billing_last_name'])  ? sanitize_text_field(wp_unslash($_POST['billing_last_name']))  : '';
            if (strlen($first) + strlen($last) < 3) {
                $errors->add('apisunat_name', __('Nombre/apellido requiere 3+ caracteres para Boleta', 'apisunatv2'));
            }
        }
    }

    public static function save($order, $data): void {
        $fields = ['_billing_apisunat_cpe_type', '_billing_apisunat_id_type', '_billing_apisunat_id_number'];
        foreach ($fields as $field) {
            if (isset($_POST[$field]) && $_POST[$field] !== '') {
                $order->update_meta_data($field, sanitize_text_field(wp_unslash($_POST[$field])));
            }
        }
    }

    public static function ajaxConsultDocument(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        // Gate 1: request must originate from the checkout page
        $referer = wp_get_referer();
        $checkoutUrl = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '';
        if (!$referer || !$checkoutUrl || strpos($referer, untrailingslashit($checkoutUrl)) === false) {
            wp_send_json_error(['message' => __('Contexto inválido', 'apisunatv2')], 403);
        }

        // Gate 2: per-IP rate limit (20 lookups / 10 minutes)
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        if ($ip !== '') {
            $rlKey = 'apisunat_consult_rl_' . md5($ip);
            $count = (int) get_transient($rlKey);
            if ($count >= 20) {
                wp_send_json_error(['message' => __('Demasiadas solicitudes. Intenta más tarde.', 'apisunatv2')], 429);
            }
            set_transient($rlKey, $count + 1, 10 * MINUTE_IN_SECONDS);
        }

        $docType   = isset($_POST['doc_type'])   ? sanitize_text_field(wp_unslash($_POST['doc_type']))   : '';
        $docNumber = isset($_POST['doc_number']) ? sanitize_text_field(wp_unslash($_POST['doc_number'])) : '';

        if ($docType === '' || $docNumber === '') {
            wp_send_json_error(['message' => __('Parámetros inválidos', 'apisunatv2')]);
        }

        // Gate 3: tight input bounds — DNI is exactly 8 digits, RUC is exactly 11 digits
        if (!in_array($docType, ['1', '6'], true)) {
            wp_send_json_error(['message' => __('Tipo de documento no soportado', 'apisunatv2')]);
        }
        if (!ctype_digit($docNumber) || strlen($docNumber) < 8 || strlen($docNumber) > 11) {
            wp_send_json_error(['message' => __('Número de documento inválido', 'apisunatv2')]);
        }

        $result = $docType === '6'
            ? DocumentConsultaService::consultRuc($docNumber)
            : DocumentConsultaService::consultDni($docNumber);

        if ($result) {
            wp_send_json_success($result);
        }
        wp_send_json_error(['message' => __('No se encontró información', 'apisunatv2')]);
    }

    public static function enqueueScripts(): void {
        if (!is_checkout()) {
            return;
        }

        wp_enqueue_script(
            'apisunatv2-checkout',
            plugins_url('assets/js/checkout.js', dirname(__DIR__, 2) . '/apisunatwp.php'),
            ['jquery'],
            defined('APISUNATWP_VERSION') ? APISUNATWP_VERSION : '1.0.0',
            true
        );

        wp_localize_script('apisunatv2-checkout', 'apisunatv2_checkout', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce(self::NONCE_ACTION),
            'isBlock'  => self::isBlockCheckout(),
        ]);
    }
}
