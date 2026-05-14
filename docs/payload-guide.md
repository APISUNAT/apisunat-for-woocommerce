# API Sunat WooCommerce - Guía de Payload

## Opciones en base de datos

Cada sección se almacena en una opción separada de WordPress (tabla `wp_options`):

| Sección | Option key | Ejemplo de valores |
|---------|-----------|-------------------|
| API / Sucursales | `apisunatv2_settings_api` | `meta_key`, `branches[]` (label, persona_id, persona_token, series) |
| Emisión | `apisunatv2_settings_emision` | `modo`, `estado_emision`, `boleta_sin_info_cliente`, `series` (factura, boleta, nota_credito_*) |
| Impuestos | `apisunatv2_settings_impuestos` | `tipo_tributo`, `afectacion_mapping` (slug => código SUNAT) |
| Detracción | `apisunatv2_settings_detraccion` | `enabled`, `porcentaje`, `medio_de_pago`, `tipo_de_detraccion`, `cuenta_bancaria`, `tipo_de_cambio` |
| Avanzado | `apisunatv2_settings_advanced` | `debug`, `custom_checkout`, `checkout_mapping` (tipo_comprobante, cpe_factura, cpe_boleta, tipo_documento, doc_dni, doc_ruc, doc_pasaporte, doc_otros, numero_documento) |

> Las option keys se construyen como `apisunatv2_settings_{section}` (ej: `apisunatv2_settings_detraccion`).
> El plugin las resuelve vía `Options::getValue('detraccion.porcentaje')` que recorre el array con notación de puntos.

## Catálogos reutilizables

Definidos en `Atm\Apisunatwp\Config\Catalogs` (`src/Config/Catalogs.php`):

| Método | Contenido |
|--------|-----------|
| `mediosDePago()` | Catálogo 59 SUNAT - Medio de pago (value => label) |
| `tiposDeDetraccion()` | Catálogo 54 SUNAT - Tipo de detracción (value => ['label', 'percent']) |
| `tipoDeDetraccionOptions()` | Ídem anterior pero solo labels para selects |

## Payload enviado a la API

Endpoint: `POST https://ecommerces-api.apisunat.com/v1.2/woocommerce`

### Estructura general

```json
{
  "plugin_data": { ... },
  "order": { ... },
  "items_data": [ ... ],
  "order_data": { ... }
}
```

### `plugin_data`

Configuración del plugin. Obtenido desde `ApiSunatService::pluginConfig()` (`src/Services/ApiSunatService.php:242`).

#### Credenciales

```json
personaId       // string - ID de persona (api.branches[N].persona_id)
personaToken    // string - Token de acceso (api.branches[N].persona_token)
```

#### Series (de sucursal activa o fallback de emisión)

```json
serie01         // string - Serie factura (emision.series.factura, default "F001")
serie03         // string - Serie boleta (emision.series.boleta, default "B001")
serie07F        // string - Serie NC factura (emision.series.nota_credito_factura, default "FC01")
serie07B        // string - Serie NC boleta (emision.series.nota_credito_boleta, default "BC01")
```

#### Emisión

```json
noDocId                   // "true"|"false" - Si no hay documento crear boleta simple (emision.boleta_sin_info_cliente)
emision_modo              // "automatico"|"manual" (emision.modo)
emision_estado            // Estado que dispara emisión (emision.estado_emision)
```

#### Moneda

```json
moneda                   // Código ISO de la tienda (get_woocommerce_currency())
```

#### Detracción

```json
detraccion: {
  enabled                // "true"|"false" (detraccion.enabled)
  codigo_default         // Código medio de pago catálogo 59 (detraccion.medio_de_pago, default "001")
  porcentaje             // Porcentaje base (detraccion.porcentaje, default 12)
  cuenta_banco           // Cuenta bancaria (detraccion.cuenta_bancaria)
  monto_minimo_soles     // 700 - constante fija DetraccionMapper::MONTO_MINIMO_SOLES
  tipo_de_cambio         // TC para monedas extranjeras (detraccion.tipo_de_cambio), formato "USD=3.5,EUR=4.0,COP=0.7"
}
```

#### Checkout (mapeo de meta keys para datos del cliente)

```json
checkout_meta_keys: {
  tipo_comprobante       // Meta key para tipo CPE (advanced.checkout_mapping.tipo_comprobante)
  tipo_documento         // Meta key para tipo documento (advanced.checkout_mapping.tipo_documento)
  numero_documento       // Meta key para número documento (advanced.checkout_mapping.numero_documento)
}
```

#### Sucursales

```json
branches                 // Array completo de sucursales (api.branches)
```

#### Debug / Custom meta

```json
debug                   // "true"|"false" (advanced.debug)
custom_meta_data        // "true"|"false" (advanced.custom_checkout)
```

---

### `items_data`

Array de items del pedido (desde `ApiSunatService::buildPayload()`). **Cada item incluye:**

```json
{
  "item": {
    "id": 1,
    "name": "Producto XYZ",
    "quantity": 2,
    "total": 100.00,
    "total_tax": 18.00
  },
  "product": {
    "id": 99,
    "sku": "SKU123"
  },
  "tax_class": "Standard",
  "tax_class_slug": "standard",
  "tax_info": {
    "rate": "18",
    "label": "IGV 18%"
  },
  "taxes": {
    "total": "18.00"
  }
}
```

