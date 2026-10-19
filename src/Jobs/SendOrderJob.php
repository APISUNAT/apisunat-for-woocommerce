<?php
namespace Atm\Apisunatwp\Jobs;

use Atm\Apisunatwp\Exceptions\ApiRequestException;
use Atm\Apisunatwp\Logging\Logger;
use Atm\Apisunatwp\Services\ApiSunatService;

class SendOrderJob {

    public const ACTION = 'sunat_send_order';
    public const GROUP  = 'apisunatv2';

    private const MAX_ATTEMPTS = 4;
    private const RETRY_DELAYS = [300, 900, 3600];

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

        if (in_array((string) $order->get_meta('_apisunat_document_status'), ['PENDIENTE', 'ACEPTADO'], true)) {
            return;
        }

        try {
            ApiSunatService::send($order_id);
            $order->update_meta_data('_sunat_sent', 1);
            $order->delete_meta_data('_apisunat_send_attempts');
            $order->save();
        } catch (\Throwable $e) {
            $attempts = (int) $order->get_meta('_apisunat_send_attempts');
            $attempts++;
            $order->update_meta_data('_apisunat_send_attempts', $attempts);
            $order->save();

            Logger::instance()->error('SendOrderJob failed', [
                'order_id' => $order_id,
                'attempt'  => $attempts,
                'error'    => $e->getMessage(),
            ]);

            $isTransient = ($e instanceof ApiRequestException && $e->isTransient());

            if (!$isTransient) {
                $order->update_meta_data('_apisunat_document_status', 'ERROR');
                $order->save();
                $order->add_order_note('APISUNAT: ' . $e->getMessage());
                return;
            }

            if ($attempts >= self::MAX_ATTEMPTS) {
                $order->update_meta_data('_apisunat_document_status', 'ERROR');
                $order->save();
                $order->add_order_note(
                    sprintf(__('APISUNAT: agotados %d reintentos. Último error: %s', 'apisunatv2'), self::MAX_ATTEMPTS, $e->getMessage())
                );
                return;
            }

            $delay = self::RETRY_DELAYS[$attempts - 1] ?? end(self::RETRY_DELAYS);
            $order->add_order_note(
                sprintf(__('APISUNAT: error transitorio, reintento #%d en %d min. Error: %s', 'apisunatv2'), $attempts, $delay / 60, $e->getMessage())
            );

            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(
                    time() + $delay,
                    self::ACTION,
                    ['order_id' => $order_id],
                    self::GROUP,
                    true
                );
            }
        }
    }
}
