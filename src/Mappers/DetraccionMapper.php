<?php
namespace Atm\Apisunatwp\Mappers;

use Atm\Apisunatwp\Config\Options;

class DetraccionMapper {

    public static function resolve(?\WC_Product $product, $item): ?array {
        if (!Options::getValue('detraccion.enabled', false)) {
            return null;
        }

        $productId = $product?->get_id() ?? 0;
        if ($productId === 0) {
            return null;
        }

        $productEnabled = get_post_meta($productId, '_sunat_detraccion', true);
        if (!$productEnabled) {
            return null;
        }

        $percentage = (float) (get_post_meta($productId, '_sunat_detraccion_pct', true)
            ?: Options::getValue('detraccion.default_percentage', 12));

        $total  = (float) (method_exists($item, 'get_total') ? $item->get_total() : 0);
        $amount = $total * ($percentage / 100);

        return [
            'codigo'     => (string) (get_post_meta($productId, '_sunat_detraccion_codigo', true) ?: '12'),
            'porcentaje' => $percentage,
            'monto'      => round($amount, 2),
        ];
    }
}
