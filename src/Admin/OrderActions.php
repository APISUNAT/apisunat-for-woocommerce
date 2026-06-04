<?php
namespace Atm\Apisunatwp\Admin;

use Atm\Apisunatwp\Jobs\SendOrderJob;
use Atm\Apisunatwp\Services\ApiSunatService;

class OrderActions {

    public const NONCE_ACTION = 'apisunatv2_admin_nonce';

    public static function register(): void {
        add_action('wp_ajax_apisunat_emit_cpe',     [self::class, 'ajaxEmit']);
        add_action('wp_ajax_apisunat_void_order',   [self::class, 'ajaxVoid']);
        add_action('wp_ajax_apisunat_check_status', [self::class, 'ajaxCheckStatus']);

        // Registrar hooks básicos - WC los maneja internamente
        add_filter('bulk_actions-edit-shop_order',        [self::class, 'bulkActions'], 0);
        add_filter('handle_bulk_actions-edit-shop_order',  [self::class, 'bulkHandle'], 0, 3);

        // Para WC nueva UI (8+)
        add_filter('bulk_actions-wc-orders',               [self::class, 'bulkActions'], 0);
        add_filter('handle_bulk_actions-wc-orders',         [self::class, 'bulkHandle'], 0, 3);

        // Para WC nueva UI (10+)
        add_filter('bulk_actions-woocommerce_page_wc-orders',       [self::class, 'bulkActions'], 0);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [self::class, 'bulkHandle'], 0, 3);

        add_action('admin_notices', [self::class, 'bulkNotice']);
    }

    private static function authorize(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permiso denegado', 'apisunatv2')], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
    }

    public static function ajaxEmit(): void {
        self::authorize();

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if (!$order_id) {
            wp_send_json_error(['message' => __('Order ID requerido', 'apisunatv2')]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => __('Orden no encontrada', 'apisunatv2')]);
        }

        $order->update_meta_data('_billing_apisunat_detraction_enabled', isset($_POST['_billing_apisunat_detraction_enabled']) ? '1' : '0');
        if (isset($_POST['_billing_apisunat_detraction_tipo'])) {
            $order->update_meta_data('_billing_apisunat_detraction_tipo', sanitize_text_field(wp_unslash($_POST['_billing_apisunat_detraction_tipo'])));
        }
        if (isset($_POST['_billing_apisunat_detraction_payment_method'])) {
            $order->update_meta_data('_billing_apisunat_detraction_payment_method', sanitize_text_field(wp_unslash($_POST['_billing_apisunat_detraction_payment_method'])));
        }
        if (isset($_POST['_billing_apisunat_detraction_percentage'])) {
            $order->update_meta_data('_billing_apisunat_detraction_percentage', sanitize_text_field(wp_unslash($_POST['_billing_apisunat_detraction_percentage'])));
        }
        $order->save();

        try {
            ApiSunatService::send($order_id);
            wp_send_json_success(['message' => __('CPE emitido correctamente', 'apisunatv2')]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public static function ajaxVoid(): void {
        self::authorize();

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $reason   = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';

        if (!$order_id) {
            wp_send_json_error(['message' => __('Order ID requerido', 'apisunatv2')]);
        }
        if (strlen($reason) < 3) {
            wp_send_json_error(['message' => __('Motivo requiere 3+ caracteres', 'apisunatv2')]);
        }

        try {
            ApiSunatService::void($order_id, $reason);
            wp_send_json_success(['message' => __('CPE anulado correctamente', 'apisunatv2')]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public static function ajaxCheckStatus(): void {
        self::authorize();

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if (!$order_id) {
            wp_send_json_error(['message' => __('Order ID requerido', 'apisunatv2')]);
        }

        try {
            ApiSunatService::checkStatus($order_id);
            $order  = wc_get_order($order_id);
            $status = $order ? (string) $order->get_meta('_apisunat_document_status', true) : 'unknown';
            wp_send_json_success([
                'message' => sprintf(__('Estado actualizado: %s', 'apisunatv2'), $status),
                'status'  => $status,
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public static function bulkActions(array $actions): array {
        $actions['sunat_emit'] = __('Emitir CPE', 'apisunatv2');
        return $actions;
    }

    public static function bulkHandle(string $redirect, string $action, array $ids): string {
        if ($action !== 'sunat_emit') {
            return $redirect;
        }
        if (!current_user_can('manage_woocommerce')) {
            return $redirect;
        }

        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';
        $valid = $nonce && (wp_verify_nonce($nonce, 'bulk-orders') || wp_verify_nonce($nonce, 'bulk-posts'));
        if (!$valid) {
            return $redirect;
        }

        if (!function_exists('as_enqueue_async_action')) {
            return $redirect;
        }

        $enqueued = 0;
        $skipped  = 0;

        foreach ($ids as $id) {
            $order = wc_get_order($id);
            if (!$order) {
                continue;
            }

            $status = (string) $order->get_meta('_apisunat_document_status');
            if (in_array($status, ['PENDIENTE', 'ACEPTADO'], true)) {
                $skipped++;
                continue;
            }

            as_enqueue_async_action(SendOrderJob::ACTION, ['order_id' => (int) $id], SendOrderJob::GROUP);
            $enqueued++;
        }

        return add_query_arg(['sunat_ok' => $enqueued, 'sunat_skip' => $skipped], $redirect);
    }

    public static function bulkNotice(): void {
        if (!isset($_GET['sunat_ok']) && !isset($_GET['sunat_skip'])) {
            return;
        }
        $ok      = isset($_GET['sunat_ok'])   ? absint($_GET['sunat_ok'])   : 0;
        $skipped = isset($_GET['sunat_skip']) ? absint($_GET['sunat_skip']) : 0;

        $parts = [];
        if ($ok > 0)      $parts[] = sprintf(__('%d encolados para emitir', 'apisunatv2'), $ok);
        if ($skipped > 0) $parts[] = sprintf(__('%d omitidos (ya emitidos)', 'apisunatv2'), $skipped);

        if (empty($parts)) {
            return;
        }

        printf(
            '<div class="updated"><p>%s</p></div>',
            esc_html(implode(', ', $parts))
        );
    }
}
