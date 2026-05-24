<?php
namespace Atm\Apisunatwp\Mappers;

use Atm\Apisunatwp\Config\Options;

class TaxMapper {

    public const GRAVADO   = '10';
    public const EXONERADO = '20';
    public const INAFECTO  = '30';

    private static array $slugToSunat = [
        'gravado10'  => self::GRAVADO,
        'gravado105' => self::GRAVADO,
        'gravado18'  => self::GRAVADO,
        'exonerado'  => self::EXONERADO,
        'inafecto'   => self::INAFECTO,
    ];

    public static function resolve(string $tax_class = ''): string {
        if ($tax_class === '') {
            return '';
        }
        $rateMapping = Options::getValue('settings.tax_rate_mapping', []);
        $rates = \WC_Tax::get_rates_for_tax_class($tax_class);
        foreach ($rates as $rate) {
            if (isset($rateMapping[$rate->tax_rate_id])) {
                $stored = $rateMapping[$rate->tax_rate_id];
                return self::$slugToSunat[$stored] ?? self::GRAVADO;
            }
        }
        $stored = Options::getValue('issue.default_tax_type', 'gravado18');
        return self::$slugToSunat[$stored] ?? self::GRAVADO;
    }
}
