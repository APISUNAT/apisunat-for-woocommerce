<?php
namespace Atm\Apisunatwp\Services;

use Atm\Apisunatwp\Config\Options;
use Atm\Apisunatwp\Contracts\LoggerInterface;
use Atm\Apisunatwp\Exceptions\ApiRequestException;
use Atm\Apisunatwp\Exceptions\InvalidOrderException;
use Atm\Apisunatwp\Exceptions\MissingCredentialsException;
use Atm\Apisunatwp\Logging\Logger;
use Atm\Apisunatwp\Mappers\OrderMapper;
use Atm\Apisunatwp\Mappers\TaxMapper;

class ApiSunatService {

    public const API_URL  = 'https://ecommerces-api.apisunat.com/v1.2/woocommerce';
    public const BASE_URL = 'https://back.apisunat.com';

    private const POST_TIMEOUT = 45;
    private const GET_TIMEOUT  = 30;

    private static ?LoggerInterface $logger = null;

    public static function setLogger(LoggerInterface $logger): void {
        self::$logger = $logger;
    }

    private static function logger(): LoggerInterface {
        if (self::$logger === null) {
            self::$logger = Logger::instance();
        }
        return self::$logger;
    }

    private static function acquireLock(int $orderId): bool {
        $key = 'apisunat_send_lock_' . $orderId;
        // add_option is atomic at the DB level (UNIQUE constraint on option_name):
        // returns true on first writer, false if option already exists.
        if (add_option($key, time() + 135, '', 'no')) {
            return true;
        }
        // Lock present — check if it has expired (stale lock from a crashed process).
        $expires = (int) get_option($key, 0);
        if ($expires > 0 && $expires < time()) {
            update_option($key, time() + 135, false);
            return true;
        }
        return false;
    }

    private static function releaseLock(int $orderId): void {
        delete_option('apisunat_send_lock_' . $orderId);
    }

