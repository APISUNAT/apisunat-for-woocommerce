<?php
namespace Atm\Apisunatwp\Mappers;

class CustomerMapper {

    public static function map(\WC_Order $order): array {
        $meta = \Atm\Apisunatwp\Config\Options::resolveCheckoutMeta($order);

        return [
            'document_type'   => $meta['tipo_documento'],
            'document_number' => $meta['numero_documento'],
            'name'            => $order->get_formatted_billing_full_name(),
            'address'         => trim(implode(', ', array_filter([
                $order->get_billing_address_1(),
                $order->get_billing_address_2(),
                $order->get_billing_city(),
                $order->get_billing_state(),
                $order->get_billing_postcode(),
                $order->get_billing_country(),
            ]))),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
        ];
    }
}
