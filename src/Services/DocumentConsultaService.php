<?php
namespace Atm\Apisunatwp\Services;

use Atm\Apisunatwp\Config\Options;
use Atm\Apisunatwp\Logging\Logger;

class DocumentConsultaService {

    private const CONSULTA_URL = 'https://api.apisunat.com/v1';
    private const TIMEOUT = 15;

    public static function consultRuc(string $ruc): ?array {
        if (!preg_match('/^[0-9]{11}$/', $ruc)) {
            return null;
        }

        $data = self::fetch('/ruc/' . $ruc);
        if (!$data || !isset($data['razonSocial'])) {
            return null;
        }

        Logger::instance()->info('RUC consulted', ['ruc' => $ruc]);

        return [
            'name'     => (string) ($data['razonSocial']    ?? ''),
            'trade'    => (string) ($data['nombreComercial'] ?? ''),
            'address'  => (string) ($data['direccion']      ?? ''),
            'district' => (string) ($data['distrito']       ?? ''),
            'state'    => (string) ($data['departamento']   ?? ''),
        ];
    }

    public static function consultDni(string $dni): ?array {
        if (!preg_match('/^[0-9]{8}$/', $dni)) {
            return null;
        }

        $data = self::fetch('/dni/' . $dni);
        if (!$data || (!isset($data['nombre']) && !isset($data['nombres']))) {
            return null;
        }

        Logger::instance()->info('DNI consulted', ['dni' => $dni]);

        $name = trim(
            ($data['nombres']         ?? '') . ' ' .
            ($data['apellidoPaterno'] ?? '') . ' ' .
            ($data['apellidoMaterno'] ?? '')
        );

        return ['name' => $name];
    }

    private static function fetch(string $path): ?array {
        $branch = Options::resolveBranch();
        $token  = $branch['persona_token'];
        if (!$token) {
            return null;
        }

        $response = wp_remote_get(self::CONSULTA_URL . $path, [
            'timeout' => self::TIMEOUT,
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        if (is_wp_error($response)) {
            return null;
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        return is_array($data) ? $data : null;
    }
}
