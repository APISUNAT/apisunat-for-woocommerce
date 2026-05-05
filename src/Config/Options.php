<?php
namespace Atm\Apisunatwp\Config;

class Options {

    public const OPTION_KEY = 'apisunatv2_settings';

    private static ?array $cache = null;

    public static function get(): array {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $saved      = get_option(self::OPTION_KEY, []);
        $saved      = is_array($saved) ? $saved : [];
        self::$cache = array_replace_recursive(Defaults::get(), $saved);
        return self::$cache;
    }

    public static function set(array $data): void {
        global $wpdb;
        if (!is_array($data)) {
            return;
        }
        $serialized = maybe_serialize($data);
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) 
                 VALUES (%s, %s, 'no') 
                 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
                self::OPTION_KEY,
                $serialized
            )
        );
        wp_cache_delete(self::OPTION_KEY, 'options');
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
            return ['persona_id' => '', 'persona_token' => ''];
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
                        ];
                    }
                }
            }
        }

        $first = $branches[0];
        return [
            'persona_id'    => (string) ($first['persona_id']    ?? ''),
            'persona_token' => (string) ($first['persona_token'] ?? ''),
        ];
    }

    public static function resolveCheckoutMeta(?\WC_Order $order = null): array {
        $mapping = self::getValue('advanced.checkout_mapping', []);
        $custom  = (bool) self::getValue('advanced.custom_checkout', false);

        if (!$custom || !$order) {
            return [
                'tipo_comprobante' => (string) ($order ? $order->get_meta('_sunat_cpe_type') ?: '03' : '03'),
                'tipo_documento'   => (string) ($order ? $order->get_meta('_sunat_id_type') ?: '1' : '1'),
                'numero_documento' => (string) ($order ? $order->get_meta('_sunat_id_number') : ''),
            ];
        }

        $cpeType = '';
        if (!empty($mapping['tipo_comprobante'])) {
            $raw = (string) $order->get_meta($mapping['tipo_comprobante']);
            if ($raw === '01' || $raw === 'factura' || $raw === 'Factura') $cpeType = '01';
            elseif ($raw === '03' || $raw === 'boleta' || $raw === 'Boleta') $cpeType = '03';
        }

        $docType = '';
        if (!empty($mapping['tipo_documento'])) {
            $raw = (string) $order->get_meta($mapping['tipo_documento']);
            if (in_array($raw, ['1', '6', '7', 'B'], true)) $docType = $raw;
            elseif (stripos($raw, 'dni') !== false) $docType = '1';
            elseif (stripos($raw, 'ruc') !== false) $docType = '6';
            elseif (stripos($raw, 'pasaporte') !== false) $docType = '7';
            elseif (stripos($raw, 'otros') !== false || stripos($raw, 'extranjero') !== false) $docType = 'B';
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
