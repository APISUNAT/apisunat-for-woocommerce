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
        add_action('wp_ajax_apisunat_setup_tax_rate',     [self::class, 'ajaxSetupTaxRate']);
        add_action('wp_ajax_apisunat_enable_tax',         [self::class, 'ajaxEnableTax']);
        add_action('wp_ajax_apisunat_create_tax_classes', [self::class, 'ajaxCreateTaxClasses']);
        add_action('wp_ajax_apisunat_delete_tax_rate',    [self::class, 'ajaxDeleteTaxRate']);
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
        register_setting('apisunatv2_group', Options::OPTION_KEY, [
            'sanitize_callback' => [self::class, 'sanitize'],
        ]);

        foreach (self::schema() as $section) {
            add_settings_section(
                $section['id'],
                $section['title'],
                $section['desc'] ?? '__return_false',
                'apisunatv2_settings'
            );

            foreach ($section['fields'] as $field) {
                add_settings_field(
                    $field['key'],
                    $field['label'],
                    [self::class, 'renderField'],
                    'apisunatv2_settings',
                    $section['id'],
                    $field
                );
            }
        }
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
                    $branches[] = [
                        'label'        => sanitize_text_field((string) ($branch['label']        ?? '')),
                        'persona_id'    => sanitize_text_field((string) ($branch['persona_id']    ?? '')),
                        'persona_token' => sanitize_text_field((string) ($branch['persona_token'] ?? '')),
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

    public static function ajaxSetupTaxRate(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permiso denegado', 'apisunatv2')], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!class_exists('WC_Tax')) {
            wp_send_json_error(['message' => __('WooCommerce no disponible', 'apisunatv2')]);
        }

        $rate_value = isset($_POST['rate']) ? (float) wp_unslash($_POST['rate']) : 0.0;
        if ($rate_value <= 0 || $rate_value > 100) {
            wp_send_json_error(['message' => __('Tasa inválida', 'apisunatv2')]);
        }

        $rates = \WC_Tax::get_rates_for_tax_class('standard');
        foreach ($rates as $existing) {
            if (isset($existing->tax_rate) && abs((float) $existing->tax_rate - $rate_value) < 0.01) {
                wp_send_json_success([
                    'message' => sprintf(__('La tasa IGV %s%% ya está configurada', 'apisunatv2'), self::formatRate($rate_value)),
                ]);
            }
        }

        \WC_Tax::_insert_tax_rate([
            'tax_rate_country'  => '',
            'tax_rate_state'    => '',
            'tax_rate'          => (string) $rate_value,
            'tax_rate_name'     => 'IGV ' . self::formatRate($rate_value) . '%',
            'tax_rate_priority' => 1,
            'tax_rate_compound' => 0,
            'tax_rate_shipping' => 1,
            'tax_rate_order'    => count($rates),
            'tax_rate_class'    => '',
        ]);

        wp_send_json_success([
            'message' => sprintf(__('Tasa IGV %s%% creada correctamente', 'apisunatv2'), self::formatRate($rate_value)),
        ]);
    }

    private static function formatRate(float $rate): string {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
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

        global $wpdb;

        $classes = [
            'exonerado' => 'Exonerado',
            'inafecto'  => 'Inafecto',
            'gratuito'  => 'Gratuito',
        ];

        $table = $wpdb->prefix . 'wc_tax_rate_classes';
        $existing = $wpdb->get_col("SELECT slug FROM {$table}");
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

                <form method="post" action="options.php" id="apisunat-form">
                    <?php settings_fields('apisunatv2_group'); ?>
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
            'text'      => self::renderTextInput($name, $id, (string) $value, $field),
            'number'    => self::renderNumberInput($name, $id, $value, $field),
            'checkbox'  => self::renderCheckbox($name, $id, (bool) $value),
            'select'    => self::renderSelect($name, $id, $value, $field),
            'branches'  => self::renderBranches($name, $id, is_array($value) ? $value : []),
            default     => self::renderTextInput($name, $id, (string) $value, $field),
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
                    '<span class="apisunat-branch-result" role="status" aria-live="polite"></span>';
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

                    $rates = \WC_Tax::get_rates_for_tax_class('standard');
                    $hasRate = function (array $rates, float $value): bool {
                        foreach ($rates as $r) {
                            if (isset($r->tax_rate) && abs((float) $r->tax_rate - $value) < 0.01) {
                                return true;
                            }
                        }
                        return false;
                    };
                    if (empty($rates)) {
                        echo '<p class="notice notice-warning" style="padding:8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
                        echo esc_html__('No se encontró la tasa IGV (18%) en WooCommerce. Es obligatoria para la facturación electrónica.', 'apisunatv2');
                        foreach ([18, 10, 10.5] as $r) {
                            if (!$hasRate($rates, (float) $r)) {
                                printf(
                                    ' <button type="button" class="button button-primary apisunat-setup-tax" data-rate="%s">%s</button>',
                                    esc_attr((string) $r),
                                    esc_html(sprintf(__('Crear tasa IGV %s%%', 'apisunatv2'), $r))
                                );
                            }
                        }
                        echo ' <span id="apisunat-tax-result" role="status" aria-live="polite"></span>';
                        echo '</p>';
                    } else {
                        echo '<p class="notice notice-success" style="padding:8px;">';
                        echo esc_html__('Tasas IGV configuradas:', 'apisunatv2');
                        echo '<ul style="margin:4px 0 0 0;padding-left:16px;list-style:disc;">';
                        foreach ($rates as $rate) {
                            $pct = is_object($rate) && isset($rate->tax_rate) ? $rate->tax_rate : '?';
                            $name = is_object($rate) && isset($rate->tax_rate_name) ? $rate->tax_rate_name : 'IGV';
                            $id = is_object($rate) && isset($rate->tax_rate_id) ? $rate->tax_rate_id : 0;
                            echo '<li>' . esc_html($name . ': ' . $pct . '%');
                            if ($id) {
                                echo ' <button type="button" class="button button-small button-link-delete apisunat-delete-tax" data-rate-id="' . esc_attr($id) . '" style="margin-left:8px;">' . esc_html__('Eliminar', 'apisunatv2') . '</button>';
                            }
                            echo '</li>';
                        }
                        echo '</ul></p>';
                    }

                    global $wpdb;
                    $table = $wpdb->prefix . 'wc_tax_rate_classes';
                    $existing = $wpdb->get_col("SELECT slug FROM {$table}");

                    $check = ['exonerado' => 'Exonerado', 'inafecto' => 'Inafecto', 'gratuito' => 'Gratuito'];
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
                'fields' => [],
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
