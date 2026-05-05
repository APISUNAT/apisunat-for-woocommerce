<?php
namespace Atm\Apisunatwp\Jobs;

use Atm\Apisunatwp\Logging\Logger;
use Atm\Apisunatwp\Services\ApiSunatService;

class StatusCheckJob {

    public const ACTION   = 'sunat_check_pending_status';
    public const GROUP    = 'apisunatv2';
    private const INTERVAL = 300;
    private const BATCH   = 50;
    private const THROTTLE_US = 500000;

    public const ASYNC_ACTION = 'apisunat_check_status_async';
    public const ASYNC_GROUP  = 'apisunat-status';

    public static function register(): void {
        add_action(self::ACTION, [self::class, 'handle']);
        add_action('init', [self::class, 'scheduleCheck'], 20);
        add_action(self::ASYNC_ACTION, [self::class, 'handleAsync']);
    }

    public static function scheduleCheck(): void {
        if (!function_exists('as_has_scheduled_action') || !function_exists('as_schedule_recurring_action')) {
            return;
        }
        if (as_has_scheduled_action(self::ACTION, [], self::GROUP)) {
            return;
        }
        as_schedule_recurring_action(time() + self::INTERVAL, self::INTERVAL, self::ACTION, [], self::GROUP, true);
    }

    public static function handle(): void {
        $orders = wc_get_orders([
            'limit'      => self::BATCH,
            'return'     => 'objects',
            'meta_query' => [
                [
                    'key'     => '_apisunat_document_status',
                    'value'   => 'PENDIENTE',
                    'compare' => '=',
                ],
            ],
        ]);

        if (empty($orders)) {
            return;
        }

        $logger = Logger::instance();

        foreach ($orders as $order) {
            try {
                ApiSunatService::checkStatus($order->get_id());
            } catch (\Throwable $e) {
                $logger->warning('Status check failed', [
                    'order_id' => $order->get_id(),
                    'error'    => $e->getMessage(),
                ]);
            }
            usleep(self::THROTTLE_US);
        }
    }

    public static function handleAsync(int $order_id, int $attempt = 0): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        try {
            ApiSunatService::checkStatus($order_id);
        } catch (\Throwable $e) {
            Logger::instance()->warning('Async status check failed', [
                'order_id' => $order_id,
                'error'    => $e->getMessage(),
            ]);
        }

        // Si sigue pendiente y no hemos agotado intentos, reprogramar
        $status = (string) $order->get_meta('_apisunat_document_status');
        if ($status === 'PENDIENTE' && $attempt < 2) {
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(
                    self::ASYNC_ACTION,
                    ['order_id' => $order_id, 'attempt' => $attempt + 1],
                    self::ASYNC_GROUP
                );
            }
        }
    }
}