    public static function send(int|string $order_id): void {
        $order_id = (int) $order_id;
        $order    = wc_get_order($order_id);
        if (!$order) {
            throw new InvalidOrderException("Order #{$order_id} not found");
        }

        self::logger()->info('Sending order to SUNAT', ['order_id' => $order_id]);

        if (self::isAlreadyProcessed($order)) {
            self::logger()->debug('Order already processed, skipping', ['order_id' => $order_id]);
            return;
        }

        if (!self::acquireLock($order_id)) {
            self::logger()->info('Send already in progress, skipping concurrent call', ['order_id' => $order_id]);
            return;
        }

        try {
            // Re-check status AFTER acquiring the lock — another process may have
            // just finished while we were waiting.
            $order = wc_get_order($order_id);
            if (self::isAlreadyProcessed($order)) {
                self::logger()->debug('Order processed by concurrent send, skipping', ['order_id' => $order_id]);
                return;
            }

            $branch = Options::resolveBranch($order);
            if (empty($branch['persona_id']) || empty($branch['persona_token'])) {
                throw new MissingCredentialsException(__('Credenciales API no configuradas', 'apisunatv2'));
            }

            if (!self::hasRequiredDocument($order)) {
                $order->add_order_note(__('API Sunat: Documento del cliente requerido. Completa los campos en el panel lateral del pedido.', 'apisunatv2'));
                self::logger()->error('Missing customer document', ['order_id' => $order_id]);
                throw new \RuntimeException(__('Documento del cliente requerido. Completa el panel "API Sunat" en el pedido.', 'apisunatv2'));
            }

            $payload  = self::buildPayload($order, $branch);

            if (Options::getValue('advanced.debug')) {
                self::logger()->debug('CPE Payload', ['order_id' => $order_id, 'payload' => $payload]);
            }

            $response = self::request('POST', self::API_URL, $payload);

            if (Options::getValue('advanced.debug')) {
                self::logger()->debug('CPE Response', ['order_id' => $order_id, 'response' => $response]);
            }

            self::processResponse($order, $response);

            // Programar verificación asíncrona del estado
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(
                    'apisunat_check_status_async',
                    ['order_id' => $order_id, 'attempt' => 0],
                    'apisunat-status'
                );
            }
        } finally {
            self::releaseLock($order_id);
        }
    }

    public static function void(int|string $order_id, string $reason): void {
        $order_id = (int) $order_id;
        $order    = wc_get_order($order_id);
        if (!$order) {
            throw new InvalidOrderException("Order #{$order_id} not found");
        }

        $docId = (string) $order->get_meta('_apisunat_document_id');
        if ($docId === '') {
            throw new \RuntimeException(__('No hay documento que anular', 'apisunatv2'));
        }

        self::logger()->info('Voiding CPE', ['order_id' => $order_id, 'doc_id' => $docId]);

        $branch = Options::resolveBranch($order);
        if (empty($branch['persona_id']) || empty($branch['persona_token'])) {
            throw new MissingCredentialsException(__('Credenciales API no configuradas', 'apisunatv2'));
        }

        $payload = self::buildPayload($order, $branch);
        $branchSeries = $branch['series'] ?? [];
        $payload['plugin_data']['serie07F'] = !empty($branchSeries['nota_credito_factura']) ? $branchSeries['nota_credito_factura'] : (string) Options::getValue('emision.series.nota_credito_factura', 'FC01');
        $payload['plugin_data']['serie07B'] = !empty($branchSeries['nota_credito_boleta']) ? $branchSeries['nota_credito_boleta'] : (string) Options::getValue('emision.series.nota_credito_boleta', 'BC01');
        $payload['document_data'] = [
            'reason'         => $reason,
            'documentId'     => $docId,
            'customer_email' => $order->get_billing_email(),
        ];

        if (Options::getValue('advanced.debug')) {
            self::logger()->debug('Void Payload', ['order_id' => $order_id, 'doc_id' => $docId, 'payload' => $payload]);
        }

        $response = self::request('POST', self::API_URL . '/' . rawurlencode($docId), $payload);

        if (Options::getValue('advanced.debug')) {
            self::logger()->debug('Void Response', ['order_id' => $order_id, 'doc_id' => $docId, 'response' => $response]);
        }

        if (($response['status'] ?? null) === 'PENDIENTE') {
            $order->delete_meta_data('_apisunat_document_status');
            $order->delete_meta_data('_apisunat_document_id');
            $order->delete_meta_data('_apisunat_document_filename');
            $order->save();

            $order->add_order_note(
                sprintf(
                    /* translators: %s: void reason */
                    __('CPE anulado con Nota de Crédito. Motivo: %s', 'apisunatv2'),
                    esc_html($reason)
                )
            );

            self::logger()->info('CPE voided', ['order_id' => $order_id, 'doc_id' => $docId]);
            return;
        }

        throw new \RuntimeException((string) ($response['error'] ?? __('Anulación falló', 'apisunatv2')));
    }

    public static function checkStatus(int|string $order_id): void {
        $order_id = (int) $order_id;
        $order    = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $docId  = (string) $order->get_meta('_apisunat_document_id');
        $status = (string) $order->get_meta('_apisunat_document_status');

        if ($docId === '') {
            self::logger()->warning('No document ID for status check', ['order_id' => $order_id]);
            return;
        }

        self::logger()->debug('Checking document status', [
            'order_id'       => $order_id,
            'doc_id'         => $docId,
            'current_status' => $status,
        ]);

        $response = self::request('GET', self::BASE_URL . '/documents/' . rawurlencode($docId) . '/getById');

        if (isset($response['status']) && is_string($response['status'])) {
            $newStatus = $response['status'];
            $order->update_meta_data('_apisunat_document_status', $newStatus);
            $order->save();
            $order->add_order_note(
                sprintf(
                    /* translators: %s: document status */
                    __('Estado del documento actualizado: %s', 'apisunatv2'),
                    esc_html($newStatus)
                )
            );

            self::logger()->info('Status updated', [
                'order_id' => $order_id,
                'doc_id'   => $docId,
                'status'   => $newStatus,
            ]);
        }
    }

    private static function buildPayload(\WC_Order $order, array $branch): array {
        $mapped = OrderMapper::map($order);

        $tax_classes = self::getTaxClassesMap();
        $items_data = [];
        foreach ($order->get_items() as $item) {
            $product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
            $tax_class = $product ? $product->get_tax_class() : '';
            $items_data[] = [
                'item'          => $item->get_data(),
                'product'       => $product ? $product->get_data() : [],
                'tax_class'     => $tax_class,
                'tax_class_slug' => sanitize_title($tax_class),
                'tax_info'      => $tax_classes[$tax_class] ?? $tax_classes[''] ?? null,
                'taxes'         => $item->get_taxes(),
            ];
        }

        $order_data = $order->get_data();

        $excluded_meta = [
            '_apisunat_request',
            '_apisunat_response',
            '_apisunat_response_code',
        ];

        $order_data['meta_data'] = array_values(array_filter(
            $order_data['meta_data'] ?? [],
            static function ($meta) use ($excluded_meta) {
                $key = $meta->key ?? ($meta['key'] ?? '');
                return !in_array($key, $excluded_meta, true);
            }
        ));

        return [
            'plugin_data' => self::pluginConfig($branch),
            'order_data'  => $order_data,
            'items_data'  => $items_data,
            'tax_data'    => self::getRawTaxData($order),
            // 'order'       => $mapped, TODO for the future specific map
        ];
    }

    private static function getTaxClassesMap(): array {
        $map = [];
        $classes = \WC_Tax::get_tax_classes();
        foreach ($classes as $class) {
            $slug = sanitize_title($class);
            $rates = \WC_Tax::get_rates_for_tax_class($slug);
            if (!empty($rates)) {
                $rate = reset($rates);
                $map[$class] = [
                    'rate'       => (string) $rate->tax_rate,
                    'label'      => $rate->tax_rate_name,
                    'class'      => $class,
                    'slug'       => $slug,
                ];
            }
        }
        $standard_rates = \WC_Tax::get_rates_for_tax_class('standard');
        if (!empty($standard_rates)) {
            $rate = reset($standard_rates);
            $map[''] = [
                'rate'  => (string) $rate->tax_rate,
                'label' => $rate->tax_rate_name,
                'class' => 'standard',
                'slug'  => 'standard',
            ];
        }
        return $map;
    }

