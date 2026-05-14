<?php
namespace Atm\Apisunatwp\Config;

class Options {

    public const OPTION_KEY = 'apisunatv2_settings';

    private static ?array $cache = null;

    public static function get(): array {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $defaults = Defaults::get();
        $saved = [];

        // Cargar cada sección por separado (opciones pequeñas)
        foreach (array_keys($defaults) as $section) {
            $value = get_option(self::OPTION_KEY . '_' . $section, null);
            if ($value !== null) {
                $saved[$section] = is_array($value) ? $value : [];
            }
        }

        self::$cache = array_replace_recursive($defaults, $saved);
        return self::$cache;
    }

    public static function set(array $data): void {
        if (!is_array($data)) {
            return;
        }

        // Guardar cada sección en una opción separada (pequeña)
        foreach ($data as $section => $value) {
            if (is_array($value)) {
                update_option(self::OPTION_KEY . '_' . $section, $value, false);
            }
        }

        self::flushCache();
    }

    public static function flushCache(): void {
        self::$cache = null;
    }

    public static function getValue(string $path, $default = null) {
        $options = self::get();
        $keys    = explode('.', $path);

        foreach ($keys as $key) {
            if (!is_array($options) || !array_key_exists($key, $options)) {
                return $default;
            }
            $options = $options[$key];
        }

        return $options ?? $default;
    }

    public static function updateValue(string $path, $value): void {
        $options = self::get();
        $keys    = explode('.', $path);

        $ref = &$options;
        foreach ($keys as $key) {
            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }
        $ref = $value;

        self::set($options);
    }

    public static function resolveBranch(?\WC_Order $order = null): array {
        $branches = self::getValue('api.branches', []);
        if (empty($branches) || !is_array($branches)) {
            return ['persona_id' => '', 'persona_token' => '', 'series' => []];
        }

        $metaKey = trim((string) self::getValue('api.meta_key', ''));
        if ($metaKey !== '' && $order) {
            $metaValue = (string) $order->get_meta($metaKey);
            if ($metaValue !== '') {
                foreach ($branches as $branch) {
                    if (isset($branch['label']) && (string) $branch['label'] === $metaValue) {
                        return [
                            'persona_id'    => (string) ($branch['persona_id']    ?? ''),
                            'persona_token' => (string) ($branch['persona_token'] ?? ''),
                            'series'        => (array) ($branch['series']        ?? []),
                        ];
                    }
                }
            }
        }

        $first = $branches[0];
        return [
            'persona_id'    => (string) ($first['persona_id']    ?? ''),
            'persona_token' => (string) ($first['persona_token'] ?? ''),
            'series'        => (array) ($first['series']        ?? []),
        ];
    }

    public static function resolveCheckoutMeta(?\WC_Order $order = null): array {
        $mapping = self::getValue('advanced.checkout_mapping', []);
        $custom  = (bool) self::getValue('advanced.custom_checkout', false);

        if (!$custom || !$order) {
            return [
                'tipo_comprobante' => (string) ($order ? $order->get_meta('_billing_apisunat_cpe_type') ?: '03' : '03'),
                'tipo_documento'   => (string) ($order ? $order->get_meta('_billing_apisunat_id_type') ?: '1' : '1'),
                'numero_documento' => (string) ($order ? $order->get_meta('_billing_apisunat_id_number') : ''),
            ];
        }

        $cpeType = '';
        if (!empty($mapping['tipo_comprobante'])) {
            $raw = (string) $order->get_meta($mapping['tipo_comprobante']);
            $cpeFactura = $mapping['cpe_factura'] ?? '01';
            $cpeBoleta  = $mapping['cpe_boleta'] ?? '03';
            if ($raw === $cpeFactura) $cpeType = '01';
            elseif ($raw === $cpeBoleta) $cpeType = '03';
        }

        $docType = '';
        if (!empty($mapping['tipo_documento'])) {
            $raw = (string) $order->get_meta($mapping['tipo_documento']);
            $docDni = $mapping['doc_dni'] ?? '1';
            $docRuc = $mapping['doc_ruc'] ?? '6';
            $docPasaporte = $mapping['doc_pasaporte'] ?? '7';
            $docOtros = $mapping['doc_otros'] ?? 'B';
            if ($raw === $docDni) $docType = '1';
            elseif ($raw === $docRuc) $docType = '6';
            elseif ($raw === $docPasaporte) $docType = '7';
            elseif ($raw === $docOtros) $docType = 'B';
        }

        $docNumber = '';
        if (!empty($mapping['numero_documento'])) {
            $docNumber = (string) $order->get_meta($mapping['numero_documento']);
        }

        return [
            'tipo_comprobante' => $cpeType !== '' ? $cpeType : '03',
            'tipo_documento'   => $docType !== '' ? $docType : '1',
            'numero_documento' => $docNumber,
        ];
    }
}
