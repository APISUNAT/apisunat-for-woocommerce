<?php
namespace Atm\Apisunatwp\Config;

class Catalogs {

    public static function mediosDePago(): array {
        return [
            '' => __('Seleccionar...', 'apisunatv2'),
            '001' => __('Depósito en cuenta', 'apisunatv2'),
            '002' => __('Giro', 'apisunatv2'),
            '003' => __('Transferencia de fondos', 'apisunatv2'),
            '004' => __('Orden de pago', 'apisunatv2'),
            '005' => __('Tarjeta de débito', 'apisunatv2'),
            '006' => __('Tarjeta de crédito emitida en el país por una empresa del sistema financiero', 'apisunatv2'),
            '007' => __('Cheques con la cláusula de "NO NEGOCIABLE", "INTRANSFERIBLES", "NO A LA ORDEN" u otra equivalente, a que se refiere el inciso g) del artículo 5° de la ley', 'apisunatv2'),
            '008' => __('Efectivo, por operaciones en las que no existe obligación de utilizar medio de pago', 'apisunatv2'),
            '009' => __('Efectivo, en los demás casos', 'apisunatv2'),
            '010' => __('Medios de pago usados en comercio exterior', 'apisunatv2'),
            '011' => __('Documentos emitidos por las EDPYMES y las cooperativas de ahorro y crédito no autorizadas a captar depósitos del público', 'apisunatv2'),
            '012' => __('Tarjeta de crédito emitida en el país o en el exterior por una empresa no perteneciente al sistema financiero, cuyo objeto principal sea la emisión y administración de tarjetas de crédito', 'apisunatv2'),
            '013' => __('Tarjetas de crédito emitidas en el exterior por empresas bancarias o financieras no domiciliadas', 'apisunatv2'),
            '101' => __('Transferencias - Comercio exterior', 'apisunatv2'),
            '102' => __('Cheques bancarios - Comercio exterior', 'apisunatv2'),
            '103' => __('Orden de pago simple - Comercio exterior', 'apisunatv2'),
            '104' => __('Orden de pago documentario - Comercio exterior', 'apisunatv2'),
            '105' => __('Remesa simple - Comercio exterior', 'apisunatv2'),
            '106' => __('Remesa documentaria - Comercio exterior', 'apisunatv2'),
            '107' => __('Carta de crédito simple - Comercio exterior', 'apisunatv2'),
            '108' => __('Carta de crédito documentario - Comercio exterior', 'apisunatv2'),
            '999' => __('Otros medios de pago - Comercio exterior', 'apisunatv2'),
        ];
    }

    public static function tiposDeDetraccion(): array {
        return [
            '' => ['label' => __('Seleccionar...', 'apisunatv2'), 'percent' => null],
            '001' => ['label' => __('Azúcar y melaza de caña', 'apisunatv2'), 'percent' => 10],
            '002' => ['label' => __('Arroz', 'apisunatv2'), 'percent' => null],
            '003' => ['label' => __('Alcohol etílico', 'apisunatv2'), 'percent' => 10],
            '004' => ['label' => __('Recursos hidrobiológicos', 'apisunatv2'), 'percent' => 4],
            '005' => ['label' => __('Maíz amarillo duro', 'apisunatv2'), 'percent' => 4],
            '007' => ['label' => __('Caña de azúcar', 'apisunatv2'), 'percent' => 10],
            '008' => ['label' => __('Madera', 'apisunatv2'), 'percent' => 4],
            '009' => ['label' => __('Arena y piedra.', 'apisunatv2'), 'percent' => 10],
            '010' => ['label' => __('Residuos, subproductos, desechos, recortes y desperdicios', 'apisunatv2'), 'percent' => 15],
            '011' => ['label' => __('Bienes gravados con el IGV, o renuncia a la exoneración', 'apisunatv2'), 'percent' => 10],
            '012' => ['label' => __('Intermediación laboral y tercerización', 'apisunatv2'), 'percent' => 12],
            '013' => ['label' => __('Animales vivos', 'apisunatv2'), 'percent' => null],
            '014' => ['label' => __('Carnes y despojos comestibles', 'apisunatv2'), 'percent' => 4],
            '015' => ['label' => __('Abonos, cueros y pieles de origen animal', 'apisunatv2'), 'percent' => null],
            '016' => ['label' => __('Aceite de pescado', 'apisunatv2'), 'percent' => 10],
            '017' => ['label' => __('Harina, polvo y "pellets" de pescado, crustáceos, moluscos y demás invertebrados acuáticos', 'apisunatv2'), 'percent' => 4],
            '019' => ['label' => __('Arrendamiento de bienes muebles', 'apisunatv2'), 'percent' => 10],
            '020' => ['label' => __('Mantenimiento y reparación de bienes muebles', 'apisunatv2'), 'percent' => 12],
            '021' => ['label' => __('Movimiento de carga', 'apisunatv2'), 'percent' => 10],
            '022' => ['label' => __('Otros servicios empresariales', 'apisunatv2'), 'percent' => 12],
            '023' => ['label' => __('Leche', 'apisunatv2'), 'percent' => 4],
            '024' => ['label' => __('Comisión mercantil', 'apisunatv2'), 'percent' => 10],
            '025' => ['label' => __('Fabricación de bienes por encargo', 'apisunatv2'), 'percent' => 10],
            '026' => ['label' => __('Servicio de transporte de personas', 'apisunatv2'), 'percent' => 10],
            '027' => ['label' => __('Servicio de transporte de carga', 'apisunatv2'), 'percent' => 4],
            '028' => ['label' => __('Transporte de pasajeros', 'apisunatv2'), 'percent' => 10],
            '030' => ['label' => __('Contratos de construcción', 'apisunatv2'), 'percent' => 4],
            '031' => ['label' => __('Oro gravado con el IGV', 'apisunatv2'), 'percent' => 10],
            '032' => ['label' => __('Paprika y otros frutos de los generos capsicum o pimienta', 'apisunatv2'), 'percent' => 10],
            '034' => ['label' => __('Minerales metálicos no auríferos', 'apisunatv2'), 'percent' => 10],
            '035' => ['label' => __('Bienes exonerados del IGV', 'apisunatv2'), 'percent' => 1.5],
            '036' => ['label' => __('Oro y demás minerales metálicos exonerados del IGV', 'apisunatv2'), 'percent' => 1.5],
            '037' => ['label' => __('Demás servicios gravados con el IGV', 'apisunatv2'), 'percent' => 12],
            '039' => ['label' => __('Minerales no metálicos', 'apisunatv2'), 'percent' => 10],
            '040' => ['label' => __('Bien inmueble gravado con IGV', 'apisunatv2'), 'percent' => 4],
            '041' => ['label' => __('Plomo', 'apisunatv2'), 'percent' => 15],
            '044' => ['label' => __('Servicio de beneficio de minerales metálicos gravado con el IGV', 'apisunatv2'), 'percent' => 12],
            '045' => ['label' => __('Minerales de oro y sus concentrados gravados con el IGV', 'apisunatv2'), 'percent' => 10],
            '099' => ['label' => __('Ley 30737', 'apisunatv2'), 'percent' => null],
        ];
    }

    public static function tipoDeDetraccionOptions(): array {
        $options = [];
        foreach (self::tiposDeDetraccion() as $k => $v) {
            $options[$k] = $v['label'];
        }
        return $options;
    }
}
