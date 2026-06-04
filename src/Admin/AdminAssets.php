<?php
namespace Atm\Apisunatwp\Admin;

class AdminAssets {

    private static string $plugin_url = '';

    public static function register(): void {
        self::$plugin_url = plugin_dir_url(dirname(__DIR__, 2)) . 'apisunatv2/';
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function enqueue(string $hook): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        $is_order = $screen && (
            $screen->post_type === 'shop_order' ||
            $screen->id === 'woocommerce_page_wc-orders' ||
            (isset($screen->post_type) && $screen->post_type === 'shop_order' && $screen->base === 'post')
        );
        $is_settings = $hook === 'woocommerce_page_apisunat';
        $is_logs     = $hook === 'woocommerce_page_apisunatv2-logs';

        if (!$is_order && !$is_settings && !$is_logs) {
            return;
        }

        wp_enqueue_style(
            'apisunatv2-admin',
            self::$plugin_url . 'assets/css/admin.css',
            [],
            APISUNATWP_VERSION
        );

        if ($is_order) {
            wp_enqueue_script(
                'apisunatv2-gre',
                self::$plugin_url . 'assets/js/gre-admin.js',
                ['jquery'],
                APISUNATWP_VERSION,
                true
            );
            wp_localize_script('apisunatv2-gre', 'apisunatv2_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce(OrderActions::NONCE_ACTION),
                'i18n'     => [
                    'saving'     => __('Guardando...', 'apisunatv2'),
                    'saved'      => __('Borrador guardado', 'apisunatv2'),
                    'emitting'   => __('Emitiendo...', 'apisunatv2'),
                    'voiding'    => __('Anulando...', 'apisunatv2'),
                    'error'      => __('Error', 'apisunatv2'),
                    'confirm'    => __('Confirmar Anulación', 'apisunatv2'),
                    'reasonShort'=> __('Motivo requiere al menos 3 caracteres', 'apisunatv2'),
                    'loadData'   => __('Cargando datos...', 'apisunatv2'),
                    'product'    => __('Producto', 'apisunatv2'),
                    'quantity'   => __('Cantidad', 'apisunatv2'),
                    'unit'       => __('Unidad', 'apisunatv2'),
                    'remove'     => __('Quitar', 'apisunatv2'),
                    'plate'      => __('Placa', 'apisunatv2'),
                    'authorization' => __('N° Autorización', 'apisunatv2'),
                    'entity'     => __('Entidad Emisora', 'apisunatv2'),
                    'docType'    => __('Tipo Doc.', 'apisunatv2'),
                    'docNum'     => __('N° Doc.', 'apisunatv2'),
                    'names'      => __('Nombres', 'apisunatv2'),
                    'lastnames'  => __('Apellidos', 'apisunatv2'),
                    'license'    => __('Licencia', 'apisunatv2'),
                ],
            ]);
        }

        if ($is_order || $is_settings) {
            wp_enqueue_script(
                'apisunatv2-admin',
                self::$plugin_url . 'assets/js/admin.js',
                ['jquery'],
                APISUNATWP_VERSION,
                true
            );

            $data = [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce(OrderActions::NONCE_ACTION),
                'i18n'     => [
                    'sending'      => __('Enviando...', 'apisunatv2'),
                    'verifying'    => __('Verificando...', 'apisunatv2'),
                    'voiding'      => __('Anulando...', 'apisunatv2'),
                    'emit'         => __('Emitir', 'apisunatv2'),
                    'verify'       => __('Verificar estado', 'apisunatv2'),
                    'confirm'      => __('Confirmar', 'apisunatv2'),
                    'reasonShort'  => __('Motivo requiere al menos 3 caracteres', 'apisunatv2'),
                    'genericError' => __('Error', 'apisunatv2'),
                ],
            ];

            if ($is_order) {
                wp_localize_script('apisunatv2-admin', 'apisunatv2_ajax', $data);
            }

            if ($is_settings) {
                wp_enqueue_script(
                    'apisunatv2-settings',
                    self::$plugin_url . 'assets/js/settings.js',
                    ['jquery'],
                    APISUNATWP_VERSION,
                    true
                );
                wp_localize_script('apisunatv2-settings', 'apisunatv2_admin', [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce(SettingsPage::NONCE_ACTION),
                'i18n'     => [
                    'verifying'    => __('Verificando...', 'apisunatv2'),
                    'syncing'      => __('Sincronizando...', 'apisunatv2'),
                    'sync'         => __('Sincronizar', 'apisunatv2'),
                    'sending'      => __('Encolando órdenes...', 'apisunatv2'),
                    'connError'    => __('Error de conexión', 'apisunatv2'),
                    'credsMissing' => __('Credenciales requeridas', 'apisunatv2'),
                ],
                ]);
            }
        }
    }
}
