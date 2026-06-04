<?php
namespace Atm\Apisunatwp\Hooks;

use Atm\Apisunatwp\Config\Options;
use Atm\Apisunatwp\Jobs\SendOrderJob;

class OrderHooks {

    public static function register(): void {
        $estados = ['completed', 'processing', 'on-hold', 'pending'];
        foreach ($estados as $estado) {
            add_action('woocommerce_order_status_' . $estado, [self::class, 'handle']);
        }
    }

    public static function handle(int|string $order_id): void {
        $order_id = (int) $order_id;
        $order    = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        if (Options::getValue('issue.mode', 'manual') !== 'automatico') {
            return;
        }

        $estado_config = Options::getValue('issue.trigger_status', 'wc-completed');
        $estado_actual = 'wc-' . $order->get_status();
        if ($estado_actual !== $estado_config) {
            return;
        }

        if ($order->get_meta('_apisunat_document_status', true)) {
            return;
        }

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(SendOrderJob::ACTION, ['order_id' => $order_id], SendOrderJob::GROUP);
        }
    }
}
