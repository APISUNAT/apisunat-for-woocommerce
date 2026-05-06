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
        $response = self::request('POST', self::API_URL, $payload);
        self::processResponse($order, $response);

        // Programar verificación asíncrona del estado
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(
                'apisunat_check_status_async',
                ['order_id' => $order_id, 'attempt' => 0],
                'apisunat-status'
            );
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

        $response = self::request('POST', self::API_URL . '/' . rawurlencode($docId), $payload);

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

        $items_data = [];
        foreach ($order->get_items() as $item) {
            $product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
            $items_data[] = [
                'item'    => $item->get_data(),
                'product' => $product ? $product->get_data() : [],
            ];
        }

        return [
            'plugin_data' => self::pluginConfig($branch),
            'order'       => $mapped,
            'order_data'  => $order->get_data(),
            'items_data'  => $items_data,
        ];
    }

    private static function pluginConfig(array $branch): array {
        $branchSeries = $branch['series'] ?? [];
        $serieFactura = !empty($branchSeries['factura']) ? $branchSeries['factura'] : (string) Options::getValue('emision.series.factura', 'F001');
        $serieBoleta  = !empty($branchSeries['boleta']) ? $branchSeries['boleta'] : (string) Options::getValue('emision.series.boleta', 'B001');

        return [
            'personaId'          => $branch['persona_id'],
            'personaToken'       => $branch['persona_token'],
            'noDocId'            => Options::getValue('documento_cliente.no_doc') ? 'true' : 'false',
            'serie01'            => $serieFactura,
            'serie03'            => $serieBoleta,
            'affectation'        => TaxMapper::resolve(),
            'affectation_factor' => (string) self::getWcTaxRate(),
            //'issueTime'          => Options::getValue('emision.include_time') ? 'true' : 'false',
            //'shipping_cost'      => Options::getValue('emision.shipping_cost') ? 'true' : 'false',
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

    private static function isAlreadyProcessed(\WC_Order $order): bool {
        return in_array((string) $order->get_meta('_apisunat_document_status'), ['PENDIENTE', 'ACEPTADO'], true);
    }

    private static function hasRequiredDocument(\WC_Order $order): bool {
        if (Options::getValue('documento_cliente.no_doc')) {
            return true;
        }

        $docType = (string) ($order->get_meta('_sunat_cpe_type') ?: '03');
        $docId   = (string) $order->get_meta('_sunat_id_number');

        if ($docType === '01' && Options::getValue('documento_cliente.require_for_factura', true)) {
            return $docId !== '';
        }

        if ($docType === '03' && Options::getValue('documento_cliente.require_for_boleta', false)) {
            return $docId !== '';
        }

        return true;
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
                $order->update_meta_data('_apisunat_request', wp_json_encode($request_data));

                if (!is_wp_error($response)) {
                    $response_code = (int) wp_remote_retrieve_response_code($response);
                    $response_body = (string) wp_remote_retrieve_body($response);
                    $order->update_meta_data('_apisunat_response_code', $response_code);
                    // Solo guardar primeros 5000 caracteres del response
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
