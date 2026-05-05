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

delete_option('apisunatv2_settings');
delete_option('sunat_migrated');
