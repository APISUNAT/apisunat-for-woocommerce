<?php
namespace Atm\Apisunatwp\Admin;

use Atm\Apisunatwp\Config\Catalogs;
use Atm\Apisunatwp\Config\Options;
use Atm\Apisunatwp\Jobs\SendOrderJob;
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
        add_action('wp_ajax_apisunat_send_pending',       [self::class, 'ajaxSendPending']);
        add_action('wp_ajax_apisunat_add_tax_rate',      [self::class, 'ajaxAddTaxRate']);
        add_action('admin_post_apisunatv2_save',         [self::class, 'handleSave']);
        add_action('admin_notices',                      [self::class, 'adminNotices']);
    }

    public static function addMenu(): void {
        add_submenu_page(
            'woocommerce',
            __('APISUNAT', 'apisunatv2'),
            __('APISUNAT', 'apisunatv2'),
            'manage_woocommerce',
            'apisunat',
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

    public static function adminNotices(): void {
    }

    public static function sanitize(array $input): array {
        // Cargar solo si es necesario y de forma parcial
        $output = [];

        if (isset($input['api']) && is_array($input['api'])) {
            $output['api']['personaId']    = sanitize_text_field((string) ($input['api']['personaId'] ?? ''));
            $output['api']['personaToken'] = sanitize_text_field((string) ($input['api']['personaToken'] ?? ''));

            $output['api']['serie01']  = sanitize_text_field((string) ($input['api']['serie01'] ?? ''));
            $output['api']['serie03']  = sanitize_text_field((string) ($input['api']['serie03'] ?? ''));
            $output['api']['serie07F'] = sanitize_text_field((string) ($input['api']['serie07F'] ?? ''));
            $output['api']['serie07B'] = sanitize_text_field((string) ($input['api']['serie07B'] ?? ''));
        }

        if (isset($input['issue']) && is_array($input['issue'])) {
            $issue = $input['issue'];
            $output['issue']['mode'] = in_array(($issue['mode'] ?? 'manual'), ['manual', 'automatico'], true)
                ? $issue['mode'] : 'manual';
            $output['issue']['trigger_status'] = in_array(($issue['trigger_status'] ?? 'wc-completed'), ['wc-completed', 'wc-processing', 'wc-on-hold'], true)
                ? $issue['trigger_status'] : 'wc-completed';
            $output['issue']['issue_time']  = !empty($issue['issue_time']);
            $output['issue']['shipping_cost'] = !empty($issue['shipping_cost']);
            $allowed = ['gravado10', 'gravado105', 'gravado18', 'exonerado', 'inafecto'];
            $output['issue']['default_tax_type'] = in_array(($issue['default_tax_type'] ?? 'gravado18'), $allowed, true)
                ? $issue['default_tax_type'] : 'gravado18';
        }

        if (isset($input['detraction']) && is_array($input['detraction'])) {
            $output['detraction']['enabled']            = !empty($input['detraction']['enabled']);
            $output['detraction']['detraction_type'] = sanitize_text_field((string) ($input['detraction']['detraction_type'] ?? ''));
            $output['detraction']['percentage'] = max(0, min(100, absint($input['detraction']['percentage'] ?? 12)));
            $output['detraction']['payment_method'] = sanitize_text_field((string) ($input['detraction']['payment_method'] ?? '001'));
            $output['detraction']['bank_account'] = sanitize_text_field((string) ($input['detraction']['bank_account'] ?? ''));
            $output['detraction']['exchange_rate'] = sanitize_text_field((string) ($input['detraction']['exchange_rate'] ?? ''));
        }

        if (isset($input['gre']) && is_array($input['gre'])) {
            $output['gre']['vehiculo_categoria'] = !empty($input['gre']['vehiculo_categoria']);
            if (isset($input['gre']['transportista']) && is_array($input['gre']['transportista'])) {
                $output['gre']['transportista']['nombre'] = sanitize_text_field((string) ($input['gre']['transportista']['nombre'] ?? ''));
                $output['gre']['transportista']['ruc']    = sanitize_text_field((string) ($input['gre']['transportista']['ruc'] ?? ''));
                $output['gre']['transportista']['mtc']    = sanitize_text_field((string) ($input['gre']['transportista']['mtc'] ?? ''));
            }
            if (isset($input['gre']['partida']) && is_array($input['gre']['partida'])) {
                $output['gre']['partida']['ubigeo']    = sanitize_text_field((string) ($input['gre']['partida']['ubigeo'] ?? ''));
                $output['gre']['partida']['direccion'] = sanitize_text_field((string) ($input['gre']['partida']['direccion'] ?? ''));
            }
        }

        if (isset($input['settings']) && is_array($input['settings'])) {
            $output['settings']['debug']           = !empty($input['settings']['debug']);
            $output['settings']['custom_checkout'] = !empty($input['settings']['custom_checkout']);
            $output['settings']['multi_branch']     = !empty($input['settings']['multi_branch']);
            $output['settings']['multi_branch_key']  = sanitize_text_field((string) ($input['settings']['multi_branch_key'] ?? ''));

            if (isset($input['settings']['tax_rate_mapping']) && is_array($input['settings']['tax_rate_mapping'])) {
                $allowedMap = ['gravado10', 'gravado105', 'gravado18', 'exonerado', 'inafecto'];
                foreach ($input['settings']['tax_rate_mapping'] as $rateId => $afectacion) {
                    $rateId = absint($rateId);
                    if ($rateId > 0 && in_array($afectacion, $allowedMap, true)) {
                        $output['settings']['tax_rate_mapping'][$rateId] = $afectacion;
                    }
                }
            }

            if (isset($input['settings']['checkout_mapping']) && is_array($input['settings']['checkout_mapping'])) {
                $mapping = $input['settings']['checkout_mapping'];
                foreach ([
                    'document_type_key', 'customer_id_type_key', 'customer_id_key',
                    'document_type_key_value_01', 'document_type_key_value_03',
                        'customer_id_type_value_-', 'customer_id_type_value_1', 'customer_id_type_value_6', 'customer_id_type_value_H', 'customer_id_type_value_7',
                        'customer_id_type_value_4', 'customer_id_type_value_E', 'customer_id_type_value_A', 'customer_id_type_value_G',
                        'customer_id_type_value_C', 'customer_id_type_value_D', 'customer_id_type_value_B', 'customer_id_type_value_0',
                ] as $k) {
                    $output['settings']['checkout_mapping'][$k] = sanitize_text_field((string) ($mapping[$k] ?? ($output['settings']['checkout_mapping'][$k] ?? '')));
                }
            }
        }

        if (isset($input['branches']) && is_array($input['branches'])) {
            $existing = Options::getValue('branches', []);
            if (!is_array($existing)) {
                $existing = [];
            }
            foreach ($input['branches'] as $i => $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                $existing[$i]['branch_name'] = sanitize_text_field((string) ($branch['branch_name'] ?? sprintf(__('Sucursal %d', 'apisunatv2'), $i + 1)));

                if (isset($branch['api'])) {
                    $sanitizedApi = [];
                    $sanitizedApi['personaId']    = sanitize_text_field((string) ($branch['api']['personaId'] ?? ''));
                    $sanitizedApi['personaToken'] = sanitize_text_field((string) ($branch['api']['personaToken'] ?? ''));

                    $sanitizedApi['serie01']  = sanitize_text_field((string) ($branch['api']['serie01'] ?? ''));
                    $sanitizedApi['serie03']  = sanitize_text_field((string) ($branch['api']['serie03'] ?? ''));
                    $sanitizedApi['serie07F'] = sanitize_text_field((string) ($branch['api']['serie07F'] ?? ''));
                    $sanitizedApi['serie07B'] = sanitize_text_field((string) ($branch['api']['serie07B'] ?? ''));
                    $existing[$i]['api'] = $sanitizedApi;
                }

                if (isset($branch['issue'])) {
                    $issue = $branch['issue'];
                    $sanitizedIssue = [];
                    $sanitizedIssue['mode'] = in_array(($issue['mode'] ?? 'manual'), ['manual', 'automatico'], true) ? $issue['mode'] : 'manual';
                    $sanitizedIssue['trigger_status'] = in_array(($issue['trigger_status'] ?? 'wc-completed'), ['wc-completed', 'wc-processing', 'wc-on-hold'], true) ? $issue['trigger_status'] : 'wc-completed';
                    $sanitizedIssue['issue_time']  = !empty($issue['issue_time']);
                    $sanitizedIssue['shipping_cost'] = !empty($issue['shipping_cost']);
                    $sanitizedIssue['no_customer_data'] = !empty($issue['no_customer_data']);
                    $allowed = ['gravado10', 'gravado105', 'gravado18', 'exonerado', 'inafecto'];
                    $sanitizedIssue['default_tax_type'] = in_array(($issue['default_tax_type'] ?? 'gravado18'), $allowed, true)
                        ? $issue['default_tax_type'] : 'gravado18';
                    $existing[$i]['issue'] = $sanitizedIssue;
                }

                if (isset($branch['detraction'])) {
                    $d = $branch['detraction'];
                    $sanitizedDetraction = [];
                    $sanitizedDetraction['enabled']            = !empty($d['enabled']);
                    $sanitizedDetraction['detraction_type']   = sanitize_text_field((string) ($d['detraction_type'] ?? ''));
                    $sanitizedDetraction['percentage'] = max(0, min(100, absint($d['percentage'] ?? 12)));
                    $sanitizedDetraction['payment_method']  = sanitize_text_field((string) ($d['payment_method'] ?? '001'));
                    $sanitizedDetraction['bank_account'] = sanitize_text_field((string) ($d['bank_account'] ?? ''));
                    $sanitizedDetraction['exchange_rate']  = sanitize_text_field((string) ($d['exchange_rate'] ?? ''));
                    $existing[$i]['detraction'] = $sanitizedDetraction;
                }

                if (isset($branch['gre']) && is_array($branch['gre'])) {
                    $g = $branch['gre'];
                    $sanitizedGre = [];
                    $sanitizedGre['vehiculo_categoria'] = !empty($g['vehiculo_categoria']);
                    if (isset($g['transportista']) && is_array($g['transportista'])) {
                        $sanitizedGre['transportista']['nombre'] = sanitize_text_field((string) ($g['transportista']['nombre'] ?? ''));
                        $sanitizedGre['transportista']['ruc']    = sanitize_text_field((string) ($g['transportista']['ruc'] ?? ''));
                        $sanitizedGre['transportista']['mtc']    = sanitize_text_field((string) ($g['transportista']['mtc'] ?? ''));
                    }
                    if (isset($g['partida']) && is_array($g['partida'])) {
                        $sanitizedGre['partida']['ubigeo']    = sanitize_text_field((string) ($g['partida']['ubigeo'] ?? ''));
                        $sanitizedGre['partida']['direccion'] = sanitize_text_field((string) ($g['partida']['direccion'] ?? ''));
                    }
                    $existing[$i]['gre'] = $sanitizedGre;
                }
            }
            $output['branches'] = $existing;
        }

        Options::flushCache();

        if (!empty($output['settings']['multi_branch']) && empty($output['branches'])) {
            $existing = Options::get();
            $output['branches'] = [[
                'branch_name' => __('Sucursal 1', 'apisunatv2'),
                'api'        => $existing['api'] ?? [],
                'issue'    => $existing['issue'] ?? [],
                'detraction' => $existing['detraction'] ?? [],
                'gre'      => $existing['gre'] ?? [],
            ]];
        }

        return $output;
    }

    public static function ajaxTestApi(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permiso denegado', 'apisunatv2')], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $personaId    = isset($_POST['personaId'])    ? sanitize_text_field(wp_unslash($_POST['personaId']))    : '';
        $personaToken = isset($_POST['personaToken']) ? sanitize_text_field(wp_unslash($_POST['personaToken'])) : '';

        if ($personaId === '' || $personaToken === '') {
            $branch = Options::resolveBranch();
            $personaId    = $branch['personaId'];
            $personaToken = $branch['personaToken'];
        }

        if ($personaId === '' || $personaToken === '') {
            wp_send_json_error(['message' => __('Credenciales requeridas', 'apisunatv2')]);
        }

        $query = add_query_arg([
            'personaId'    => $personaId,
            'personaToken' => $personaToken,
            'limit'        => 1,
        ], ApiSunatService::BASE_URL . '/documents/getAll');

        $response = wp_remote_get($query, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);

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

    public static function ajaxSendPending(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permiso denegado', 'apisunatv2')], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!function_exists('as_enqueue_async_action')) {
            wp_send_json_error(['message' => __('Action Scheduler no disponible', 'apisunatv2')]);
        }

        $enqueued = 0;
        $skipped  = 0;

        $triggerStatus = Options::getValue('issue.trigger_status', 'wc-completed');

        $page = 0;
        while ($page < 10) {
            $orders = wc_get_orders([
                'limit'  => self::SYNC_LIMIT,
                'offset' => $page * self::SYNC_LIMIT,
                'status' => [str_replace('wc-', '', $triggerStatus)],
                'return' => 'ids',
            ]);

            if (empty($orders)) {
                break;
            }

            foreach ($orders as $order_id) {
                $order = wc_get_order($order_id);
                if (!$order) {
                    continue;
                }

                $docStatus = (string) $order->get_meta('_apisunat_document_status');
                if (in_array($docStatus, ['PENDIENTE', 'ACEPTADO'], true)) {
                    $skipped++;
                    continue;
                }

                if ($order->get_meta('_apisunat_send_attempts')) {
                    $skipped++;
                    continue;
                }

                as_enqueue_async_action(SendOrderJob::ACTION, ['order_id' => $order_id], SendOrderJob::GROUP);
                $enqueued++;
            }

            $page++;
        }

        $stats = self::statusCounts();

        wp_send_json_success([
            'message'   => sprintf(__('%d órdenes encoladas, %d omitidas', 'apisunatv2'), $enqueued, $skipped),
            'enqueued'  => $enqueued,
            'total'     => array_sum($stats),
            'pendiente' => $stats['PENDIENTE'] ?? 0,
            'aceptado'  => $stats['ACEPTADO']  ?? 0,
            'error'     => $stats['ERROR']     ?? 0,
        ]);
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

        $multi_branch = self::isMultiBranch();

        if ($multi_branch && isset($_GET['add_branch'])) {
            $ids = Options::getBranchIds();
            $id  = Options::addBranch(['branch_name' => sprintf(__('Sucursal %d', 'apisunatv2'), count($ids) + 1)]);
            $ids = Options::getBranchIds();
            $newIndex = array_search($id, $ids, true);
            wp_redirect(add_query_arg(['branch' => $newIndex !== false ? $newIndex : count($ids) - 1], remove_query_arg('add_branch')));
            exit;
        }

        if ($multi_branch && isset($_GET['delete_branch'])) {
            $delIdx = (int) $_GET['delete_branch'];
            $ids    = Options::getBranchIds();
            if (isset($ids[$delIdx]) && count($ids) > 1) {
                Options::deleteBranch($ids[$delIdx]);
                $ids = Options::getBranchIds();
            }
            $redirectIdx = min($delIdx, max(0, count($ids) - 1));
            wp_redirect(add_query_arg(['branch' => $redirectIdx], remove_query_arg('delete_branch')));
            exit;
        }

        $currentTab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'api';
        $tabs      = self::tabs();
        if (!isset($tabs[$currentTab])) {
            $currentTab = 'api';
        }

        $multi_branch = self::isMultiBranch();
        $branchTabs = [];
        $currentBranch = 0;
        if ($multi_branch && in_array($currentTab, ['api', 'issue', 'detraction', 'gre'], true)) {
            $branches = Options::getValue('branches', []);
            if (empty($branches)) {
                $branches = [['branch_name' => __('Sucursal 1', 'apisunatv2')]];
            }
            foreach ($branches as $i => $branch) {
                $branchTabs[$i] = $branch['branch_name'] ?? sprintf(__('Sucursal %d', 'apisunatv2'), $i + 1);
            }
            $currentBranch = isset($_GET['branch']) ? max(0, (int) $_GET['branch']) : 0;
            if (!isset($branchTabs[$currentBranch])) {
                $currentBranch = 0;
            }
        }
        ?>
        <div class="wrap apisunatv2-settings">
            <h1><?= esc_html(isset($_GET['view']) && $_GET['view'] === 'settings' ? __('APISUNAT - CONFIGURACIÓN AVANZADA', 'apisunatv2') : get_admin_page_title()) ?>
                <?php if (!isset($_GET['view']) || $_GET['view'] !== 'settings'): ?>
                <a href="<?= esc_url(add_query_arg(array_merge(['page' => 'apisunat', 'view' => 'settings'], isset($_GET['branch']) ? ['branch' => (int) $_GET['branch']] : []), admin_url('admin.php'))) ?>" class="dashicons dashicons-admin-generic" title="<?= esc_attr__('Configuración avanzada', 'apisunatv2') ?>" style="margin-left:8px;vertical-align:middle;"></a>
                <?php endif; ?>
            </h1>

            <?php if (isset($_GET['view']) && $_GET['view'] === 'settings'): ?>
            <p style="margin:0 0 12px;"><a href="<?= esc_url(remove_query_arg('view', add_query_arg(array_merge(['page' => 'apisunat'], isset($_GET['branch']) ? ['branch' => (int) $_GET['branch']] : []), admin_url('admin.php')))) ?>">&larr; <?= esc_html__('Volver', 'apisunatv2') ?></a></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="apisunatv2_save">
                <?php wp_nonce_field('apisunatv2_settings_save'); ?>

                <?php if (!isset($_GET['view']) || $_GET['view'] !== 'settings'): ?>
                <?php if (!empty($branchTabs)): ?>
                <ul class="subsubsub" style="margin:0 0 8px;float: none;">
                    <?php foreach ($branchTabs as $i => $branchLabel): ?>
                    <li>
                        <a href="<?= esc_url(add_query_arg(['branch' => $i])) ?>" class="<?= $currentBranch === $i ? 'current' : '' ?>"><?= esc_html($branchLabel) ?></a>
                        <?php if ($i === $currentBranch && count($branchTabs) > 1): ?>
                        <a href="<?= esc_url(add_query_arg(['delete_branch' => $i])) ?>" class="apisunat-delete-branch" style="color:#b32d2e;text-decoration:none;margin-left:2px;" title="<?= esc_attr__('Eliminar sucursal', 'apisunatv2') ?>">✕</a>
                        <?php endif; ?>
                        <?= $i < count($branchTabs) - 1 ? ' |' : '' ?>
                    </li>
                    <?php endforeach; ?>
                    <li>
                        <a href="<?= esc_url(add_query_arg(['add_branch' => '1'])) ?>" class="button button-small" style="margin-left:8px;vertical-align:baseline;height:auto;line-height:2;min-height:0;"><?= esc_html__('+', 'apisunatv2') ?></a>
                    </li>
                </ul>
                <div style="margin-bottom:8px;">
                    <label style="display:block;margin-bottom:2px;font-weight:600;"><?= esc_html__('Nombre de sucursal', 'apisunatv2') ?></label>
                    <input type="text" name="apisunatv2_settings[branches][<?= (int) $currentBranch ?>][branch_name]" value="<?= esc_attr($branchTabs[$currentBranch]) ?>" class="regular-text" style="max-width:300px;">
                </div>
                <?php endif; ?>
                <nav class="nav-tab-wrapper apisunat-tabs" aria-label="<?= esc_attr__('Secciones', 'apisunatv2') ?>">
                    <?php foreach ($tabs as $id => $tab): ?>
                        <a href="<?= esc_url(add_query_arg(array_merge(['page' => 'apisunat', 'tab' => $id], isset($_GET['branch']) ? ['branch' => (int) $_GET['branch']] : []), admin_url('admin.php'))) ?>"
                           class="nav-tab <?= $currentTab === $id ? 'nav-tab-active' : '' ?>">
                            <?= esc_html($tab['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <?php endif; ?>

            <div class="apisunat-content">
                <?php if (!isset($_GET['view']) || $_GET['view'] !== 'settings'): ?>
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
                    <button type="button" id="send-pending-btn" class="button button-primary"><?= esc_html__('Enviar pendientes', 'apisunatv2') ?></button>
                    <span id="send-pending-result" role="status" aria-live="polite" style="margin-left:6px;"></span>
                </div>
                <?php endif; ?>

                    <table class="form-table" role="presentation">
                        <?php
                        $schema    = self::schema();
                        $isSettingsView = isset($_GET['view']) && $_GET['view'] === 'settings';

                        if ($isSettingsView) {
                            // Render only the advanced/settings section
                            foreach ($schema as $section) {
                                if ($section['id'] !== 'apisunat_avanzado') {
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
                        } else {
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
                                $branchPrefix = '';
                                if ($multi_branch && in_array($section['id'], ['apisunat_api', 'apisunat_issue', 'apisunat_detraction', 'apisunat_gre'], true)) {
                                    $branchPrefix = 'branches.' . $currentBranch . '.';
                                }
                                foreach ($section['fields'] as $field) {
                                    $field['key'] = $branchPrefix . $field['key'];
                                    $id = self::fieldId($field['key']);
                                    echo '<tr>';
                                    echo '<th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($field['label']) . '</label></th>';
                                    echo '<td>';
                                    self::renderField($field);
                                    echo '</td>';
                                    echo '</tr>';
                                }
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

    private static function isMultiBranch(): bool {
        return (bool) Options::getValue('settings.multi_branch', false);
    }

    private static function tabs(): array {
        return [
            'api'        => ['label' => __('API', 'apisunatv2'),        'section' => 'apisunat_api'],
            'issue'    => ['label' => __('Emisión', 'apisunatv2'),    'section' => 'apisunat_issue'],
            'detraction' => ['label' => __('Detracción', 'apisunatv2'), 'section' => 'apisunat_detraction'],
            'gre'        => ['label' => __('Guía de Remisión', 'apisunatv2'), 'section' => 'apisunat_gre'],
        ];
    }

    public static function renderField(array $field): void {
        $keys  = explode('.', $field['key']);
        $name  = self::buildName($keys);
        $id    = self::fieldId($field['key']);
        $value = Options::getValue($field['key'], $field['default'] ?? '');

        match ($field['type']) {
            'text'            => self::renderTextInput($name, $id, (string) $value, $field),
            'password'        => self::renderPasswordInput($name, $id, (string) $value),
            'number'          => self::renderNumberInput($name, $id, $value, $field),
            'checkbox'        => self::renderCheckbox($name, $id, (bool) $value),
            'select'          => self::renderSelect($name, $id, $value, $field),
            'api_credentials' => self::renderApiCredentials($keys),
            'tax_manager'     => self::renderTaxManager(),
            'tipo_detraction_select' => self::renderTipoDetractionSelect($name, $id, (string) $value),
            'checkout_mapping'       => self::renderCheckoutMapping(),
            default           => self::renderTextInput($name, $id, (string) $value, $field),
        };
    }

    private static function renderPasswordInput(string $name, string $id, string $value): void {
        printf(
            "<input type='password' id='%s' name='%s' value='%s' class='regular-text apisunat-token-input' autocomplete='new-password'>",
            esc_attr($id),
            esc_attr($name),
            esc_attr($value)
        );
        echo "<button type='button' class='button apisunat-toggle-token' aria-label='" . esc_attr__('Mostrar/ocultar token', 'apisunatv2') . "' style='margin-left:4px;'>👁</button>";
    }

    private static function renderApiCredentials(array $keys): void {
        $baseKeys = array_slice($keys, 0, -1);

        $personaIdValue    = Options::getValue(implode('.', array_merge($baseKeys, ['personaId'])), '');
        $personaTokenValue = Options::getValue(implode('.', array_merge($baseKeys, ['personaToken'])), '');
        $personaIdName     = self::buildName(array_merge($baseKeys, ['personaId']));
        $personaTokenName  = self::buildName(array_merge($baseKeys, ['personaToken']));
        ?>
        <div class="apisunat-credentials">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="text" name="<?= esc_attr($personaIdName) ?>" value="<?= esc_attr($personaIdValue) ?>" placeholder="<?= esc_attr__('Persona ID', 'apisunatv2') ?>" class="regular-text">
                <input type="password" name="<?= esc_attr($personaTokenName) ?>" value="<?= esc_attr($personaTokenValue) ?>" placeholder="<?= esc_attr__('Persona Token', 'apisunatv2') ?>" class="regular-text apisunat-token-input" autocomplete="new-password">
                <button type="button" class="button apisunat-toggle-token" aria-label="<?= esc_attr__('Mostrar/ocultar token', 'apisunatv2') ?>">👁</button>
                <button type="button" class="button button-primary apisunat-test-api"><?= esc_html__('Verificar', 'apisunatv2') ?></button>
                <span class="apisunat-api-result" role="status" aria-live="polite"></span>
            </div>
        </div>
        <?php
    }

    private static function renderTaxManager(): void {
        if (!function_exists('wc_tax_enabled') || !wc_tax_enabled()) {
            return;
        }
        if (!class_exists('WC_Tax')) {
            echo '<p class="notice notice-error">' . esc_html__('WooCommerce no disponible.', 'apisunatv2') . '</p>';
            return;
        }

        $nonce = wp_create_nonce(self::NONCE_ACTION);
        $tax_classes = array_merge(['standard'], \WC_Tax::get_tax_classes());
        $all_rates = [];
        $rateMapping = Options::getValue('settings.tax_rate_mapping', []);
        
        foreach ($tax_classes as $class) {
            $rates = \WC_Tax::get_rates_for_tax_class($class);
            foreach ($rates as $rate) {
                $rate->tax_class = $class;
                $all_rates[] = $rate;
            }
        }

        $afectacionOptions = [
            'gravado10' => __('Gravado 10%', 'apisunatv2'),
            'gravado105' => __('Gravado 10.5%', 'apisunatv2'),
            'gravado18' => __('Gravado 18%', 'apisunatv2'),
            'exonerado' => __('Exonerado', 'apisunatv2'),
            'inafecto' => __('Inafecto', 'apisunatv2'),
        ];
        ?>
        <h3><?= esc_html__('Mapeo de tasas', 'apisunatv2') ?></h3>
        <?php if (empty($all_rates)): ?>
            <p><?= esc_html__('No hay tasas configuradas.', 'apisunatv2') ?></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?= esc_html__('Clase', 'apisunatv2') ?></th>
                        <th><?= esc_html__('Nombre', 'apisunatv2') ?></th>
                        <th><?= esc_html__('Tasa (%)', 'apisunatv2') ?></th>
                        <th><?= esc_html__('Afectación SUNAT', 'apisunatv2') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_rates as $rate):
                        $current = $rateMapping[$rate->tax_rate_id] ?? '';
                    ?>
                        <tr>
                            <td><?= esc_html($rate->tax_class === 'standard' ? __('Estándar', 'apisunatv2') : $rate->tax_class) ?></td>
                            <td><?= esc_html($rate->tax_rate_name) ?></td>
                            <td><?= esc_html($rate->tax_rate) ?></td>
                            <td>
                                <select name="<?= esc_attr(Options::OPTION_KEY) ?>[settings][tax_rate_mapping][<?= esc_attr($rate->tax_rate_id) ?>]">
                                    <option value="" <?= selected($current, '', false) ?>><?= esc_html__('Seleccionar...', 'apisunatv2') ?></option>
                                    <?php foreach ($afectacionOptions as $val => $optLabel): ?>
                                        <option value="<?= esc_attr($val) ?>" <?= selected($current, $val, false) ?>><?= esc_html($optLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
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
        echo "<label class='checkbox style-e'>";
        printf(
            "<input type='checkbox' id='%s' name='%s' value='1'%s>",
            esc_attr($id),
            esc_attr($name),
            $value ? ' checked' : ''
        );
        echo "<div class='checkbox__checkmark'></div>";
        echo "</label>";
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

    private static function renderTipoDetractionSelect(string $name, string $id, string $value): void {
        $tipos = Catalogs::tiposDeDetraction();
        echo "<select id='" . esc_attr($id) . "' name='" . esc_attr($name) . "'>";
        foreach ($tipos as $k => $v) {
            $selected = ($value === (string) $k) ? ' selected' : '';
            printf(
                '<option value="%s"%s data-percent="%s">%s</option>',
                esc_attr((string) $k),
                $selected,
                esc_attr($v['percent'] !== null ? (string) $v['percent'] : ''),
                esc_html($v['label'])
            );
        }
        echo '</select>';
        $pctId = self::fieldId('detraction.percentage');
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var tipo = document.getElementById('<?= esc_js($id) ?>');
            var pct  = document.getElementById('<?= esc_js($pctId) ?>');
            if (tipo && pct) {
                var setPct = function() {
                    var opt = tipo.options[tipo.selectedIndex];
                    if (opt && opt.dataset.percent) {
                        pct.value = opt.dataset.percent;
                    }
                };
                tipo.addEventListener('change', setPct);
                setPct();
            }
        });
        </script>
        <?php
    }

    private static function renderCheckoutMapping(): void {
        $custom     = (bool) Options::getValue('settings.custom_checkout', false);
        $mapping    = Options::getValue('settings.checkout_mapping', []);
        $prefix     = Options::OPTION_KEY . '[settings][checkout_mapping]';
        ?>
        <div id="checkout-mapping-fields" style="<?= $custom ? '' : 'display:none' ?>">
            <table class="form-table" style="margin:0">
                <tr>
                    <th style="width:180px"><?= esc_html__('Key Tipo CPE', 'apisunatv2') ?></th>
                    <td><input type="text" name="<?= esc_attr($prefix) ?>[document_type_key]" value="<?= esc_attr($mapping['document_type_key'] ?? '') ?>" placeholder="_billing_apisunat_document_type" class="regular-text"></td>
                </tr>
                <tr>
                    <th><?= esc_html__('Valor FACTURA', 'apisunatv2') ?></th>
                    <td><input type="text" name="<?= esc_attr($prefix) ?>[document_type_key_value_01]" value="<?= esc_attr($mapping['value_01'] ?? '01') ?>" placeholder="01" class="regular-text"></td>
                </tr>
                <tr>
                    <th><?= esc_html__('Valor BOLETA', 'apisunatv2') ?></th>
                    <td><input type="text" name="<?= esc_attr($prefix) ?>[document_type_key_value_03]" value="<?= esc_attr($mapping['value_03'] ?? '03') ?>" placeholder="03" class="regular-text"></td>
                </tr>
                <tr>
                    <th><?= esc_html__('Key Tipo Doc.', 'apisunatv2') ?></th>
                    <td><input type="text" name="<?= esc_attr($prefix) ?>[customer_id_type_key]" value="<?= esc_attr($mapping['customer_id_type_key'] ?? '') ?>" placeholder="_billing_apisunat_customer_id_type" class="regular-text"></td>
                </tr>
                <tr>
                    <th><?= esc_html__('Valor SIN DOC.', 'apisunatv2') ?></th>
                    <td><input type="text" name="<?= esc_attr($prefix) ?>[customer_id_type_value_-]" value="<?= esc_attr($mapping['customer_id_type_value_-'] ?? '-') ?>" placeholder="-" class="regular-text"></td>
                </tr>
                <tr>
                    <th><?= esc_html__('Valor DNI', 'apisunatv2') ?></th>
                    <td><input type="text" name="<?= esc_attr($prefix) ?>[customer_id_type_value_1]" value="<?= esc_attr($mapping['customer_id_type_value_1'] ?? '1') ?>" placeholder="1" class="regular-text"></td>
                </tr>
                <tr>
                    <th><?= esc_html__('Valor RUC', 'apisunatv2') ?></th>
                    <td><input type="text" name="<?= esc_attr($prefix) ?>[customer_id_type_value_6]" value="<?= esc_attr($mapping['customer_id_type_value_6'] ?? '6') ?>" placeholder="6" class="regular-text"></td>
                </tr>
                <tr>
                    <th><?= esc_html__('Valor CPP - Carné Temporal de Permanencia', 'apisunatv2') ?></th>
                    <td><input type="text" name="<?= esc_attr($prefix) ?>[customer_id_type_value_H]" value="<?= esc_attr($mapping['customer_id_type_value_H'] ?? 'H') ?>" placeholder="H" class="regular-text"></td>
                </tr>
                <tr>
                    <th><?= esc_html__('Valor PASAPORTE', 'apisunatv2') ?></th>
                    <td><input type="text" name="<?= esc_attr($prefix) ?>[customer_id_type_value_7]" value="<?= esc_attr($mapping['customer_id_type_value_7'] ?? '7') ?>" placeholder="7" class="regular-text"></td>
                </tr>
                <tr>
                    <th><?= esc_html__('Valor C. EXTRANJERÍA', 'apisunatv2') ?></th>
                    <td><input type="text" name="<?= esc_attr($prefix) ?>[customer_id_type_value_4]" value="<?= esc_attr($mapping['customer_id_type_value_4'] ?? '4') ?>" placeholder="4" class="regular-text"></td>
                </tr>
                <tr>
                    <th><?= esc_html__('Valor TAM - Tarjeta Andina de Migración', 'apisunatv2') ?></th>
                    <td><input type="text" name="<?= esc_attr($prefix) ?>[customer_id_type_value_E]" value="<?= esc_attr($mapping['customer_id_type_value_E'] ?? 'E') ?>" placeholder="E" class="regular-text"></td>
                </tr>
                <tr>
                    <th><?= esc_html__('Valor C. DIPLOMÁTICA', 'apisunatv2') ?></th>
                    <td><input type="text" name="<?= esc_attr($prefix) ?>[customer_id_type_value_A]" value="<?= esc_attr($mapping['customer_id_type_value_A'] ?? 'A') ?>" placeholder="A" class="regular-text"></td>
                </tr>
                <tr>
                    <th><?= esc_html__('Valor SALVOCONDUCTO', 'apisunatv2') ?></th>
                    <td><input type="text" name="<?= esc_attr($prefix) ?>[customer_id_type_value_G]" value="<?= esc_attr($mapping['customer_id_type_value_G'] ?? 'G') ?>" placeholder="G" class="regular-text"></td>
                </tr>
                <tr>
                    <th><?= esc_html__('Valor TIN - Tax Identification Number', 'apisunatv2') ?></th>
                    <td><input type="text" name="<?= esc_attr($prefix) ?>[customer_id_type_value_C]" value="<?= esc_attr($mapping['customer_id_type_value_C'] ?? 'C') ?>" placeholder="C" class="regular-text"></td>
                </tr>
                <tr>
                    <th><?= esc_html__('Valor IN - Identification Number', 'apisunatv2') ?></th>
                    <td><input type="text" name="<?= esc_attr($prefix) ?>[customer_id_type_value_D]" value="<?= esc_attr($mapping['customer_id_type_value_D'] ?? 'D') ?>" placeholder="D" class="regular-text"></td>
                </tr>
                <tr>
                    <th><?= esc_html__('Valor ID. PERS. NAT.', 'apisunatv2') ?></th>
                    <td><input type="text" name="<?= esc_attr($prefix) ?>[customer_id_type_value_B]" value="<?= esc_attr($mapping['customer_id_type_value_B'] ?? 'B') ?>" placeholder="B" class="regular-text"></td>
                </tr>
                <tr>
                    <th><?= esc_html__('Valor DOC. TRIB.', 'apisunatv2') ?></th>
                    <td><input type="text" name="<?= esc_attr($prefix) ?>[customer_id_type_value_0]" value="<?= esc_attr($mapping['customer_id_type_value_0'] ?? '0') ?>" placeholder="0" class="regular-text"></td>
                </tr>
                <tr>
                    <th><?= esc_html__('Key N° Doc.', 'apisunatv2') ?></th>
                    <td><input type="text" name="<?= esc_attr($prefix) ?>[customer_id_key]" value="<?= esc_attr($mapping['customer_id_key'] ?? '') ?>" placeholder="_billing_apisunat_customer_id" class="regular-text"></td>
                </tr>
            </table>
        </div>
        <script>
        (function() {
            var cb = document.getElementById('apisunatv2-settings-custom_checkout');
            var group = document.getElementById('checkout-mapping-fields');
            if (cb && group) {
                cb.addEventListener('change', function() {
                    group.style.display = this.checked ? '' : 'none';
                });
            }
        })();
        </script>
        <?php
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
                    echo '<p class="description">' . esc_html__('Usa el botón "Verificar" para probar la conexión.', 'apisunatv2') . '</p>';
                },
                'fields' => [
                    ['key' => 'api.credentials', 'label' => __('Credenciales API', 'apisunatv2'), 'type' => 'api_credentials'],
                    ['key' => 'api.serie01', 'label' => __('Serie Factura', 'apisunatv2'), 'type' => 'text', 'placeholder' => 'F001'],
                    ['key' => 'api.serie03', 'label' => __('Serie Boleta', 'apisunatv2'), 'type' => 'text', 'placeholder' => 'B001'],
                    ['key' => 'api.serie07F', 'label' => __('Serie NC Factura', 'apisunatv2'), 'type' => 'text', 'placeholder' => 'FC01'],
                    ['key' => 'api.serie07B', 'label' => __('Serie NC Boleta', 'apisunatv2'), 'type' => 'text', 'placeholder' => 'BC01'],
                ],
            ],
            [
                'id'    => 'apisunat_issue',
                'title' => __('Emisión', 'apisunatv2'),
                'fields' => [
                    [
                        'key'     => 'issue.mode',
                        'label'   => __('Modo de emisión', 'apisunatv2'),
                        'type'    => 'select',
                        'options' => ['automatico' => __('Automático', 'apisunatv2'), 'manual' => __('Manual', 'apisunatv2')],
                        'default' => 'manual',
                    ],
                    [
                        'key'     => 'issue.trigger_status',
                        'label'   => __('Estado que dispara la emisión', 'apisunatv2'),
                        'type'    => 'select',
                        'options' => [
                            'wc-completed'  => __('Completado', 'apisunatv2'),
                            'wc-processing' => __('Procesando', 'apisunatv2'),
                            'wc-on-hold'    => __('En espera', 'apisunatv2'),
                            'wc-pending'    => __('Pendiente', 'apisunatv2'),
                        ],
                        'default' => 'wc-completed',
                    ],
                    ['key' => 'issue.no_customer_data', 'label' => __('Si no hay información del cliente crear una boleta simple', 'apisunatv2'), 'type' => 'checkbox'],
                    ['key' => 'issue.issue_time', 'label' => __('Incluir hora en la fecha de emisión', 'apisunatv2'), 'type' => 'checkbox'],
                    ['key' => 'issue.shipping_cost', 'label' => __('Incluir costo de envío en el total', 'apisunatv2'), 'type' => 'checkbox'],
                    ['key' => 'issue.default_tax_type', 'label' => __('Tipo de tributo por defecto', 'apisunatv2'), 'type' => 'select', 'options' => ['gravado18' => __('Gravado 18%', 'apisunatv2'), 'gravado10' => __('Gravado 10%', 'apisunatv2'), 'gravado105' => __('Gravado 10.5%', 'apisunatv2'), 'exonerado' => __('Exonerado', 'apisunatv2'), 'inafecto' => __('Inafecto', 'apisunatv2')], 'default' => 'gravado18'],
                ],
            ],
            [
                'id'    => 'apisunat_detraction',
                'title' => __('Detracción', 'apisunatv2'),
                'fields' => [
                    ['key' => 'detraction.detraction_type',      'label' => __('Tipo de detracción', 'apisunatv2'),          'type' => 'tipo_detraction_select', 'options' => Catalogs::tipoDeDetractionOptions()],
                    ['key' => 'detraction.percentage',    'label' => __('Porcentaje %', 'apisunatv2'),             'type' => 'number', 'default' => 12, 'min' => 0, 'max' => 100],
                    ['key' => 'detraction.payment_method',      'label' => __('Medio de pago', 'apisunatv2'),          'type' => 'select', 'options' => Catalogs::mediosDePago()],
                    ['key' => 'detraction.bank_account',   'label' => __('Cuenta Bancaria', 'apisunatv2'),        'type' => 'text', 'placeholder' => '00-000-000000'],
                    ['key' => 'detraction.exchange_rate',   'label' => __('Tipo de cambio', 'apisunatv2'), 'type' => 'text', 'placeholder' => 'USD=3.5,EUR=4.0,COP=0.7'],
                    ['key' => 'detraction.enabled',           'label' => __('Aplicar automáticamente para ventas superiores a 700 PEN', 'apisunatv2'), 'type' => 'checkbox'],
                ],
            ],
            [
                'id'    => 'apisunat_gre',
                'title' => __('Guía de Remisión', 'apisunatv2'),
                'fields' => [
                    ['key' => 'gre.vehiculo_categoria', 'label' => __('Vehículos Categoría M1 o L', 'apisunatv2'), 'type' => 'checkbox'],
                    ['key' => 'gre.transportista.nombre', 'label' => __('Nombre transportista', 'apisunatv2'), 'type' => 'text'],
                    ['key' => 'gre.transportista.ruc', 'label' => __('RUC transportista', 'apisunatv2'), 'type' => 'text'],
                    ['key' => 'gre.transportista.mtc', 'label' => __('Registro MTC', 'apisunatv2'), 'type' => 'text'],
                    ['key' => 'gre.partida.ubigeo', 'label' => __('Ubigeo partida', 'apisunatv2'), 'type' => 'text', 'placeholder' => '150101'],
                    ['key' => 'gre.partida.direccion', 'label' => __('Dirección partida', 'apisunatv2'), 'type' => 'text'],
                ],
            ],
            [
                'id'    => 'apisunat_avanzado',
                'title' => __('Avanzado', 'apisunatv2'),
                'desc'  => function (): void {

                    if (!function_exists('wc_tax_enabled') || !wc_tax_enabled()) {
                        echo '<p class="notice" style="padding:8px;display:flex;align-items:center;gap:8px;">';
                        echo esc_html__('Los impuestos están desactivados en WooCommerce, por lo que únicamente se tomará en cuenta el tipo de tributo por defecto.', 'apisunatv2');
                        echo ' <button type="button" id="apisunat-enable-tax" class="button button-primary">' . esc_html__('Activar impuestos', 'apisunatv2') . '</button>';
                        echo ' <span id="apisunat-enable-tax-result" role="status" aria-live="polite"></span>';
                        echo '</p>';
                        return;
                    }

                    if (!class_exists('WC_Tax')) {
                        echo '<p class="notice notice-error" style="padding:8px;">';
                        echo esc_html__('WooCommerce no está activo.', 'apisunatv2');
                        echo '</p>';
                        return;
                    }
                },
                'fields' => [
                    ['key' => 'tax_manager', 'label' => __('Gestor de Impuestos', 'apisunatv2'), 'type' => 'tax_manager'],
                    ['key' => 'settings.custom_checkout',  'label' => __('Checkout personalizado', 'apisunatv2'),  'type' => 'checkbox'],
                    ['key' => 'settings.checkout_mapping', 'label' => '',                                          'type' => 'checkout_mapping'],
                    ['key' => 'settings.multi_branch',      'label' => __('Multisucursal', 'apisunatv2'),             'type' => 'checkbox'],
                    ['key' => 'settings.multi_branch_key',   'label' => __('Meta key de sucursal', 'apisunatv2'),      'type' => 'text', 'placeholder' => '_billing_apisunat_branch'],
                    ['key' => 'settings.debug',            'label' => __('DEBUG (No activar)', 'apisunatv2'),                  'type' => 'checkbox'],
                ],
            ],
        ];
    }
}
