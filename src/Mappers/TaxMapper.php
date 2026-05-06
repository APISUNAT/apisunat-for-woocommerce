<?php
namespace Atm\Apisunatwp\Mappers;

class TaxMapper {

    public const GRAVADO   = '10';
    public const EXONERADO = '20';
    public const INAFECTO  = '30';
    public const GRATUITO  = '40';

    private static array $slugToSunat = [
        'standard'       => self::GRAVADO,
        'gravado10'     => self::GRAVADO,
        'gravado105'   => self::GRAVADO,
        'exonerado'      => self::EXONERADO,
        'inafecto'       => self::INAFECTO,
        'gratuito'       => self::GRATUITO,
    ];

    public static function resolve(string $tax_class = ''): string {
        if ($tax_class === '') {
            $tax_class = 'standard';
        }
        $slug = sanitize_title($tax_class);
        return self::$slugToSunat[$slug] ?? self::GRAVADO;
    }

    public static function getFactor(string $afectacion): string {
        if ($afectacion !== self::GRAVADO) {
            return '0';
        }
        $rates = \WC_Tax::get_rates_for_tax_class('standard');
        if (!empty($rates) && is_array($rates)) {
            $rate = reset($rates);
            if (isset($rate->tax_rate) && is_numeric($rate->tax_rate)) {
                return (string) absint($rate->tax_rate);
            }
        }
        return '18';
    }
}