private static function pluginConfig(array $branch): array {
        $branchSeries = $branch['series'] ?? [];

        $serieFactura = !empty($branchSeries['factura']) ? $branchSeries['factura'] : (string) Options::getValue('emision.series.factura', 'F001');
        $serieBoleta  = !empty($branchSeries['boleta']) ? $branchSeries['boleta'] : (string) Options::getValue('emision.series.boleta', 'B001');
        $serieNCFactura = !empty($branchSeries['nota_credito_factura']) ? $branchSeries['nota_credito_factura'] : (string) Options::getValue('emision.series.nota_credito_factura', 'FC01');
        $serieNCBoleta = !empty($branchSeries['nota_credito_boleta']) ? $branchSeries['nota_credito_boleta'] : (string) Options::getValue('emision.series.nota_credito_boleta', 'BC01');

        $mapping = Options::getValue('advanced.checkout_mapping', []);

        return [
            'personaId'          => $branch['persona_id'],
            'personaToken'       => $branch['persona_token'],
            'noDocId'            => Options::getValue('emision.boleta_sin_info_cliente') ? 'true' : 'false',
            'serie01'            => $serieFactura,
            'serie03'            => $serieBoleta,
            'serie07F'           => $serieNCFactura,
            'serie07B'           => $serieNCBoleta,
            'emision_modo'       => Options::getValue('emision.modo', 'manual'),
            'emision_estado'     => Options::getValue('emision.estado_emision', 'wc-completed'),
            'moneda'             => get_woocommerce_currency(),
            'detraccion'         => [
                'enabled'          => Options::getValue('detraccion.enabled', false) ? 'true' : 'false',
                'codigo_default'   => Options::getValue('detraccion.medio_de_pago', '001'),
                'porcentaje'       => (float) Options::getValue('detraccion.porcentaje', 12),
                'cuenta_banco'     => Options::getValue('detraccion.cuenta_bancaria', ''),
                'monto_minimo_soles' => \Atm\Apisunatwp\Mappers\DetraccionMapper::MONTO_MINIMO_SOLES,
                'tipo_de_cambio'   => (string) Options::getValue('detraccion.tipo_de_cambio', ''),
            ],
            'checkout_meta_keys' => [
                'tipo_comprobante' => (string) ($mapping['tipo_comprobante'] ?? '_billing_apisunat_document_type'),
                'tipo_documento'   => (string) ($mapping['tipo_documento'] ?? '_billing_apisunat_customer_id_type'),
                'numero_documento' => (string) ($mapping['numero_documento'] ?? '_billing_apisunat_customer_id'),
            ],
            'impuestos'          => [
                'tipo_tributo' => (string) Options::getValue('impuestos.tipo_tributo', 'gravado18'),
                'afectaciones' => Options::getValue('impuestos.afectacion_mapping', []),
            ],
            'branches'           => Options::getValue('api.branches', []),
            'debug'              => Options::getValue('advanced.debug') ? 'true' : 'false',
            'custom_meta_data'   => Options::getValue('advanced.custom_checkout') ? 'true' : 'false',
        ];
    }

    private static function getWcTaxRate(): string {
        $rates = \WC_Tax::get_rates_for_tax_class('standard');
        if (!empty($rates) && is_array($rates)) {
            $rate = reset($rates);
            if (isset($rate->tax_rate) && is_numeric($rate->tax_rate)) {
                return (string) absint($rate->tax_rate);
            }
        }
        return '18';
    }

    private static function getRawTaxData(\WC_Order $order): array {
        $tax_data = [
            'order_taxes'  => $order->get_taxes(),
            'tax_totals'   => $order->get_tax_totals(),
            'tax_lines'    => [],
            'tax_classes'  => \WC_Tax::get_tax_classes(),
            'all_rates'    => [],
        ];

        foreach ($tax_data['tax_classes'] as $class) {
            $slug = sanitize_title($class);
            $rates = \WC_Tax::get_rates_for_tax_class($slug);
            if (!empty($rates)) {
                $tax_data['all_rates'][$slug] = array_map(function ($rate) {
                    return [
                        'rate'    => (string) $rate->tax_rate,
                        'label'   => $rate->tax_rate_name,
                        'shipping'=> $rate->tax_rate_shipping ? 'yes' : 'no',
                        'compound'=> $rate->tax_rate_compound ? 'yes' : 'no',
                    ];
                }, $rates);
            }
        }

        foreach ($order->get_items('tax') as $tax_item) {
            $tax_data['tax_lines'][] = $tax_item->get_data();
        }

        foreach ($order->get_items() as $item) {
            $tax_data['item_taxes'][] = [
                'item_id'    => $item->get_id(),
                'name'       => $item->get_name(),
                'tax_class'  => $item->get_tax_class(),
                'tax_status' => $item->get_tax_status(),
                'taxes'      => $item->get_taxes(),
            ];
        }

        $tax_data['wc_settings'] = [
            'calc_taxes'          => get_option('woocommerce_calc_taxes', 'no'),
            'prices_include_tax'  => get_option('woocommerce_prices_include_tax', 'no'),
            'tax_based_on'        => get_option('woocommerce_tax_based_on', 'shipping'),
            'shipping_tax_class'  => get_option('woocommerce_shipping_tax_class', ''),
            'tax_round_at_subtotal' => get_option('woocommerce_tax_round_at_subtotal', 'no'),
            'tax_display_shop'    => get_option('woocommerce_tax_display_shop', 'excl'),
            'tax_display_cart'    => get_option('woocommerce_tax_display_cart', 'excl'),
        ];

        return $tax_data;
    }

    private static function isAlreadyProcessed(\WC_Order $order): bool {
        return in_array((string) $order->get_meta('_apisunat_document_status'), ['PENDIENTE', 'ACEPTADO'], true);
    }

    private static function hasRequiredDocument(\WC_Order $order): bool {
        $docId   = (string) $order->get_meta('_billing_apisunat_id_number');
        $docType = (string) ($order->get_meta('_billing_apisunat_cpe_type') ?: '03');

        if ($docId !== '') {
            return true;
        }

        if (Options::getValue('emision.boleta_sin_info_cliente', false)) {
            return true;
        }

        return false;
    }

    private static function request(string $method, string $url, ?array $body = null): array {
        $json = $body !== null
            ? wp_json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;

        self::logger()->info("{$method} {$url}");

        $args = [
            'method'  => $method,
            'timeout' => $method === 'POST' ? self::POST_TIMEOUT : self::GET_TIMEOUT,
            'headers' => ['Content-Type' => 'application/json'],
        ];

        if ($json !== null) {
            $args['body'] = $json;
        }

        $response = wp_remote_request($url, $args);

        if (function_exists('wc_get_order') && isset($body['order']['id'])) {
            $order_id = $body['order']['id'];
            $order = wc_get_order($order_id);
            if ($order) {
                $request_data = [
                    'method' => $method,
                    'url'    => $url,
                    'body'   => $body,
                ];
                $order->update_meta_data('_apisunat_request', $request_data);

                if (!is_wp_error($response)) {
                    $response_code = (int) wp_remote_retrieve_response_code($response);
                    $response_body = (string) wp_remote_retrieve_body($response);
                    $order->update_meta_data('_apisunat_response_code', $response_code);
                    $order->update_meta_data('_apisunat_response', $response_body);
                }
                $order->save();
            }
        }

        if (is_wp_error($response)) {
            self::logger()->error('WP HTTP error', ['error' => $response->get_error_message()]);
            throw new ApiRequestException($response->get_error_message(), 0);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);

        self::logger()->info("Response {$code}", ['code' => $code]);

        if ($code < 200 || $code >= 300) {
            $snippet = self::truncate($raw, 500);
            throw new ApiRequestException("API responded with status {$code}: {$snippet}", $code);
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            self::logger()->error('Invalid JSON response', [
                'json_error' => json_last_error_msg(),
                'snippet'    => self::truncate($raw, 200),
            ]);
            throw new ApiRequestException(__('Respuesta API inválida', 'apisunatv2'));
        }

        return $data;
    }

    private static function processResponse(\WC_Order $order, array $response): void {
        $status = $response['status'] ?? null;
        if (!is_string($status) || $status === '') {
            throw new \RuntimeException(__('Respuesta inválida del servicio SUNAT', 'apisunatv2'));
        }

        $order->update_meta_data('_apisunat_document_status', $status);
        $order->save();

        if ($status === 'ERROR') {
            $error = $response['error'] ?? $response;
            $order->add_order_note('API Sunat Error: ' . wp_json_encode($error));
            self::logger()->error('API returned error', ['order_id' => $order->get_id()]);
            throw new \RuntimeException(__('Error al emitir CPE', 'apisunatv2'));
        }

        if (isset($response['documentId'])) {
            $order->update_meta_data('_apisunat_document_id', (string) $response['documentId']);
            $order->update_meta_data('_apisunat_document_filename', (string) ($response['fileName'] ?? ''));
            $order->save();

            self::logger()->info('CPE issued', [
                'order_id' => $order->get_id(),
                'doc_id'   => $response['documentId'],
                'status'   => $status,
            ]);
        }

        $order->add_order_note(self::buildSuccessNote($response));
    }

    private static function buildSuccessNote(array $response): string {
        $docId    = (string) ($response['documentId'] ?? '');
        $filename = (string) ($response['fileName'] ?? '');
        $number   = self::documentNumber($filename);

        if ($docId === '') {
            return __('CPE enviado correctamente', 'apisunatv2');
        }

        return sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s %s</a>',
            esc_url(self::BASE_URL . '/documents/' . rawurlencode($docId) . '/getPDF/default/' . rawurlencode($filename) . '.pdf'),
            esc_html__('Comprobante:', 'apisunatv2'),
            esc_html($number)
        );
    }

    private static function documentNumber(string $filename): string {
        $parts = explode('-', $filename);
        return ($parts[2] ?? '') . '-' . ($parts[3] ?? '');
    }

    private static function truncate(string $value, int $max): string {
        return strlen($value) > $max ? substr($value, 0, $max) . '…' : $value;
    }
}
