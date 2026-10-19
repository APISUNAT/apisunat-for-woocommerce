<?php
namespace Atm\Apisunatwp\Mappers;

class OrderMapper {

    public static function map(\WC_Order $order): array {
        $created = $order->get_date_created();
        $meta    = \Atm\Apisunatwp\Config\Options::resolveCheckoutMeta($order);

        $detraction = DetractionMapper::resolve($order, (float) $order->get_total());

        return [
            'id'         => $order->get_id(),
            'number'     => $order->get_order_number(),
            'date'       => $created ? $created->date('Y-m-d\TH:i:s') : null,
            'type'       => $meta['tipo_comprobante'],
            'currency'   => $order->get_currency(),
            'customer'   => CustomerMapper::map($order),
            'items'      => ItemsMapper::map($order),
            'totals'     => [
                'subtotal' => round((float) $order->get_subtotal(),       2),
                'tax'      => round((float) $order->get_total_tax(),      2),
                'shipping' => round((float) $order->get_shipping_total(), 2),
                'discount' => round((float) $order->get_discount_total(), 2),
                'total'    => round((float) $order->get_total(),          2),
            ],
            'detraction' => $detraction,
        ];
    }
}
