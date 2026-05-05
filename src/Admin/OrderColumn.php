<?php
namespace Atm\Apisunatwp\Admin;

use Atm\Apisunatwp\Services\ApiSunatService;

class OrderColumn {

    public static function register(): void {
        add_filter('manage_edit-shop_order_columns',                          [self::class, 'addColumn'], 20);
        add_action('manage_shop_order_posts_custom_column',                   [self::class, 'columnContent'], 10, 2);
        add_filter('manage_woocommerce_page_wc-orders_columns',               [self::class, 'addColumn'], 20);
        add_action('manage_woocommerce_page_wc-orders_custom_column',         [self::class, 'columnContent'], 10, 2);
    }

    public static function addColumn(array $columns): array {
        $out = [];
        foreach ($columns as $k => $v) {
            $out[$k] = $v;
            if ($k === 'order_status') {
                $out['sunat_cpe'] = __('CPE', 'apisunatv2');
            }
        }
        return $out;
    }

    public static function columnContent(string $column, $order_id_or_order): void {
        if ($column !== 'sunat_cpe') {
            return;
        }

        $order = is_object($order_id_or_order) ? $order_id_or_order : wc_get_order((int) $order_id_or_order);
        if (!$order) {
            return;
        }

        $order_id = (int) $order->get_id();
        $status   = (string) $order->get_meta('_apisunat_document_status', true);

        if ($status === '') {
            self::emitBtn($order_id, $order->get_status());
            return;
        }

        if (in_array($status, ['ERROR', 'EXCEPCION'], true)) {
            printf('<span class="sunat-status %s">%s</span>&nbsp;', esc_attr(strtolower($status)), esc_html($status));
            self::emitBtn($order_id, $order->get_status());
            return;
        }

        if ($status === 'RECHAZADO') {
            printf('<span class="sunat-status %s">%s</span>', esc_attr(strtolower($status)), esc_html($status));
            return;
        }

        if ($status === 'PENDIENTE') {
            printf('<span class="sunat-status %s">%s</span>', esc_attr(strtolower($status)), esc_html($status));
            return;
        }

        $docId    = (string) $order->get_meta('_apisunat_document_id');
        $filename = (string) $order->get_meta('_apisunat_document_filename');
        if ($docId !== '') {
            printf(
                '<a href="%s" target="_blank" rel="noopener noreferrer" class="button">%s</a>',
                esc_url(ApiSunatService::BASE_URL . '/documents/' . rawurlencode($docId) . '/getPDF/default/' . rawurlencode($filename) . '.pdf'),
                esc_html__('PDF', 'apisunatv2')
            );
        }
    }

    private static function emitBtn(int $order_id, string $status): void {
        $estado_config = \Atm\Apisunatwp\Config\Options::getValue('emision.estado_emision', 'wc-completed');
        $estado_actual = 'wc-' . $status;
        $disabled = $estado_actual === $estado_config ? '' : 'disabled';
        printf(
            '<button data-order="%d" class="button sunat-emit-btn" %s>%s</button>',
            (int) $order_id,
            esc_attr($disabled),
            esc_html__('Emitir', 'apisunatv2')
        );
    }
}
