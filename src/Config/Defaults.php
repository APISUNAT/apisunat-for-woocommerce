<?php
namespace Atm\Apisunatwp\Config;

class Defaults {

    public static function get(): array {
        return [
            'api' => [
                'personaId'    => '',
                'personaToken' => '',
                'serie01'  => 'F001',
                'serie03'  => 'B001',
                'serie07F' => 'FC01',
                'serie07B' => 'BC01',
            ],
            'issue' => [
                'mode'              => 'manual',
                'trigger_status'    => 'wc-completed',
                'no_customer_data' => false,
                'default_tax_type' => 'gravado18',
            ],
            'detraction' => [
                'enabled'          => false,
                'detraction_type'   => '',
                'percentage'           => 12,
                'payment_method'        => '001',
                'bank_account'      => '',
                'exchange_rate'       => '',
            ],
            'settings' => [
                'debug'           => false,
                'tax_types' => [],
                'custom_checkout'  => false,
                'multi_branch'      => false,
                'multi_branch_key'   => '',
                'checkout_mapping' => [
                    'document_type_key'    => '_billing_apisunat_document_type',
                    'document_type_key_value_01'             => '01',
                    'document_type_key_value_03'             => '03',
                    'customer_id_type_key' => '_billing_apisunat_customer_id_type',
                    'customer_id_type_value_-'              => '-',
                    'customer_id_type_value_1'              => '1',
                    'customer_id_type_value_6'              => '6',
                    'customer_id_type_value_H'              => 'H',
                    'customer_id_type_value_7'              => '7',
                    'customer_id_type_value_4'              => '4',
                    'customer_id_type_value_E'              => 'E',
                    'customer_id_type_value_A'              => 'A',
                    'customer_id_type_value_G'              => 'G',
                    'customer_id_type_value_C'              => 'C',
                    'customer_id_type_value_D'              => 'D',
                    'customer_id_type_value_B'              => 'B',
                    'customer_id_type_value_0'              => '0',
                    'customer_id_key'      => '_billing_apisunat_customer_id',
                ],
            ],
            'gre' => [
                'vehiculo_categoria' => false,
                'transportista' => [
                    'nombre' => '',
                    'ruc'    => '',
                    'mtc'    => '',
                ],
                'partida' => [
                    'ubigeo'    => '',
                    'direccion' => '',
                ],
            ],
            'branches' => [],
        ];
    }
}
