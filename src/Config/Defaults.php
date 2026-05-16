<?php
namespace Atm\Apisunatwp\Config;

class Defaults {

    public static function get(): array {
        return [
            'api' => [
                'branch_meta_key' => '',
                'branches' => [
                    [
                        'label'        => 'Principal',
                        'persona_id'    => '',
                        'persona_token' => '',
                        'series'        => [
                            'factura'             => 'F001',
                            'boleta'              => 'B001',
                            'nota_credito_factura' => 'FC01',
                            'nota_credito_boleta'  => 'BC01',
                        ],
                    ],
                ],
            ],
            'emision' => [
                'modo'              => 'manual',
                'estado_emision'    => 'wc-completed',
                'boleta_sin_info_cliente' => false,
            ],
            'impuestos' => [
                'afectacion_default' => 'gravado18',
                'afectacion_mapeo' => [],
            ],
            'detraccion' => [
                'enabled'          => false,
                'tipo_de_detraccion'   => '',
                'porcentaje'           => 12,
                'medio_de_pago'        => '001',
                'cuenta_bancaria'      => '',
                'tipo_de_cambio'       => '',
            ],
            'advanced' => [
                'debug'           => false,
                'custom_checkout'  => false,
                'multitienda'      => false,
                'store_meta_key'   => '',
                'checkout_mapping' => [
                    'tipo_comprobante'      => '_billing_apisunat_document_type',
                    'cpe_factura'           => '01',
                    'cpe_boleta'            => '03',
                    'tipo_documento'        => '_billing_apisunat_customer_id_type',
                    'doc_sin'               => '-',
                    'doc_dni'               => '1',
                    'doc_ruc'               => '6',
                    'doc_cpp'               => 'H',
                    'doc_pasaporte'         => '7',
                    'doc_carnet_ext'        => '4',
                    'doc_tam'               => 'E',
                    'doc_cedula_dip'        => 'A',
                    'doc_salvoconducto'     => 'G',
                    'doc_tin'               => 'C',
                    'doc_in'                => 'D',
                    'doc_otros'             => 'B',
                    'doc_trib'              => '0',
                    'numero_documento'      => '_billing_apisunat_customer_id',
                ],
            ],
            'stores' => [],
        ];
    }
}
