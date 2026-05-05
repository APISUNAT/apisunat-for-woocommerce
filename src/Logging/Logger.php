<?php
namespace Atm\Apisunatwp\Logging;

use Atm\Apisunatwp\Config\Options;
use Atm\Apisunatwp\Contracts\LoggerInterface;

class Logger implements LoggerInterface {

    private const REDACTED = '***REDACTED***';
    private const MAX_VALUE_LEN = 500;
    private const SENSITIVE_KEYS = [
        'persona_token', 'personatoken', 'persona_id', 'personaid',
        'authorization', 'x-persona-id', 'x-persona-token',
        'token', 'password', 'secret', 'api_key', 'apikey',
    ];

    private string $channel;

    public function __construct(string $channel = 'apisunatv2') {
        $this->channel = $channel;
    }

    public static function instance(): self {
        static $i = null;
        if ($i === null) {
            $i = new self();
        }
        return $i;
    }

    public function info(string $message, array $context = []): void {
        $this->log('INFO', $message, $context);
    }

    public function error(string $message, array $context = []): void {
        $this->log('ERROR', $message, $context);
    }

    public function warning(string $message, array $context = []): void {
        $this->log('WARNING', $message, $context);
    }

    public function debug(string $message, array $context = []): void {
        if (!Options::getValue('advanced.debug')) {
            return;
        }
        $this->log('DEBUG', $message, $context);
    }

    private function log(string $level, string $message, array $context = []): void {
        $context = $this->sanitize($context);

        $entry = sprintf(
            '[%s] [%s] [%s] %s',
            gmdate('Y-m-d H:i:s'),
            $this->channel,
            $level,
            $this->format($message, $context)
        );

        $dir = $this->ensureDir();
        if ($dir === null) {
            error_log('apisunatv2: ' . $entry);
            return;
        }

        $file = $dir . '/' . gmdate('Y-m-d') . '.log';
        @file_put_contents($file, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function sanitize(array $context): array {
        $out = [];
        foreach ($context as $key => $value) {
            $lkey = is_string($key) ? strtolower($key) : (string) $key;
            if (in_array($lkey, self::SENSITIVE_KEYS, true)) {
                $out[$key] = self::REDACTED;
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->sanitize($value);
                continue;
            }
            if (is_string($value) && strlen($value) > self::MAX_VALUE_LEN) {
                $out[$key] = substr($value, 0, self::MAX_VALUE_LEN) . '…(truncated)';
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    private function format(string $message, array $context): string {
        $replace = [];
        foreach ($context as $key => $val) {
            if ($val === null) {
                $replace["{{$key}}"] = 'null';
            } elseif (is_scalar($val)) {
                $replace["{{$key}}"] = (string) $val;
            }
        }

        $output = strtr($message, $replace);

        if (!empty($context)) {
            $output .= PHP_EOL . wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $output;
    }

    private function ensureDir(): ?string {
        $upload_dir = wp_upload_dir();

        if (($upload_dir['error'] ?? false) === false) {
            $log_dir = rtrim($upload_dir['basedir'], '/') . '/apisunatv2/logs';

            if (!is_dir($log_dir)) {
                if (wp_mkdir_p($log_dir)) {
                    $this->protectDir($log_dir);
                    return $log_dir;
                }
            } elseif (is_writable($log_dir)) {
                $this->protectDir($log_dir);
                return $log_dir;
            }
        }

        $log_dir = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($log_dir)) {
            if (@mkdir($log_dir, 0755, true)) {
                $this->protectDir($log_dir);
                return $log_dir;
            }
        } elseif (is_writable($log_dir)) {
            return $log_dir;
        }

        return null;
    }

    private function protectDir(string $dir): void {
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }
        $index = $dir . '/index.html';
        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }
    }
}
