<?php
namespace Atm\Apisunatwp\Admin;

class ProductFields {

    public static function register(): void {
        add_action('woocommerce_product_options_general_product_data', [self::class, 'renderDetraccionField']);
        add_action('woocommerce_process_product_meta', [self::class, 'saveDetraccionField']);
    }

    public static function renderDetraccionField(): void {
        global $post;
        $enabled = get_post_meta($post->ID, '_sunat_detraccion', true);
        $pct = get_post_meta($post->ID, '_sunat_detraccion_pct', true);
        $codigo = get_post_meta($post->ID, '_sunat_detraccion_codigo', true);
        ?>
        <div class="options_group">
            <p class="form-field">
                <label for="_sunat_detraccion"><?php esc_html_e('Detracción SUNAT', 'apisunatv2'); ?></label>
                <input type="checkbox" id="_sunat_detraccion" name="_sunat_detraccion" value="1" <?php checked($enabled, '1'); ?> />
                <span class="description"><?php esc_html_e('Activar detracción (SPOT) para este producto', 'apisunatv2'); ?></span>
            </p>
            <p class="form-field">
                <label for="_sunat_detraccion_pct"><?php esc_html_e('% Detracción', 'apisunatv2'); ?></label>
                <input type="number" id="_sunat_detraccion_pct" name="_sunat_detraccion_pct" value="<?php echo esc_attr($pct); ?>" step="0.01" min="0" max="100" />
            </p>
            <p class="form-field">
                <label for="_sunat_detraccion_codigo"><?php esc_html_e('Código detracción', 'apisunatv2'); ?></label>
                <input type="text" id="_sunat_detraccion_codigo" name="_sunat_detraccion_codigo" value="<?php echo esc_attr($codigo); ?>" placeholder="12" />
            </p>
        </div>
        <?php
    }

    public static function saveDetraccionField(int $post_id): void {
        $enabled = isset($_POST['_sunat_detraccion']) ? '1' : '0';
        update_post_meta($post_id, '_sunat_detraccion', $enabled);

        $pct = isset($_POST['_sunat_detraccion_pct']) ? sanitize_text_field(wp_unslash($_POST['_sunat_detraccion_pct'])) : '';
        if ($pct !== '') {
            update_post_meta($post_id, '_sunat_detraccion_pct', $pct);
        } else {
            delete_post_meta($post_id, '_sunat_detraccion_pct');
        }

        $codigo = isset($_POST['_sunat_detraccion_codigo']) ? sanitize_text_field(wp_unslash($_POST['_sunat_detraccion_codigo'])) : '';
        if ($codigo !== '') {
            update_post_meta($post_id, '_sunat_detraccion_codigo', $codigo);
        } else {
            delete_post_meta($post_id, '_sunat_detraccion_codigo');
        }
    }
}

