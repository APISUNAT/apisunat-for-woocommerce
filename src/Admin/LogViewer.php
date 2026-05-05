<?php
namespace Atm\Apisunatwp\Admin;

class LogViewer {

    private const MAX_LINES = 500;
    private const FILENAME_PATTERN = '/^\d{4}-\d{2}-\d{2}\.log$/';

    public static function register(): void {
        add_action('admin_menu', [self::class, 'addPage']);
    }

    public static function addPage(): void {
        // Solo mostrar si el debug está activado
        if (!\Atm\Apisunatwp\Config\Options::getValue('advanced.debug', false)) {
            return;
        }

        add_submenu_page(
            'woocommerce',
            __('Logs API Sunat', 'apisunatv2'),
            __('Logs', 'apisunatv2'),
            'manage_woocommerce',
            'apisunatv2-logs',
            [self::class, 'render']
        );
    }

    public static function render(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permiso denegado', 'apisunatv2'));
        }

        $logs_dir  = self::getLogDir();
        $log_files = [];

        if ($logs_dir && is_dir($logs_dir)) {
            $files = glob(rtrim($logs_dir, '/') . '/*.log') ?: [];
            $log_files = array_map('basename', $files);
            rsort($log_files);
        }

        $requested = isset($_GET['log_file']) ? basename(wp_unslash($_GET['log_file'])) : '';
        $selected  = '';
        if ($requested && preg_match(self::FILENAME_PATTERN, $requested) && in_array($requested, $log_files, true)) {
            $selected = $requested;
        } elseif (!empty($log_files)) {
            $selected = $log_files[0];
        }

        $lines = [];
        if ($selected && $logs_dir) {
            $path = $logs_dir . '/' . $selected;
            if (is_file($path)) {
                $raw = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($raw !== false) {
                    $lines = array_slice(array_reverse($raw), 0, self::MAX_LINES);
                }
            }
        }

        ?>
        <div class="wrap apisunatv2-logs">
            <h1><?= esc_html(get_admin_page_title()) ?></h1>

            <?php if (empty($log_files)): ?>
                <div class="notice notice-info">
                    <p><?= esc_html__('No hay logs disponibles.', 'apisunatv2') ?></p>
                </div>
            <?php else: ?>
                <form method="get">
                    <input type="hidden" name="page" value="apisunatv2-logs">
                    <label for="apisunat-log-file" class="screen-reader-text"><?= esc_html__('Archivo de log', 'apisunatv2') ?></label>
                    <select name="log_file" id="apisunat-log-file" onchange="this.form.submit()">
                        <?php foreach ($log_files as $file): ?>
                            <option value="<?= esc_attr($file) ?>" <?= selected($selected, $file, false) ?>>
                                <?= esc_html($file) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button" type="submit"><?= esc_html__('Ver', 'apisunatv2') ?></button>
                </form>

                <div class="apisunat-log-viewer">
                    <?php if (empty($lines)): ?>
                        <p class="apisunat-log-empty"><?= esc_html__('Log vacío.', 'apisunatv2') ?></p>
                    <?php else: ?>
                        <?php foreach ($lines as $line): ?>
                            <div class="<?= esc_attr(self::levelClass($line)) ?>"><?= esc_html($line) ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <p class="apisunat-log-path"><code><?= esc_html($selected) ?></code></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function getLogDir(): ?string {
        $upload_dir = wp_upload_dir();
        if ($upload_dir['error'] !== false) {
            return null;
        }
        return rtrim($upload_dir['basedir'], '/') . '/apisunatv2/logs';
    }

    private static function levelClass(string $line): string {
        if (str_contains($line, 'ERROR')) return 'apisunat-log-error';
        if (str_contains($line, 'INFO'))  return 'apisunat-log-info';
        if (str_contains($line, 'DEBUG')) return 'apisunat-log-debug';
        return '';
    }
}
