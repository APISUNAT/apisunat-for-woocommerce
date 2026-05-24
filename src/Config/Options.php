<?php
namespace Atm\Apisunatwp\Config;

class Options {

    public const OPTION_KEY = 'apisunatv2_settings';

    public const BRANCH_PREFIX    = 'apisunatv2_branch_';
    public const BRANCH_INDEX_KEY = 'apisunatv2_branch_index';

    private static ?array $cache = null;

    public static function get(): array {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $defaults = Defaults::get();
        $saved = [];

        foreach (array_keys($defaults) as $section) {
            if ($section === 'branches') {
                continue;
            }
            $value = get_option(self::OPTION_KEY . '_' . $section, null);
            if ($value !== null) {
                $saved[$section] = is_array($value) ? $value : [];
            }
        }

        if (empty($saved['settings'])) {
            $old = get_option(self::OPTION_KEY . '_advanced', null);
            if ($old !== null) {
                $saved['settings'] = is_array($old) ? $old : [];
                delete_option(self::OPTION_KEY . '_advanced');
            }
        }

        $saved['branches'] = self::getAllBranches();

        self::$cache = array_replace_recursive($defaults, $saved);
        return self::$cache;
    }

    public static function set(array $data): void {
        if (!is_array($data)) {
            return;
        }

        foreach ($data as $section => $value) {
            if ($section === 'branches') {
                if (is_array($value)) {
                    self::saveBranchesFromArray($value);
                }
                continue;
            }
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

    public static function getBranchIds(): array {
        $ids = get_option(self::BRANCH_INDEX_KEY, null);
        if ($ids === null) {
            self::migrateBranches();
            $ids = get_option(self::BRANCH_INDEX_KEY, []);
        }
        return is_array($ids) ? $ids : [];
    }

    public static function getBranch(string $id): ?array {
        $data = get_option(self::BRANCH_PREFIX . $id, null);
        return is_array($data) ? $data : null;
    }

    public static function getAllBranches(): array {
        $ids   = self::getBranchIds();
        $branches = [];
        foreach ($ids as $id) {
            $branch = self::getBranch($id);
            if ($branch !== null) {
                $branches[] = $branch;
            }
        }
        return $branches;
    }

    public static function addBranch(array $data): string {
        $ids  = self::getBranchIds();
        $id   = self::generateBranchId($data, $ids);
        $ids[] = $id;
        update_option(self::BRANCH_PREFIX . $id, $data, false);
        update_option(self::BRANCH_INDEX_KEY, $ids, false);
        self::flushCache();
        return $id;
    }

    public static function deleteBranch(string $id): void {
        $ids = self::getBranchIds();
        $ids = array_values(array_filter($ids, static fn (string $existing) => $existing !== $id));
        delete_option(self::BRANCH_PREFIX . $id);
        update_option(self::BRANCH_INDEX_KEY, $ids, false);
        self::flushCache();
    }

    public static function migrateBranches(): void {
        $oldBranches = get_option(self::OPTION_KEY . '_branches', null);
        if ($oldBranches === null || !is_array($oldBranches)) {
            return;
        }

        $ids = [];
        foreach ($oldBranches as $index => $branch) {
            if (!is_array($branch)) {
                continue;
            }
            $id    = self::generateBranchId($branch, $ids);
            $ids[] = $id;
            update_option(self::BRANCH_PREFIX . $id, $branch, false);
        }

        update_option(self::BRANCH_INDEX_KEY, $ids, false);
        delete_option(self::OPTION_KEY . '_branches');
    }

    public static function resolveBranch(?\WC_Order $order = null): array {
        $personaId    = (string) self::getValue('api.personaId', '');
        $personaToken = (string) self::getValue('api.personaToken', '');
        $serie01  = (string) self::getValue('api.serie01', '');
        $serie03  = (string) self::getValue('api.serie03', '');
        $serie07F = (string) self::getValue('api.serie07F', '');
        $serie07B = (string) self::getValue('api.serie07B', '');

        if ($personaId === '' || $personaToken === '') {
            $branches = self::getValue('api.branches', []);
            if (!empty($branches) && is_array($branches)) {
                $first = $branches[0];
                $personaId    = (string) ($first['personaId']    ?? '');
                $personaToken = (string) ($first['personaToken'] ?? '');
                $serie01      = (string) ($first['serie01']      ?? '');
                $serie03      = (string) ($first['serie03']      ?? '');
                $serie07F     = (string) ($first['serie07F']     ?? '');
                $serie07B     = (string) ($first['serie07B']     ?? '');
            }
        }

        return [
            'personaId'    => $personaId,
            'personaToken' => $personaToken,
            'serie01'      => $serie01,
            'serie03'      => $serie03,
            'serie07F'     => $serie07F,
            'serie07B'     => $serie07B,
        ];
    }

    public static function resolveCheckoutMeta(?\WC_Order $order = null): array {
        $mapping = self::getValue('settings.checkout_mapping', []);
        $custom  = (bool) self::getValue('settings.custom_checkout', false);

        if (!$custom || !$order) {
            return [
                'tipo_comprobante' => (string) ($order ? $order->get_meta('_billing_apisunat_cpe_type') ?: '03' : '03'),
                'tipo_documento'   => (string) ($order ? $order->get_meta('_billing_apisunat_id_type') ?: '1' : '1'),
                'numero_documento' => (string) ($order ? $order->get_meta('_billing_apisunat_id_number') : ''),
            ];
        }

        $cpeType = '';
        if (!empty($mapping['document_type_key'])) {
            $raw = (string) $order->get_meta($mapping['document_type_key']);
            $cpeFactura = $mapping['document_type_key_value_01'] ?? '01';
            $cpeBoleta  = $mapping['document_type_key_value_03'] ?? '03';
            if ($raw === $cpeFactura) $cpeType = '01';
            elseif ($raw === $cpeBoleta) $cpeType = '03';
        }

        $docType = '';
        if (!empty($mapping['customer_id_type_key'])) {
            $raw = (string) $order->get_meta($mapping['customer_id_type_key']);
            $docMap = [
                'customer_id_type_value_-' => '-',
                'customer_id_type_value_1' => '1',
                'customer_id_type_value_6' => '6',
                'customer_id_type_value_H' => 'H',
                'customer_id_type_value_7' => '7',
                'customer_id_type_value_4' => '4',
                'customer_id_type_value_E' => 'E',
                'customer_id_type_value_A' => 'A',
                'customer_id_type_value_G' => 'G',
                'customer_id_type_value_C' => 'C',
                'customer_id_type_value_D' => 'D',
                'customer_id_type_value_B' => 'B',
                'customer_id_type_value_0' => '0',
            ];
            foreach ($docMap as $key => $val) {
                if ($raw === ($mapping[$key] ?? $val)) {
                    $docType = $val;
                    break;
                }
            }
        }

        $docNumber = '';
        if (!empty($mapping['customer_id_key'])) {
            $docNumber = (string) $order->get_meta($mapping['customer_id_key']);
        }

        return [
            'tipo_comprobante' => $cpeType !== '' ? $cpeType : '03',
            'tipo_documento'   => $docType !== '' ? $docType : '1',
            'numero_documento' => $docNumber,
        ];
    }

    private static function saveBranchesFromArray(array $branches): void {
        $ids     = self::getBranchIds();
        $newIds  = [];

        foreach ($branches as $index => $branch) {
            if (!is_array($branch)) {
                continue;
            }
            $id = $ids[$index] ?? self::generateBranchId($branch, $ids);
            $newIds[$index] = $id;
            update_option(self::BRANCH_PREFIX . $id, $branch, false);
        }

        foreach ($ids as $oldId) {
            if (!in_array($oldId, $newIds, true)) {
                delete_option(self::BRANCH_PREFIX . $oldId);
            }
        }

        update_option(self::BRANCH_INDEX_KEY, array_values($newIds), false);
    }

    private static function generateBranchId(array $branch, array $existingIds): string {
        $name = $branch['branch_name'] ?? '';
        if ($name !== '') {
            $slug = sanitize_title($name);
            if ($slug !== '') {
                $base = $slug;
                $id   = $base;
                $i    = 1;
                while (in_array($id, $existingIds, true)) {
                    $id = $base . '_' . $i++;
                }
                return $id;
            }
        }

        $id    = 'branch_' . count($existingIds);
        $base  = $id;
        $i     = count($existingIds) + 1;
        while (in_array($id, $existingIds, true)) {
            $id = $base . '_' . $i++;
        }
        return $id;
    }
}
