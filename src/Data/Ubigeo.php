<?php
namespace Atm\Apisunatwp\Data;

class Ubigeo {

    private static ?array $data = null;

    public static function getData(): array {
        if (self::$data !== null) {
            return self::$data;
        }
        $path = dirname(__DIR__, 2) . '/assets/data/ubigeo_peru.json';
        if (!file_exists($path)) {
            return ['departments' => [], 'provinces' => [], 'districts' => []];
        }
        $contents = file_get_contents($path);
        $decoded = json_decode($contents, true);
        self::$data = is_array($decoded) ? $decoded : ['departments' => [], 'provinces' => [], 'districts' => []];
        return self::$data;
    }

    public static function getDepartments(): array {
        return self::getData()['departments'] ?? [];
    }

    public static function getProvinces(string $departmentCode): array {
        $data = self::getData();
        return $data['provinces'][$departmentCode] ?? [];
    }

    public static function getDistricts(string $provinceCode): array {
        $data = self::getData();
        return $data['districts'][$provinceCode] ?? [];
    }

    public static function getDepartmentName(string $code): string {
        foreach (self::getDepartments() as $dep) {
            if ($dep['code'] === $code) {
                return $dep['name'];
            }
        }
        return '';
    }

    public static function getProvinceName(string $code): string {
        $depCode = substr($code, 0, 2);
        foreach (self::getProvinces($depCode) as $prov) {
            if ($prov['code'] === $code) {
                return $prov['name'];
            }
        }
        return '';
    }

    public static function getDistrictName(string $code): string {
        $provCode = substr($code, 0, 4);
        foreach (self::getDistricts($provCode) as $dist) {
            if ($dist['code'] === $code) {
                return $dist['name'];
            }
        }
        return '';
    }
}