| Campo | Fuente |
|-------|--------|
| `item` | `$item->get_data()` |
| `product` | `$product->get_data()` |
| `tax_class` | `$product->get_tax_class()` |
| `tax_class_slug` | `sanitize_title(tax_class)` |
| `tax_info` | Tasa de impuesto resuelta desde `getTaxClassesMap()` |
| `taxes` | `$item->get_taxes()` |

---

### `order_data`

Datos brutos del pedido WooCommerce (`$order->get_data()`):

```json
{
  "id": 123,
  "status": "completed",
  "currency": "PEN",
  "total": "118.00",
  "subtotal": "100.00",
  "total_tax": "18.00",
  "billing": {
    "first_name": "...",
    "last_name": "...",
    "address_1": "...",
    "city": "...",
    "state": "...",
    "postcode": "...",
    "country": "PE"
  }
}
```

---

## Detracción

### Detracción a nivel de orden

Si la detracción automática está habilitada y el monto supera el mínimo, la orden incluye `detraccion`:

```json
{
  "detraccion": {
    "codigo": "001",
    "porcentaje": 10,
    "monto": 25.50,
    "cuenta_banco": "00-000-000000"
  }
}
```

Resuelto por `DetraccionMapper::resolve()` (`src/Mappers/DetraccionMapper.php`) y asignado en `OrderMapper::map()`.

### Lógica de Detracción

1. Si `detraccion.enabled = true` y el total supera el mínimo:
   - **Moneda PEN**: total < 700 (`DetraccionMapper::MONTO_MINIMO_SOLES`) → no aplica
   - **Moneda extranjera**: busca tasa en `detraccion.tipo_de_cambio` (formato `USD=3.5`), convierte total a soles, compara con 700. Si no hay tasa usa el total directo.
2. Si el pedido tiene valores manuales (`_billing_apisunat_detraccion_medio_de_pago` o `_billing_apisunat_detraccion_porcentaje`), se usan como override.
3. Si no cumple condiciones → `detraccion = null` en la orden.

---

## Metas del Pedido (WooCommerce Order Meta)

Si `plugin_data.custom_meta_data = "true"` o las keys están configuradas en `checkout_meta_keys`, se leen del pedido. Además, la detracción manual se almacena en metas propias:

| Meta key | Tipo | Descripción | Default si vacío |
|----------|------|-------------|------------------|
| `_billing_apisunat_cpe_type` | string | Tipo CPE (`01`/`03`) | `03` |
| `_billing_apisunat_id_type` | string | Tipo doc. (`1`/`6`/`7`/`B`) | `1` |
| `_billing_apisunat_id_number` | string | Número documento | `''` |
| `_billing_apisunat_detraccion_enabled` | checkbox | Forzar detracción manual | `0` |
| `_billing_apisunat_detraccion_tipo` | select | Tipo detracción (catálogo 54) | Config global |
| `_billing_apisunat_detraccion_porcentaje` | number | Porcentaje | Config global |
| `_billing_apisunat_detraccion_medio_de_pago` | select | Medio de pago (catálogo 59) | Config global |
| `_billing_apisunat_detraccion_cuenta_banco` | text | Cuenta bancaria | Config global |

> Si la orden no tiene valor propio, se usa el default de la configuración global vía `Options::getValue()`.
> El campo `_billing_apisunat_detraccion_tipo` rellena automáticamente el porcentaje vía JS (`data-percent`).

---

## Mapeo de órdenes

### CustomerMapper (`src/Mappers/CustomerMapper.php`)

| Campo | Descripción |
|-------|-------------|
| `document_type` | Tipo documento (`1`=DNI, `6`=RUC, `7`=Pasaporte, `B`=Otro) |
| `document_number` | Número de documento |
| `name` | Nombre completo del cliente |
| `address` | Dirección concatenada |
| `email` | Email |
| `phone` | Teléfono |

### ItemsMapper (`src/Mappers/ItemsMapper.php`)

| Campo | Descripción |
|-------|-------------|
| `name` | Nombre del producto |
| `quantity` | Cantidad |
| `unit_price` | Precio unitario (4 decimales) |
| `total` | Total del item (2 decimales) |
| `tax` | Impuesto del item |
| `afectacion` | Código SUNAT de afectación |
| `sku` | SKU del producto |

El envío se agrega como item adicional con SKU `SHIPPING`.

### TaxMapper (`src/Mappers/TaxMapper.php`)

La afectación se resuelve dinámicamente desde la configuración:

1. Busca el slug de la clase en `impuestos.afectacion_mapping`
2. Si no encuentra mapping, usa `impuestos.tipo_tributo` (default `gravado18`)
3. Internamente mapea los slugs a códigos SUNAT: `gravado10|gravado105|gravado18` → `10`, `exonerado` → `20`, `inafecto` → `30`

| Opción configurable | Código SUNAT |
|--------------------|-------------|
| `gravado10` | `10` |
| `gravado105` | `10` |
| `gravado18` | `10` |
| `exonerado` | `20` |
| `inafecto` | `30` |
