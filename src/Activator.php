<?php
namespace Atm\Apisunatwp;

use Atm\Apisunatwp\Config\Defaults;
use Atm\Apisunatwp\Config\Options;

class Activator {

    private const INDEX_NAME = 'apisunat_status_idx';

    public static function activate(): void {
        if (!get_option(Options::OPTION_KEY)) {
            update_option(Options::OPTION_KEY, Defaults::get(), false);
        }
        self::ensureIndex();
        self::ensureTaxClasses();
    }

    private static function ensureIndex(): void {
        global $wpdb;
        if (!$wpdb) {
            return;
        }

        $table = $wpdb->postmeta;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM information_schema.statistics
             WHERE table_schema = %s AND table_name = %s AND index_name = %s",
            DB_NAME,
            $table,
            self::INDEX_NAME
        ));

        if ((int) $exists === 0) {
            $wpdb->query("ALTER TABLE {$table} ADD INDEX " . self::INDEX_NAME . " (meta_key(20), meta_value(20))");
        }
    }

    private static function ensureTaxClasses(): void {
        if (!class_exists('WC_Tax')) {
            return;
        }

        global $wpdb;

        $classes = [
            'Exonerado' => 'Exonerado',
            'Inafecto'  => 'Inafecto',
        ];

        $table = $wpdb->prefix . 'wc_tax_rate_classes';
        $existing = $wpdb->get_col("SELECT slug FROM {$table}");

        foreach ($classes as $slug => $name) {
            $key = sanitize_title($slug);
            if (!in_array($key, $existing, true)) {
                \WC_Tax::create_tax_class($name, $key);
            }
        }

        if (get_option('apisunat_tax_setup_done')) {
            return;
        }

        $ratesTable = $wpdb->prefix . 'woocommerce_tax_rates';
        $existingIgvCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM {$ratesTable}
             WHERE tax_rate_country = %s
               AND tax_rate_class = %s
               AND tax_rate BETWEEN %f AND %f",
            'PE',
            '',
            17.5,
            18.5
        ));

        if ($existingIgvCount > 0) {
            update_option('apisunat_tax_setup_done', '1');
            return;
        }

        $rateId = (int) \WC_Tax::_insert_tax_rate([
            'tax_rate_country'  => 'PE',
            'tax_rate_state'    => '',
            'tax_rate'          => '18',
            'tax_rate_name'     => 'IGV',
            'tax_rate_priority' => 1,
            'tax_rate_compound' => 0,
            'tax_rate_shipping' => 1,
            'tax_rate_order'    => 0,
            'tax_rate_class'    => '',
        ]);

        if ($rateId > 0) {
            update_option('apisunat_tax_setup_done', '1');
        }
    }
}
