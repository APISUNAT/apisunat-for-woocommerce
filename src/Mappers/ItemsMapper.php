<?php
namespace Atm\Apisunatwp\Mappers;

class ItemsMapper {

    public static function map(\WC_Order $order): array {
        $items = [];
        $orderTotal = (float) $order->get_total();

        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $product = $item->get_product();

            $unitPrice    = (float) $item->get_total();
            $quantity     = (float) $item->get_quantity();
            $taxAmount    = (float) $item->get_total_tax();
            $unitPriceNet = $quantity > 0 ? $unitPrice / $quantity : 0.0;
            $tax_class    = $product?->get_tax_class() ?? '';
            $items[] = [
                'name'       => $item->get_name(),
                'quantity'   => $quantity,
                'unit_price' => round($unitPriceNet, 4),
                'total'      => round($unitPrice, 2),
                'tax'        => round($taxAmount, 2),
                'afectacion' => TaxMapper::resolve($tax_class),
                'sku'        => $product?->get_sku() ?: '',
            ];
        }

        $shippingTotal = (float) $order->get_shipping_total();
        if ($shippingTotal > 0) {
            $items[] = [
                'name'       => __('Envío', 'apisunatv2'),
                'quantity'   => 1,
                'unit_price' => round($shippingTotal, 4),
                'total'      => round($shippingTotal, 2),
                'tax'        => round((float) $order->get_shipping_tax(), 2),
                'afectacion' => TaxMapper::resolve(''),
                'sku'        => 'SHIPPING',
            ];
        }

        return $items;
    }
}
