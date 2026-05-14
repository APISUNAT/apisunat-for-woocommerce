<?php
namespace Atm\Apisunatwp\Jobs;

use Atm\Apisunatwp\Exceptions\ApiRequestException;
use Atm\Apisunatwp\Logging\Logger;
use Atm\Apisunatwp\Services\ApiSunatService;

class SendOrderJob {

    public const ACTION = 'sunat_send_order';
    public const GROUP  = 'apisunatv2';

    public static function register(): void {
        add_action(self::ACTION, [self::class, 'handle']);
    }

    public static function handle(array $args): void {
        $order_id = (int) ($args['order_id'] ?? 0);
        if ($order_id === 0) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        try {
            ApiSunatService::send($order_id);
            $order->update_meta_data('_sunat_sent', 1);
            $order->save();
        } catch (\Throwable $e) {
            Logger::instance()->error('SendOrderJob failed', [
                'order_id' => $order_id,
                'error'    => $e->getMessage(),
            ]);
            $order->add_order_note('API Sunat: ' . $e->getMessage());

            $isPermanent = !($e instanceof ApiRequestException && $e->isTransient());
            if ($isPermanent) {
                $order->update_meta_data('_apisunat_document_status', 'ERROR');
                $order->save();
            }

            throw $e; // Surface failure to Action Scheduler (single-shot async actions are not auto-retried).
        }
    }
}
