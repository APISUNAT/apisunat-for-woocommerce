<?php
namespace Atm\Apisunatwp\Admin;

use Atm\Apisunatwp\Config\Options;
use Atm\Apisunatwp\Services\ApiSunatService;

class SettingsPage {

    public const NONCE_ACTION = 'apisunatv2_settings_nonce';
    private const SYNC_LIMIT  = 50;
    private const SYNC_THROTTLE_US = 500000;

    public static function init(): void {
        add_action('admin_menu',                    [self::class, 'addMenu']);
        add_action('admin_init',                    [self::class, 'registerSettings']);
        add_action('wp_ajax_apisunat_test_api',     [self::class, 'ajaxTestApi']);
        add_action('wp_ajax_apisunat_sync_pending', [self::class, 'ajaxSyncPending']);
        add_action('wp_ajax_apisunat_enable_tax',         [self::class, 'ajaxEnableTax']);
        add_action('wp_ajax_apisunat_create_tax_classes', [self::class, 'ajaxCreateTaxClasses']);
        add_action('wp_ajax_apisunat_delete_tax_rate',    [self::class, 'ajaxDeleteTaxRate']);
        add_action('wp_ajax_apisunat_add_tax_rate',      [self::class, 'ajaxAddTaxRate']);
        add_action('admin_post_apisunatv2_save',         [self::class, 'handleSave']);
    }

    public static function addMenu(): void {
        add_submenu_page(
            'woocommerce',
            __('API Sunat', 'apisunatv2'),
            __('API Sunat', 'apisunatv2'),
            'manage_woocommerce',
            'apisunatv2-settings',
            [self::class, 'render']
        );
    }

    public static function registerSettings(): void {
        // No usar register_setting/add_settings_field para evitar errores de memoria
        // El formulario se renderiza manualmente en render()
    }

    public static function handleSave(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permiso denegado', 'apisunatv2'));
        }
        check_admin_referer('apisunatv2_settings_save');
        
        $input = isset($_POST[Options::OPTION_KEY]) && is_array($_POST[Options::OPTION_KEY]) 
            ? $_POST[Options::OPTION_KEY] 
            : [];
        
        $sanitized = self::sanitize($input);
        Options::set($sanitized);
        
