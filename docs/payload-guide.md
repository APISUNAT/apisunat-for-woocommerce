# APISUNAT WooCommerce - Guía de Payload

## Opciones en base de datos

Cada sección se almacena en una opción separada de WordPress (tabla `wp_options`):

| Sección | Option key | Ejemplo de valores |
|---------|-----------|-------------------|
| API / Sucursales | `apisunatv2_settings_api` | `branches[]` (branch_name, personaId, personaToken, series) |
| Emisión | `apisunatv2_settings_issue` | `mode`, `trigger_status`, `no_customer_data`, `default_tax_type`, `series` (factura, boleta, nota_credito_*) |
| Detracción | `apisunatv2_settings_detraction` | `enabled`, `percentage`, `payment_method`, `detraction_type`, `bank_account`, `exchange_rate` |
| Avanzado | `apisunatv2_settings_advanced` | `debug`, `custom_checkout`, `multi_branch`, `multi_branch_key`, `checkout_mapping` (tipo_comprobante, cpe_factura, cpe_boleta, tipo_documento, doc_sin, doc_dni, doc_ruc, doc_cpp, doc_pasaporte, doc_carnet_ext, doc_tam, doc_cedula_dip, doc_salvoconducto, doc_tin, doc_in, doc_otros, doc_trib, numero_documento) |
| Sucursales (multi_branch) | `apisunatv2_settings_branchs` | `[]` — array de sucursales, cada una con `branch_name`, `api`, `issue`, `detraction` |

> Las option keys se construyen como `apisunatv2_settings_{section}` (ej: `apisunatv2_settings_detraction`).
> El plugin las resuelve vía `Options::getValue('detraction.percentage')` que recorre el array con notación de puntos.

## Catálogos reutilizables

Definidos en `Atm\Apisunatwp\Config\Catalogs` (`src/Config/Catalogs.php`):

| Método | Contenido |
|--------|-----------|
| `mediosDePago()` | Catálogo 59 SUNAT - Medio de pago (value => label) |
| `tiposDeDetraction()` | Catálogo 54 SUNAT - Tipo de detracción (value => ['label', 'percent']) |
| `tipoDeDetractionOptions()` | Ídem anterior pero solo labels para selects |

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

Configuración completa del plugin. Retorna las opciones tal cual están almacenadas, agrupadas por sección.

Obtenido desde `ApiSunatService::pluginConfig()` (`src/Services/ApiSunatService.php`).

#### Sin multitienda (`advanced.multi_branch = false`)

```json
{
  "api": { ... },
  "issue": { ... },
  "settings": { ... },
  "detraction": { ... },
  "branches": []
}
```

Cada sección contiene exactamente los mismos campos que están en la base de datos (ver tabla de opciones arriba).

#### Con multitienda (`advanced.multi_branch = true`)

```json
{
  "branches": [
    {
      "branch_name": "Sucursal 1",
      "api": { ... },
      "issue": { ... },
      "detraction": { ... }
    }
  ]
}
```

Cada sucursal del array `branches` tiene su propia configuración independiente de `api`, `issue` y `detraction`.

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

Si la detracción automática está habilitada y el monto supera el mínimo, la orden incluye `detraction`:

```json
{
  "detraction": {
    "codigo": "001",
    "percentage": 10,
    "monto": 25.50,
    "cuenta_banco": "00-000-000000"
  }
}
```

Resuelto por `DetractionMapper::resolve()` (`src/Mappers/DetractionMapper.php`) y asignado en `OrderMapper::map()`.

### Lógica de Detracción

1. Si `detraction.enabled = true` y el total supera el mínimo:
   - **Moneda PEN**: total < 700 (`DetractionMapper::MONTO_MINIMO_SOLES`) → no aplica
   - **Moneda extranjera**: busca tasa en `detraction.exchange_rate` (formato `USD=3.5`), convierte total a soles, compara con 700. Si no hay tasa usa el total directo.
2. Si el pedido tiene valores manuales (`_billing_apisunat_detraction_payment_method` o `_billing_apisunat_detraction_percentage`), se usan como override.
3. Si no cumple condiciones → `detraction = null` en la orden.

---

## Metas del Pedido (WooCommerce Order Meta)

Si `plugin_data.advanced.custom_checkout = true` o las keys están configuradas en `plugin_data.advanced.checkout_mapping`, se leen del pedido. Además, la detracción manual se almacena en metas propias:

| Meta key | Tipo | Descripción | Default si vacío |
|----------|------|-------------|------------------|
| `_billing_apisunat_cpe_type` | string | Tipo CPE (`01`/`03`) | `03` |
| `_billing_apisunat_id_type` | string | Tipo doc. (`1`/`6`/`7`/`B`) | `1` |
| `_billing_apisunat_id_number` | string | Número documento | `''` |
| `_billing_apisunat_detraction_enabled` | checkbox | Forzar detracción manual | `0` |
| `_billing_apisunat_detraction_tipo` | select | Tipo detracción (catálogo 54) | Config global |
| `_billing_apisunat_detraction_percentage` | number | Porcentaje | Config global |
| `_billing_apisunat_detraction_payment_method` | select | Medio de pago (catálogo 59) | Config global |
| `_billing_apisunat_detraction_cuenta_banco` | text | Cuenta bancaria | Config global |

> Si la orden no tiene valor propio, se usa el default de la configuración global vía `Options::getValue()`.
> El campo `_billing_apisunat_detraction_tipo` rellena automáticamente el percentage vía JS (`data-percent`).

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

1. Busca el slug de la clase en `settings.tax_types`
2. Si no encuentra mapping, usa `issue.default_tax_type` (default `gravado18`)
3. Internamente mapea los slugs a códigos SUNAT: `gravado10|gravado105|gravado18` → `10`, `exonerado` → `20`, `inafecto` → `30`

| Opción configurable | Código SUNAT |
|--------------------|-------------|
| `gravado10` | `10` |
| `gravado105` | `10` |
| `gravado18` | `10` |
| `exonerado` | `20` |
| `inafecto` | `30` |
