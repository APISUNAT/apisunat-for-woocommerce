<?php
namespace Atm\Apisunatwp\Mappers;

use Atm\Apisunatwp\Config\Options;

class DetraccionMapper {

    public const MONTO_MINIMO_SOLES = 700;

    public static function resolve(?\WC_Order $order, float $total): ?array {
        $enabled = Options::getValue('detraccion.enabled', false);
        $codigo = Options::getValue('detraccion.medio_de_pago', '001');
        $percentage = (float) Options::getValue('detraccion.porcentaje', 12);

        if (!$enabled) {
            return null;
        }

        $minSoles = self::MONTO_MINIMO_SOLES;
        $currency = get_woocommerce_currency();

        if ($currency === 'PEN') {
            if ($total < $minSoles) {
                return null;
            }
        } else {
            $tipoCambioStr = (string) Options::getValue('detraccion.tipo_de_cambio', '');
            $rate = null;
            if ($tipoCambioStr) {
                foreach (explode(',', $tipoCambioStr) as $pair) {
                    $parts = explode('=', trim($pair));
                    if (count($parts) === 2 && trim($parts[0]) === $currency) {
                        $rate = (float) trim($parts[1]);
                        break;
                    }
                }
            }
            $totalEnSoles = $rate ? $total * $rate : $total;
            if ($totalEnSoles < $minSoles) {
                return null;
            }
        }

        $manualCodigo = $order ? (string) $order->get_meta('_billing_apisunat_detraccion_medio_de_pago') : '';
        $manualPct = $order ? (string) $order->get_meta('_billing_apisunat_detraccion_porcentaje') : '';

        if ($order && !empty($manualCodigo)) {
            $codigo = $manualCodigo;
        }
        if ($order && !empty($manualPct)) {
            $percentage = (float) $manualPct;
        }

        $amount = $total * ($percentage / 100);

        return [
            'codigo'       => $codigo,
            'porcentaje'   => $percentage,
            'monto'        => round($amount, 2),
            'cuenta_banco' => (string) Options::getValue('detraccion.cuenta_bancaria', ''),
        ];
    }
}
