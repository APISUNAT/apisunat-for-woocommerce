<?php
namespace Atm\Apisunatwp\Services;

use Atm\Apisunatwp\Config\Options;
use Atm\Apisunatwp\Logging\Logger;

class DocumentConsultaService {

    private const BASE_URL = 'https://back.apisunat.com/personas';
    private const TIMEOUT = 15;

    public static function consultRuc(string $ruc): ?array {
        if (!preg_match('/^[0-9]{11}$/', $ruc)) {
            return null;
        }

        $data = self::fetch('getRUC', ['ruc' => $ruc]);
        if (!$data || empty($data['nombre'])) {
            return null;
        }

        Logger::instance()->info('RUC consulted', ['ruc' => $ruc]);

        $domicilio = $data['domicilio'] ?? [];

        return [
            'name'     => (string) ($data['nombre'] ?? ''),
            'address'  => (string) ($domicilio['direccion'] ?? ''),
            'district' => (string) ($domicilio['distrito'] ?? ''),
            'state'    => (string) ($domicilio['departamento'] ?? ''),
        ];
    }

    public static function consultDni(string $dni): ?array {
        if (!preg_match('/^[0-9]{8}$/', $dni)) {
            return null;
        }

        $data = self::fetch('getDNI', ['dni' => $dni]);
        if (!$data || empty($data['nombre'])) {
            return null;
        }

        Logger::instance()->info('DNI consulted', ['dni' => $dni]);

        $name = trim(
            ($data['nombre']           ?? '') . ' ' .
            ($data['apellido_paterno'] ?? '') . ' ' .
            ($data['apellido_materno'] ?? '')
        );

        $domicilio = $data['domicilio'] ?? [];

        return [
            'name'     => $name,
            'address'  => (string) ($domicilio['direccion'] ?? ''),
            'district' => (string) ($domicilio['distrito'] ?? ''),
            'state'    => (string) ($domicilio['departamento'] ?? ''),
        ];
    }

    private static function fetch(string $endpoint, array $params): ?array {
        $branch = Options::resolveBranch();
        $personaId    = $branch['personaId'] ?? '';
        $personaToken = $branch['personaToken'] ?? '';

        if (!$personaId || !$personaToken) {
            Logger::instance()->error('Missing persona credentials for document consult');
            return null;
        }

        $url = self::BASE_URL . '/' . rawurlencode($personaId) . '/' . $endpoint;
        $url = add_query_arg($params + ['personaToken' => $personaToken], $url);

        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
        ]);

        if (is_wp_error($response)) {
            Logger::instance()->error('Document consult HTTP error', [
                'endpoint' => $endpoint,
                'error'    => $response->get_error_message(),
            ]);
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            Logger::instance()->warning('Document consult non-200', ['endpoint' => $endpoint, 'code' => $code]);
            return null;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['success']) || empty($body['data'])) {
            return null;
        }

        return $body['data'];
    }
}
