<?php
namespace Atm\Apisunatwp\Admin;

class OrderFilters {

    public static function register(): void {
        add_filter('views_edit-shop_order',                                            [self::class, 'filterViews']);
        add_filter('woocommerce_order_list_table_prepare_items_query_args',            [self::class, 'filterQuery']);
    }

    public static function filterViews(array $views): array {
        $url = add_query_arg(
            ['post_type' => 'shop_order', 'sunat_filter' => 'pending'],
            admin_url('edit.php')
        );
        $views['sunat_pending'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($url),
            esc_html__('Pendientes SUNAT', 'apisunatv2')
        );
        return $views;
    }

    public static function filterQuery(array $args): array {
        $filter = isset($_GET['sunat_filter']) ? sanitize_key(wp_unslash($_GET['sunat_filter'])) : '';
        if ($filter !== 'pending') {
            return $args;
        }

        $args['meta_query'] = [
            'relation' => 'OR',
            ['key' => '_apisunat_document_status', 'compare' => 'NOT EXISTS'],
            ['key' => '_apisunat_document_status', 'value' => ['ACEPTADO', 'PENDIENTE'], 'compare' => 'NOT IN'],
        ];
        $args['status'] = 'completed';

        return $args;
    }
}