        wp_redirect(add_query_arg(['updated' => '1'], wp_get_referer()));
        exit;
    }

    public static function sanitize(array $input): array {
        // Cargar solo si es necesario y de forma parcial
        $output = [];

        if (isset($input['api']) && is_array($input['api'])) {
            $output['api']['meta_key'] = sanitize_text_field((string) ($input['api']['meta_key'] ?? ''));

                if (isset($input['api']['branches']) && is_array($input['api']['branches'])) {
                    $branches = [];
                    foreach ($input['api']['branches'] as $branch) {
                        if (!is_array($branch)) {
                            continue;
                        }
                        $series = $branch['series'] ?? [];
                        $sanitized_series = [];
                        foreach (['factura', 'boleta', 'nota_credito_factura', 'nota_credito_boleta'] as $k) {
                            $sanitized_series[$k] = sanitize_text_field((string) ($series[$k] ?? ''));
                        }
                        $branches[] = [
                            'label'        => sanitize_text_field((string) ($branch['label']        ?? '')),
                            'persona_id'    => sanitize_text_field((string) ($branch['persona_id']    ?? '')),
                            'persona_token' => sanitize_text_field((string) ($branch['persona_token'] ?? '')),
                            'series'        => $sanitized_series,
                        ];
                    }
                    $output['api']['branches'] = !empty($branches) ? $branches : ($output['api']['branches'] ?? []);
                }
        }

        if (isset($input['emision']) && is_array($input['emision'])) {
            $emision = $input['emision'];
            $output['emision']['modo'] = in_array(($emision['modo'] ?? 'manual'), ['manual', 'automatico'], true)
                ? $emision['modo'] : 'manual';
            $output['emision']['estado_emision'] = in_array(($emision['estado_emision'] ?? 'wc-completed'), ['wc-completed', 'wc-processing', 'wc-on-hold'], true)
                ? $emision['estado_emision'] : 'wc-completed';

            $series = $emision['series'] ?? [];
            foreach (['factura', 'boleta', 'nota_credito_factura', 'nota_credito_boleta'] as $k) {
                $output['emision']['series'][$k] = sanitize_text_field((string) ($series[$k] ?? ($output['emision']['series'][$k] ?? '')));
            }

            $output['emision']['include_time']  = !empty($emision['include_time']);
            $output['emision']['shipping_cost'] = !empty($emision['shipping_cost']);
        }

        if (isset($input['documento_cliente']) && is_array($input['documento_cliente'])) {
            $output['documento_cliente']['require_for_factura'] = !empty($input['documento_cliente']['require_for_factura']);
            $output['documento_cliente']['require_for_boleta']  = !empty($input['documento_cliente']['require_for_boleta']);
            $output['documento_cliente']['no_doc']              = !empty($input['documento_cliente']['no_doc']);
        }

        if (isset($input['detraccion']) && is_array($input['detraccion'])) {
            $output['detraccion']['enabled']            = !empty($input['detraccion']['enabled']);
            $output['detraccion']['default_percentage'] = max(0, min(100, absint($input['detraccion']['default_percentage'] ?? 12)));
        }

        if (isset($input['advanced']) && is_array($input['advanced'])) {
            $output['advanced']['debug']           = !empty($input['advanced']['debug']);
            $output['advanced']['custom_checkout'] = !empty($input['advanced']['custom_checkout']);

            if (isset($input['advanced']['checkout_mapping']) && is_array($input['advanced']['checkout_mapping'])) {
                $mapping = $input['advanced']['checkout_mapping'];
                foreach (['tipo_comprobante', 'tipo_documento', 'numero_documento'] as $k) {
                    $output['advanced']['checkout_mapping'][$k] = sanitize_text_field((string) ($mapping[$k] ?? ($output['advanced']['checkout_mapping'][$k] ?? '')));
                }
            }
        }

        Options::flushCache();
        return $output;
    }

    public static function ajaxTestApi(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permiso denegado', 'apisunatv2')], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $personaId    = isset($_POST['persona_id'])    ? sanitize_text_field(wp_unslash($_POST['persona_id']))    : '';
        $personaToken = isset($_POST['persona_token']) ? sanitize_text_field(wp_unslash($_POST['persona_token'])) : '';

        if ($personaId === '' || $personaToken === '') {
            $branch = Options::resolveBranch();
            $personaId    = $branch['persona_id'];
            $personaToken = $branch['persona_token'];
        }

        if ($personaId === '' || $personaToken === '') {
            wp_send_json_error(['message' => __('Credenciales requeridas', 'apisunatv2')]);
        }

        $url = add_query_arg(
            [
                'personaId'    => $personaId,
                'personaToken' => $personaToken,
                'limit'        => 1,
            ],
            ApiSunatService::BASE_URL . '/documents/getAll'
        );

        $response = wp_remote_get($url, ['timeout' => 15]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $body = is_array($body) ? $body : [];

        if ($code === 200) {
            wp_send_json_success([
                'message' => __('Conexión exitosa', 'apisunatv2'),
                'persona' => sanitize_text_field((string) ($body['ruc'] ?? $body['razonSocial'] ?? $personaId)),
            ]);
        }

        $msg = isset($body['message'])
            ? (string) $body['message']
            : sprintf(__('Error de conexión (HTTP %d)', 'apisunatv2'), $code);
        wp_send_json_error(['message' => $msg]);
    }

    public static function ajaxSyncPending(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permiso denegado', 'apisunatv2')], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $orders = wc_get_orders([
            'limit'      => self::SYNC_LIMIT,
            'meta_query' => [
                [
                    'key'     => '_apisunat_document_status',
                    'value'   => 'PENDIENTE',
                    'compare' => '=',
                ],
            ],
            'return'     => 'objects',
        ]);

        $checked = 0;
        foreach ($orders as $order) {
            try {
                ApiSunatService::checkStatus($order->get_id());
                $checked++;
                usleep(self::SYNC_THROTTLE_US);
            } catch (\Throwable) {
            }
        }

        $stats = self::statusCounts();

        wp_send_json_success([
            'message'   => sprintf(__('%d órdenes verificadas', 'apisunatv2'), $checked),
            'total'     => array_sum($stats),
            'pendiente' => $stats['PENDIENTE'] ?? 0,
            'aceptado'  => $stats['ACEPTADO']  ?? 0,
            'error'     => $stats['ERROR']     ?? 0,
        ]);
    }

    public static function ajaxDeleteTaxRate(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permiso denegado', 'apisunatv2')], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $rate_id = isset($_POST['rate_id']) ? absint($_POST['rate_id']) : 0;
        if (!$rate_id) {
            wp_send_json_error(['message' => __('ID de tasa no válido', 'apisunatv2')]);
        }

        \WC_Tax::_delete_tax_rate($rate_id);
        wp_send_json_success(['message' => __('Tasa eliminada correctamente', 'apisunatv2')]);
    }

    public static function ajaxAddTaxRate(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permiso denegado', 'apisunatv2')], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!class_exists('WC_Tax')) {
            wp_send_json_error(['message' => __('WooCommerce no disponible', 'apisunatv2')]);
        }

        $rate_value = isset($_POST['rate']) ? (float) wp_unslash($_POST['rate']) : 0.0;
        $rate_name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $rate_class_input = isset($_POST['class']) ? sanitize_text_field(wp_unslash($_POST['class'])) : 'standard';
        $rate_compound = isset($_POST['compound']) ? absint(wp_unslash($_POST['compound'])) : 0;
        
        if ($rate_value < 0 || $rate_value > 100) {
            wp_send_json_error(['message' => __('Tasa inválida', 'apisunatv2')]);
        }

        // WooCommerce get_tax_classes() devuelve slugs directamente
        $rate_class = $rate_class_input;

        $tax_rate_id = \WC_Tax::_insert_tax_rate([
            'tax_rate_country'  => '',
            'tax_rate_state'    => '',
            'tax_rate'          => (string) $rate_value,
            'tax_rate_name'     => $rate_name ?: sprintf(__('Impuesto %s%%', 'apisunatv2'), $rate_value),
            'tax_rate_priority' => $rate_compound ? 2 : 1,
            'tax_rate_compound' => $rate_compound,
            'tax_rate_shipping' => 1,
            'tax_rate_order'    => 0,
            'tax_rate_class'    => $rate_class === 'standard' ? '' : $rate_class,
        ]);

        if ($tax_rate_id) {
            wp_send_json_success(['message' => __('Tasa creada correctamente', 'apisunatv2')]);
        } else {
            wp_send_json_error(['message' => __('Error al crear la tasa', 'apisunatv2')]);
        }
    }

    public static function ajaxEnableTax(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permiso denegado', 'apisunatv2')], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!function_exists('wc_tax_enabled')) {
            wp_send_json_error(['message' => __('WooCommerce no disponible', 'apisunatv2')]);
        }

        update_option('woocommerce_calc_taxes', 'yes');
        wp_send_json_success(['message' => __('Impuestos activados. Recargando...', 'apisunatv2')]);
    }

    public static function ajaxCreateTaxClasses(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permiso denegado', 'apisunatv2')], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!class_exists('WC_Tax')) {
            wp_send_json_error(['message' => __('WooCommerce no disponible', 'apisunatv2')]);
        }

        $classes = [
            'gravado10'   => 'Gravado10',
            'gravado105' => 'Gravado105',
            'exonerado'    => 'Exonerado',
            'inafecto'     => 'Inafecto',
            'gratuito'     => 'Gratuito',
        ];

        $existing = \WC_Tax::get_tax_classes();
        $created  = [];

        foreach ($classes as $slug => $name) {
            if (!in_array($slug, $existing, true)) {
                \WC_Tax::create_tax_class($name, $slug);
                $created[] = $name;
            }
        }

        if (empty($created)) {
            wp_send_json_success(['message' => __('Las clases de impuestos ya existen', 'apisunatv2')]);
        }

        wp_send_json_success(['message' => sprintf(__('Creadas: %s. Recargando...', 'apisunatv2'), implode(', ', $created))]);
    }

    private static function statusCounts(): array {
        global $wpdb;
        $counts = ['PENDIENTE' => 0, 'ACEPTADO' => 0, 'ERROR' => 0];

        $hpos_table = $wpdb->prefix . 'wc_orders_meta';
        $hpos_exists = (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(1) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
            DB_NAME,
            $hpos_table
        ));

        $table  = $hpos_exists ? $hpos_table : $wpdb->postmeta;
        $sql    = $wpdb->prepare(
            "SELECT meta_value, COUNT(*) AS c FROM {$table} WHERE meta_key = %s GROUP BY meta_value",
            '_apisunat_document_status'
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $key = strtoupper(trim((string) ($row['meta_value'] ?? '')));
                if (isset($counts[$key])) {
                    $counts[$key] = (int) $row['c'];
                }
            }
        }

        return $counts;
    }

    public static function render(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permiso denegado', 'apisunatv2'));
        }

        $currentTab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'api';
        $tabs      = self::tabs();
        if (!isset($tabs[$currentTab])) {
            $currentTab = 'api';
        }
        ?>
        <div class="wrap apisunatv2-settings">
            <h1><?= esc_html(get_admin_page_title()) ?></h1>

            <nav class="nav-tab-wrapper apisunat-tabs" aria-label="<?= esc_attr__('Secciones', 'apisunatv2') ?>">
                <?php foreach ($tabs as $id => $tab): ?>
                    <a href="<?= esc_url(add_query_arg(['page' => 'apisunatv2-settings', 'tab' => $id], admin_url('admin.php'))) ?>"
                       class="nav-tab <?= $currentTab === $id ? 'nav-tab-active' : '' ?>">
                        <?= esc_html($tab['label']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="apisunat-content">
                <div class="apisunat-status-bar" role="status" aria-live="polite">
                    <div class="apisunat-stat">
                        <span class="label"><?= esc_html__('Pendientes', 'apisunatv2') ?></span>
                        <span class="value" id="stat-pending">—</span>
                    </div>
                    <div class="apisunat-stat">
                        <span class="label"><?= esc_html__('Aceptados', 'apisunatv2') ?></span>
                        <span class="value" id="stat-accepted">—</span>
                    </div>
                    <div class="apisunat-stat">
                        <span class="label"><?= esc_html__('API', 'apisunatv2') ?></span>
                        <span class="value" id="stat-api">—</span>
                    </div>
                    <button type="button" id="sync-pending-btn" class="button button-secondary"><?= esc_html__('Sincronizar', 'apisunatv2') ?></button>
                </div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="apisunatv2_save">
                    <?php wp_nonce_field('apisunatv2_settings_save'); ?>
                    <table class="form-table" role="presentation">
                        <?php
                        $schema    = self::schema();
                        $tabSection = $tabs[$currentTab]['section'] ?? 'apisunat_api';
                        foreach ($schema as $section) {
                            if ($section['id'] !== $tabSection) {
                                continue;
                            }
                            if (!empty($section['desc']) && is_callable($section['desc'])) {
                                echo '<tr><td colspan="2">';
                                call_user_func($section['desc']);
                                echo '</td></tr>';
                            }
                            foreach ($section['fields'] as $field) {
                                $id = self::fieldId($field['key']);
                                echo '<tr>';
                                echo '<th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($field['label']) . '</label></th>';
                                echo '<td>';
                                self::renderField($field);
                                echo '</td>';
                                echo '</tr>';
                            }
                        }
                        ?>
                    </table>

                    <?php submit_button(); ?>
                </form>
            </div>
        </div>
        <?php
    }

    private static function tabs(): array {
        return [
            'api'        => ['label' => __('API', 'apisunatv2'),        'section' => 'apisunat_api'],
            'emision'    => ['label' => __('Emisión', 'apisunatv2'),    'section' => 'apisunat_emision'],
            'impuestos'  => ['label' => __('Impuestos', 'apisunatv2'),  'section' => 'apisunat_impuestos'],
            'documento'  => ['label' => __('Documento', 'apisunatv2'),  'section' => 'apisunat_documento'],
            'detraccion' => ['label' => __('Detracción', 'apisunatv2'), 'section' => 'apisunat_detraccion'],
            'avanzado'   => ['label' => __('Avanzado', 'apisunatv2'),   'section' => 'apisunat_avanzado'],
        ];
    }

    public static function renderField(array $field): void {
        $keys  = explode('.', $field['key']);
        $name  = self::buildName($keys);
        $id    = self::fieldId($field['key']);
        $value = Options::getValue($field['key'], $field['default'] ?? '');

        match ($field['type']) {
            'text'        => self::renderTextInput($name, $id, (string) $value, $field),
            'number'      => self::renderNumberInput($name, $id, $value, $field),
            'checkbox'    => self::renderCheckbox($name, $id, (bool) $value),
            'select'      => self::renderSelect($name, $id, $value, $field),
            'branches'    => self::renderBranches($name, $id, is_array($value) ? $value : []),
            'tax_manager' => self::renderTaxManager(),
            default       => self::renderTextInput($name, $id, (string) $value, $field),
        };
    }

    private static function renderBranches(string $name, string $id, array $branches): void {
        $baseName = rtrim($name, ']');
        ?>
        <div id="apisunat-branches" class="apisunat-branches">
            <?php foreach ($branches as $i => $branch): ?>
                <div class="branch-row" style="display:flex;gap:8px;margin-bottom:8px;align-items:center;flex-wrap:wrap;">
                    <input type="text" name="<?= esc_attr($baseName . '][' . $i) ?>][label]" value="<?= esc_attr($branch['label'] ?? '') ?>" placeholder="<?= esc_attr__('Etiqueta', 'apisunatv2') ?>" class="regular-text">
                    <input type="text" name="<?= esc_attr($baseName . '][' . $i) ?>][persona_id]" value="<?= esc_attr($branch['persona_id'] ?? '') ?>" placeholder="<?= esc_attr__('Persona ID', 'apisunatv2') ?>" class="regular-text">
                    <input type="text" name="<?= esc_attr($baseName . '][' . $i) ?>][persona_token]" value="<?= esc_attr($branch['persona_token'] ?? '') ?>" placeholder="<?= esc_attr__('Persona Token', 'apisunatv2') ?>" class="regular-text">
                    <button type="button" class="button button-secondary apisunat-remove-branch"><?= esc_html__('Eliminar', 'apisunatv2') ?></button>
                    <button type="button" class="button button-primary apisunat-test-branch"><?= esc_html__('Verificar', 'apisunatv2') ?></button>
                    <span class="apisunat-branch-result" role="status" aria-live="polite"></span>
                    <div class="branch-series" style="width:100%; margin-top:8px; padding-left: 20px;">
                        <strong><?= esc_html__('Series', 'apisunatv2') ?></strong>
                        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:4px;">
                            <?php foreach ([
                                'factura' => __('Serie Factura', 'apisunatv2'),
                                'boleta' => __('Serie Boleta', 'apisunatv2'),
                                'nota_credito_factura' => __('Serie NC Factura', 'apisunatv2'),
                                'nota_credito_boleta' => __('Serie NC Boleta', 'apisunatv2'),
                            ] as $s_key => $s_label):
                                $s_value = $branch['series'][$s_key] ?? '';
                                $s_name = $baseName . '][' . $i . '][series][' . $s_key . ']';
                            ?>
                                <div>
                                    <label style="display:block; font-size:12px;"><?= esc_html($s_label) ?></label>
                                    <input type="text" name="<?= esc_attr($s_name) ?>" value="<?= esc_attr($s_value) ?>" placeholder="<?= esc_attr($s_label) ?>" class="small-text">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <button type="button" class="button button-secondary" id="apisunat-add-branch"><?= esc_html__('Agregar sucursal', 'apisunatv2') ?></button>
        </div>
        <script>
        (function(){
            var wrap = document.getElementById('apisunat-branches');
            if(!wrap) return;
            document.getElementById('apisunat-add-branch').addEventListener('click', function(){
                var div = document.createElement('div');
                div.className = 'branch-row';
                div.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;align-items:center;flex-wrap:wrap;';
                var idx = wrap.querySelectorAll('.branch-row').length;
                div.innerHTML = '<input type="text" name="<?= esc_attr($baseName) ?>[' + idx + '][label]" placeholder="<?= esc_attr__('Etiqueta', 'apisunatv2') ?>" class="regular-text">' +
                    '<input type="text" name="<?= esc_attr($baseName) ?>[' + idx + '][persona_id]" placeholder="<?= esc_attr__('Persona ID', 'apisunatv2') ?>" class="regular-text">' +
                    '<input type="text" name="<?= esc_attr($baseName) ?>[' + idx + '][persona_token]" placeholder="<?= esc_attr__('Persona Token', 'apisunatv2') ?>" class="regular-text">' +
                    '<button type="button" class="button button-secondary apisunat-remove-branch"><?= esc_html__('Eliminar', 'apisunatv2') ?></button>' +
                    '<button type="button" class="button button-primary apisunat-test-branch"><?= esc_html__('Verificar', 'apisunatv2') ?></button>' +
                    '<span class="apisunat-branch-result" role="status" aria-live="polite"></span>' +
                    '<div class="branch-series" style="width:100%; margin-top:8px; padding-left: 20px;">' +
                        '<strong><?= esc_js(__('Series', 'apisunatv2')) ?></strong>' +
                        '<div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:4px;">' +
                            '<div><label style="display:block; font-size:12px;"><?= esc_js(__('Serie Factura', 'apisunatv2')) ?></label><input type="text" name="<?= esc_attr($baseName) ?>[' + idx + '][series][factura]" placeholder="<?= esc_attr__('Serie Factura', 'apisunatv2') ?>" class="small-text"></div>' +
                            '<div><label style="display:block; font-size:12px;"><?= esc_js(__('Serie Boleta', 'apisunatv2')) ?></label><input type="text" name="<?= esc_attr($baseName) ?>[' + idx + '][series][boleta]" placeholder="<?= esc_attr__('Serie Boleta', 'apisunatv2') ?>" class="small-text"></div>' +
                            '<div><label style="display:block; font-size:12px;"><?= esc_js(__('Serie NC Factura', 'apisunatv2')) ?></label><input type="text" name="<?= esc_attr($baseName) ?>[' + idx + '][series][nota_credito_factura]" placeholder="<?= esc_attr__('Serie NC Factura', 'apisunatv2') ?>" class="small-text"></div>' +
                            '<div><label style="display:block; font-size:12px;"><?= esc_js(__('Serie NC Boleta', 'apisunatv2')) ?></label><input type="text" name="<?= esc_attr($baseName) ?>[' + idx + '][series][nota_credito_boleta]" placeholder="<?= esc_attr__('Serie NC Boleta', 'apisunatv2') ?>" class="small-text"></div>' +
                        '</div>' +
                    '</div>';
                wrap.insertBefore(div, document.getElementById('apisunat-add-branch'));
            });
            wrap.addEventListener('click', function(e){
                if(e.target && e.target.classList.contains('apisunat-remove-branch')){
                    e.target.parentElement.remove();
                }
            });
        })();
        </script>
        <?php
    }

    private static function renderTaxManager(): void {
        if (!class_exists('WC_Tax')) {
            echo '<p class="notice notice-error">' . esc_html__('WooCommerce no disponible.', 'apisunatv2') . '</p>';
            return;
        }

        $nonce = wp_create_nonce(self::NONCE_ACTION);
        $tax_classes = array_merge(['standard'], \WC_Tax::get_tax_classes());
        $all_rates = [];
        
        foreach ($tax_classes as $class) {
            $rates = \WC_Tax::get_rates_for_tax_class($class);
            foreach ($rates as $rate) {
                $rate->tax_class = $class;
                $all_rates[] = $rate;
            }
        }
        ?>
        <h3><?= esc_html__('Tasas existentes', 'apisunatv2') ?></h3>
        <?php if (empty($all_rates)): ?>
            <p><?= esc_html__('No hay tasas configuradas.', 'apisunatv2') ?></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?= esc_html__('Clase', 'apisunatv2') ?></th>
                        <th><?= esc_html__('Nombre', 'apisunatv2') ?></th>
                        <th><?= esc_html__('Tasa (%)', 'apisunatv2') ?></th>
                        <th><?= esc_html__('Acciones', 'apisunatv2') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_rates as $rate): ?>
                        <tr>
                            <td><?= esc_html($rate->tax_class === 'standard' ? __('Estándar', 'apisunatv2') : $rate->tax_class) ?></td>
                            <td><?= esc_html($rate->tax_rate_name) ?></td>
                            <td><?= esc_html($rate->tax_rate) ?></td>
                            <td>
                                <button type="button" class="button button-small button-link-delete delete-tax-rate" data-rate-id="<?= esc_attr($rate->tax_rate_id) ?>">
                                    <?= esc_html__('Eliminar', 'apisunatv2') ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <h3 style="margin-top: 20px;"><?= esc_html__('Agregar nueva tasa', 'apisunatv2') ?></h3>
        
        <p class="description" style="margin-bottom: 15px;">
            <?= esc_html__('Crea impuestos para IGV (10%, 10.5%, 18%), Exonerado e Inafecto (0%), o combinaciones como IGV + ISC (impuesto compuesto).', 'apisunatv2') ?>
        </p>
        
        <table class="form-table">
            <tr>
                <th><label for="new_tax_rate"><?= esc_html__('Tasa (%)', 'apisunatv2') ?></label></th>
                <td><input type="number" step="0.01" min="0" max="100" id="new_tax_rate" value="18" class="small-text"></td>
            </tr>
            <tr>
                <th><label for="new_tax_name"><?= esc_html__('Nombre', 'apisunatv2') ?></label></th>
                <td><input type="text" id="new_tax_name" placeholder="<?= esc_attr__('Ej: IGV 18%, ISC 2%', 'apisunatv2') ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><label for="new_tax_class"><?= esc_html__('Clase', 'apisunatv2') ?></label></th>
                <td>
                    <select id="new_tax_class">
                        <?php foreach ($tax_classes as $class): ?>
                            <option value="<?= esc_attr($class) ?>">
                                <?= esc_html($class === 'standard' ? __('Estándar (Gravado)', 'apisunatv2') : $class) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="new_tax_compound"><?= esc_html__('Impuesto compuesto', 'apisunatv2') ?></label></th>
                <td>
                    <input type="checkbox" id="new_tax_compound" value="1">
                    <span class="description"><?= esc_html__('Marcar si este impuesto se aplica después de otros (ej. ISC después de IGV)', 'apisunatv2') ?></span>
                </td>
            </tr>
            <tr>
                <th></th>
                <td>
                    <button type="button" class="button button-primary" id="add-tax-rate-btn">
                        <?= esc_html__('Agregar Tasa', 'apisunatv2') ?>
                    </button>
                    <span id="tax-add-result" role="status" aria-live="polite"></span>
                </td>
            </tr>
        </table>
        
        <h3 style="margin-top: 30px;"><?= esc_html__('Configuraciones rápidas', 'apisunatv2') ?></h3>
        <p class="description" style="margin-bottom: 15px;">
            <?= esc_html__('Crea rápidamente las configuraciones de impuestos más comunes para SUNAT.', 'apisunatv2') ?>
        </p>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button type="button" class="button button-secondary quick-tax" data-rate="18" data-name="IGV 18%" data-class="standard">
                <?= esc_html__('IGV 18% (Estándar)', 'apisunatv2') ?>
            </button>
            <button type="button" class="button button-secondary quick-tax" data-rate="10" data-name="IGV 10%" data-class="gravado10">
                <?= esc_html__('IGV 10% (Clase Gravado 10%)', 'apisunatv2') ?>
            </button>
            <button type="button" class="button button-secondary quick-tax" data-rate="10.5" data-name="IGV 10.5%" data-class="gravado105">
                <?= esc_html__('IGV 10.5% (Gravado)', 'apisunatv2') ?>
            </button>
            <button type="button" class="button button-secondary quick-tax" data-rate="0" data-name="Exonerado 0%" data-class="exonerado">
                <?= esc_html__('Exonerado 0%', 'apisunatv2') ?>
            </button>
            <button type="button" class="button button-secondary quick-tax" data-rate="0" data-name="Inafecto 0%" data-class="inafecto">
                <?= esc_html__('Inafecto 0%', 'apisunatv2') ?>
            </button>
            <button type="button" class="button button-secondary quick-tax" data-rate="2" data-name="ISC 2%" data-class="standard" data-compound="1">
                <?= esc_html__('ISC 2% (Compuesto)', 'apisunatv2') ?>
            </button>
        </div>
        
        <script>
        (function($) {
            if (typeof $ === 'undefined') return;
            
            $(document).on('click', '.delete-tax-rate', function() {
                if (!confirm('<?= esc_js(__('¿Eliminar esta tasa?', 'apisunatv2')) ?>')) return;
                var btn = $(this);
                $.post(ajaxurl, {
                    action: 'apisunat_delete_tax_rate',
                    rate_id: btn.data('rate-id'),
                    nonce: '<?= $nonce ?>'
                }, function(response) {
                    if (response.success) location.reload();
                    else alert(response.data.message);
                });
            });
            
            function addTaxRate(rate, name, taxClass, compound) {
                $.post(ajaxurl, {
                    action: 'apisunat_add_tax_rate',
                    rate: rate,
                    name: name,
                    class: taxClass,
                    compound: compound ? 1 : 0,
                    nonce: '<?= $nonce ?>'
                }, function(response) {
                    if (response.success) location.reload();
                    else $('#tax-add-result').text(response.data.message).css('color', 'red');
                });
            }
            
            $('#add-tax-rate-btn').on('click', function() {
                var rate = $('#new_tax_rate').val();
                var name = $('#new_tax_name').val();
                var taxClass = $('#new_tax_class').val();
                var compound = $('#new_tax_compound').is(':checked') ? 1 : 0;
                addTaxRate(rate, name, taxClass, compound);
            });
            
            $('.quick-tax').on('click', function() {
                var btn = $(this);
                var rate = btn.data('rate');
                var name = btn.data('name');
                var taxClass = btn.data('class');
                var compound = btn.data('compound') ? 1 : 0;
                addTaxRate(rate, name, taxClass, compound);
            });
        })(jQuery);
        </script>
        <?php
    }

    private static function fieldId(string $key): string {
        return 'apisunatv2-' . str_replace('.', '-', $key);
    }

    private static function buildName(array $keys): string {
        $out = Options::OPTION_KEY;
        foreach ($keys as $key) {
            $out .= '[' . $key . ']';
        }
        return $out;
    }

    private static function renderTextInput(string $name, string $id, string $value, array $field): void {
        $class       = $field['class'] ?? 'regular-text';
        $placeholder = $field['placeholder'] ?? '';
        printf(
            "<input type='text' id='%s' name='%s' value='%s' class='%s' placeholder='%s' autocomplete='off'>",
            esc_attr($id),
            esc_attr($name),
            esc_attr($value),
            esc_attr($class),
            esc_attr($placeholder)
        );
    }

    private static function renderNumberInput(string $name, string $id, $value, array $field): void {
        $step = $field['step'] ?? '1';
        $min  = $field['min']  ?? '';
        $max  = $field['max']  ?? '';
        printf(
            "<input type='number' id='%s' name='%s' value='%s' step='%s' min='%s' max='%s'>",
            esc_attr($id),
            esc_attr($name),
            esc_attr((string) $value),
            esc_attr((string) $step),
            esc_attr((string) $min),
            esc_attr((string) $max)
        );
    }

    private static function renderCheckbox(string $name, string $id, bool $value): void {
        echo "<input type='hidden' name='" . esc_attr($name) . "' value='0'>";
        printf(
            "<input type='checkbox' id='%s' name='%s' value='1'%s>",
            esc_attr($id),
            esc_attr($name),
            $value ? ' checked' : ''
        );
    }

    private static function renderSelect(string $name, string $id, $value, array $field): void {
        echo "<select id='" . esc_attr($id) . "' name='" . esc_attr($name) . "'>";
        foreach ($field['options'] as $k => $label) {
            $selected = ((string) $value === (string) $k) ? ' selected' : '';
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr((string) $k),
                $selected,
                esc_html((string) $label)
            );
        }
        echo '</select>';
    }

    private static function schema(): array {
        return [
            [
                'id'    => 'apisunat_api',
                'title' => __('Datos de acceso', 'apisunatv2'),
                'desc'  => function (): void {
                    echo '<p>' . wp_kses_post(sprintf(
                        /* translators: %s: link to APISUNAT.com */
                        __('Obtén tus credenciales en %s', 'apisunatv2'),
                        '<a href="https://apisunat.com/" target="_blank" rel="noopener noreferrer">APISUNAT.com</a>'
                    )) . '</p>';
                    echo '<p class="description">' . esc_html__('Usa el botón "Verificar" junto a cada sucursal para probar la conexión.', 'apisunatv2') . '</p>';
                },
                'fields' => [
                    ['key' => 'api.meta_key', 'label' => __('Meta key de sucursal', 'apisunatv2'), 'type' => 'text', 'placeholder' => '_sucursal_label'],
                    ['key' => 'api.branches', 'label' => __('Sucursales', 'apisunatv2'), 'type' => 'branches'],
                ],
            ],
            [
                'id'    => 'apisunat_emision',
                'title' => __('Emisión', 'apisunatv2'),
                'desc'  => function (): void {
                    echo '<p class="description">' . esc_html__('Series globales. Si hay sucursales definidas, las series por sucursal (sección API) tienen prioridad.', 'apisunatv2') . '</p>';
                },
                'fields' => [
                    [
                        'key'     => 'emision.modo',
                        'label'   => __('Modo de emisión', 'apisunatv2'),
                        'type'    => 'select',
                        'options' => ['automatico' => __('Automático', 'apisunatv2'), 'manual' => __('Manual', 'apisunatv2')],
                        'default' => 'manual',
                    ],
                    [
                        'key'     => 'emision.estado_emision',
                        'label'   => __('Estado que dispara la emisión', 'apisunatv2'),
                        'type'    => 'select',
                        'options' => [
                            'wc-completed'  => __('Completado', 'apisunatv2'),
                            'wc-processing' => __('Procesando', 'apisunatv2'),
                            'wc-on-hold'    => __('En espera', 'apisunatv2'),
                        ],
                        'default' => 'wc-completed',
                    ],
                    ['key' => 'emision.series.factura',              'label' => __('Serie Factura', 'apisunatv2'),    'type' => 'text', 'default' => 'F001'],
                    ['key' => 'emision.series.boleta',               'label' => __('Serie Boleta', 'apisunatv2'),     'type' => 'text', 'default' => 'B001'],
                    ['key' => 'emision.series.nota_credito_factura', 'label' => __('Serie NC Factura', 'apisunatv2'), 'type' => 'text', 'default' => 'FC01'],
                    ['key' => 'emision.series.nota_credito_boleta',  'label' => __('Serie NC Boleta', 'apisunatv2'),  'type' => 'text', 'default' => 'BC01'],
                ],
            ],
            [
                'id'    => 'apisunat_impuestos',
                'title' => __('Impuestos', 'apisunatv2'),
                'desc'  => function (): void {
                    echo '<p class="description">';
                    echo esc_html__('La afectación SUNAT se determina automáticamente por la clase de impuesto asignada al producto en WooCommerce.', 'apisunatv2');
                    echo '</p>';

                    if (!function_exists('wc_tax_enabled') || !wc_tax_enabled()) {
                        echo '<p class="notice notice-error" style="padding:8px;display:flex;align-items:center;gap:8px;">';
                        echo esc_html__('Los impuestos están desactivados en WooCommerce. Es obligatorio para la integración.', 'apisunatv2');
                        echo ' <button type="button" id="apisunat-enable-tax" class="button button-primary">' . esc_html__('Activar impuestos', 'apisunatv2') . '</button>';
                        echo ' <span id="apisunat-enable-tax-result" role="status" aria-live="polite"></span>';
                        echo '</p>';
                        return;
                    }

                    if (!class_exists('WC_Tax')) {
                        echo '<p class="notice notice-error" style="padding:8px;">';
                        echo esc_html__('WooCommerce no está activo. Es obligatorio para la integración.', 'apisunatv2');
                        echo '</p>';
                        return;
                    }

                    global $wpdb;
                    $table = $wpdb->prefix . 'wc_tax_rate_classes';
                    $existing = $wpdb->get_col("SELECT slug FROM {$table}");

                    $check = ['gravado10' => 'Gravado 10%', 'gravado105' => 'Gravado 10.5%', 'exonerado' => 'Exonerado', 'inafecto' => 'Inafecto', 'gratuito' => 'Gratuito'];
                    $missing = [];
                    foreach ($check as $slug => $label) {
                        if (!in_array($slug, $existing, true)) {
                            $missing[] = $label;
                        }
                    }
                    if (!empty($missing)) {
                        echo '<p class="notice notice-warning" style="padding:8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
                        echo esc_html(sprintf(__('Faltan clases de impuestos: %s. Son necesarias para la integración.', 'apisunatv2'), implode(', ', $missing)));
                        echo ' <button type="button" id="apisunat-create-classes" class="button button-primary">' . esc_html__('Crear clases de impuestos', 'apisunatv2') . '</button>';
                        echo ' <span id="apisunat-classes-result" role="status" aria-live="polite"></span>';
                        echo '</p>';
                    } else {
                        echo '<p class="notice notice-success" style="padding:8px;">';
                        echo esc_html__('Clases de impuestos configuradas: Exonerado, Inafecto, Gratuito', 'apisunatv2');
                        echo '</p>';
                    }
                },
                'fields' => [
                    ['key' => 'tax_manager', 'label' => __('Gestor de Impuestos', 'apisunatv2'), 'type' => 'tax_manager'],
                ],
            ],
            [
                'id'    => 'apisunat_documento',
                'title' => __('Documento del cliente', 'apisunatv2'),
                'fields' => [
                    ['key' => 'documento_cliente.require_for_factura', 'label' => __('Requerir para factura', 'apisunatv2'),  'type' => 'checkbox', 'default' => true],
                    ['key' => 'documento_cliente.require_for_boleta',  'label' => __('Requerir para boleta', 'apisunatv2'),   'type' => 'checkbox'],
                    ['key' => 'documento_cliente.no_doc',              'label' => __('Permitir sin documento', 'apisunatv2'), 'type' => 'checkbox'],
                ],
            ],
            [
                'id'    => 'apisunat_detraccion',
                'title' => __('Detracción', 'apisunatv2'),
                'fields' => [
                    ['key' => 'detraccion.enabled',            'label' => __('Activar', 'apisunatv2'),      'type' => 'checkbox'],
                    ['key' => 'detraccion.default_percentage', 'label' => __('Porcentaje %', 'apisunatv2'), 'type' => 'number', 'default' => 12, 'min' => 0, 'max' => 100],
                ],
            ],
            [
                'id'    => 'apisunat_avanzado',
                'title' => __('Avanzado', 'apisunatv2'),
                'fields' => [
                    ['key' => 'advanced.debug',           'label' => __('Debug', 'apisunatv2'),                'type' => 'checkbox'],
                    ['key' => 'advanced.custom_checkout', 'label' => __('Checkout personalizado', 'apisunatv2'), 'type' => 'checkbox'],
                    ['key' => 'advanced.checkout_mapping.tipo_comprobante', 'label' => __('Key Tipo CPE', 'apisunatv2'), 'type' => 'text', 'placeholder' => '_billing_apisunat_document_type'],
                    ['key' => 'advanced.checkout_mapping.tipo_documento',   'label' => __('Key Tipo Doc.', 'apisunatv2'),  'type' => 'text', 'placeholder' => '_billing_apisunat_customer_id_type'],
                    ['key' => 'advanced.checkout_mapping.numero_documento', 'label' => __('Key N° Doc.', 'apisunatv2'),   'type' => 'text', 'placeholder' => '_billing_apisunat_customer_id'],
                ],
            ],
        ];
    }
}
