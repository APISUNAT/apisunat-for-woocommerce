<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    if (class_exists(\Atm\Apisunatwp\Deactivator::class)) {
        \Atm\Apisunatwp\Deactivator::deactivate();
    }
}

$options = [
    'apisunatv2_settings',
    'apisunatv2_settings_api',
    'apisunatv2_settings_issue',
    'apisunatv2_settings_advanced',
    'apisunatv2_settings_settings',
    'apisunatv2_settings_detraction',
    'apisunatv2_settings_branches',
    'apisunatv2_branch_index',
    'apisunat_tax_setup_done'
];

foreach ($options as $option) {
    delete_option($option);
    delete_site_option($option);
}

global $wpdb;
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('apisunatv2_branch_') . '%'
    )
);


/* TODO evaluar si es necesario eliminar datos adicionales como logs, meta keys, etc.

global $wpdb;

$metaKeys = [
    '_sunat_sent',
    '_apisunat_document_status',
    '_billing_apisunat_cpe_type',
    '_billing_apisunat_id_type',
    '_billing_apisunat_id_number',
];

foreach ($metaKeys as $metaKey) {
    $wpdb->delete($wpdb->postmeta, ['meta_key' => $metaKey]);
}

if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('', [], 'apisunatv2');
    as_unschedule_all_actions('', [], 'apisunat-status');
}

$upload = wp_upload_dir();
$logDir = trailingslashit($upload['basedir']) . 'apisunatv2-logs';

if (is_dir($logDir)) {
    foreach (glob($logDir . '/*.log') as $file) {
        if (is_file($file)) {
            wp_delete_file($file);
        }
    }

    @rmdir($logDir);
}
*/
