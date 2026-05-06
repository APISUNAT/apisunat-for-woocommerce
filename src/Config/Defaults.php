<?php
namespace Atm\Apisunatwp\Config;

class Defaults {

    public static function get(): array {
        return [
            'api' => [
                'meta_key' => '',
                'branches' => [
                    [
                        'label'        => 'Principal',
                        'persona_id'    => '',
                        'persona_token' => '',
                        'series'        => [
                            'factura'             => '',
                            'boleta'              => '',
                            'nota_credito_factura' => '',
                            'nota_credito_boleta'  => '',
                        ],
                    ],
                ],
            ],
            'emision' => [
                'modo'              => 'manual',
                'estado_emision'    => 'wc-completed',
                'series'        => [
                    'factura'             => 'F001',
                    'boleta'              => 'B001',
                    'nota_credito_factura' => 'FC01',
                    'nota_credito_boleta'  => 'BC01',
                ],
                //'include_time'    => false,
                //'shipping_cost'   => false,
            ],
            'impuestos' => [],
            'detraccion' => [
                'enabled'           => false,
                'default_percentage' => 12,
            ],
            'documento_cliente' => [
                'require_for_factura' => true,
                'require_for_boleta'  => false,
                'no_doc'              => false,
            ],
            'advanced' => [
                'debug'          => false,
                'custom_checkout' => false,
                'checkout_mapping' => [
                    'tipo_comprobante'      => '_billing_apisunat_document_type',
                    'tipo_documento'        => '_billing_apisunat_customer_id_type',
                    'numero_documento'      => '_billing_apisunat_customer_id',
                ],
            ],
        ];
    }
}
