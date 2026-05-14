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
        $slug = sanitize_title($tax_class);
        $mapping = Options::getValue('impuestos.afectacion_mapping', []);
        $stored = $mapping[$slug] ?? Options::getValue('impuestos.tipo_tributo', 'gravado18');
        return self::$slugToSunat[$stored] ?? self::GRAVADO;
    }
}
