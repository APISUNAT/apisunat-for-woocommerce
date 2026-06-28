<?php
namespace Atm\Apisunatwp\Admin;

use Atm\Apisunatwp\Data\Ubigeo;

class GREMetaBox {

    public static function register(): void {
        add_action('add_meta_boxes_shop_order',                 [self::class, 'metaBox']);
        add_action('add_meta_boxes_woocommerce_page_wc-orders', [self::class, 'metaBox']);
        add_action('wp_ajax_apisunat_gre_get_order_data',  [self::class, 'ajaxGetOrderData']);
        add_action('wp_ajax_apisunat_gre_save_draft',      [self::class, 'ajaxSaveDraft']);
        add_action('wp_ajax_apisunat_gre_emit',            [self::class, 'ajaxEmit']);
        add_action('wp_ajax_apisunat_gre_void',            [self::class, 'ajaxVoid']);
        add_action('wp_ajax_apisunat_gre_load',            [self::class, 'ajaxLoad']);
    }

    public static function metaBox(): void {
        add_meta_box(
            'sunat_gre_meta',
            __('Guía de Remisión Electrónica', 'apisunatv2'),
            [self::class, 'render'],
            null,
            'side',
            'default'
        );
    }

    public static function render($object): void {
        if (is_object($object) && method_exists($object, 'get_id')) {
            $order_id = (int) $object->get_id();
        } elseif (is_object($object) && isset($object->ID)) {
            $order_id = (int) $object->ID;
        } else {
            $order_id = (int) $object;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            echo '<p>' . esc_html__('Orden no encontrada', 'apisunatv2') . '</p>';
            return;
        }

        $gre_data = $order->get_meta('_gre_data', true);
        $gre_status = $order->get_meta('_gre_status', true);
        $gre_status = is_string($gre_status) ? $gre_status : '';
        $gre_data = is_array($gre_data) ? $gre_data : [];

        $datos = $gre_data['datos'] ?? [];
        $partida = $gre_data['partida'] ?? [];
        $llegada = $gre_data['llegada'] ?? [];
        $transportista = $gre_data['transportista'] ?? [];
        $vehiculos = $gre_data['vehiculos'] ?? [];
        $conductores = $gre_data['conductores'] ?? [];
        $items = $gre_data['items'] ?? [];

        $ubigeo_js = wp_json_encode(Ubigeo::getData());
        ?>
        <div id="sunat-gre-container" data-order="<?= esc_attr($order_id) ?>" data-status="<?= esc_attr($gre_status) ?>">
            <div id="sunat-gre-status">
                <?php if ($gre_status === ''): ?>
                    <p><span class="sunat-status" style="background:#e2e2e2;color:#555;"><?= esc_html__('Sin GRE', 'apisunatv2') ?></span></p>
                <?php else: ?>
                    <p><span class="sunat-status <?= esc_attr(strtolower($gre_status)) ?>"><?= esc_html($gre_status) ?></span></p>
                <?php endif; ?>
            </div>

            <div id="sunat-gre-form" style="<?= $gre_status === 'EMITIDO' ? 'display:none;' : '' ?>">
                <input type="hidden" id="gre_order_id" value="<?= esc_attr($order_id) ?>">

                <div id="sunat-gre-spinner" style="display:none;text-align:center;padding:20px;">
                    <span class="apisunat-loading"></span>
                </div>

                <div id="sunat-gre-fields">
                    <p class="form-field form-field-wide">
                        <label for="gre_fecha_traslado"><?= esc_html__('Fecha de Inicio de Traslado', 'apisunatv2') ?></label>
                        <input type="date" id="gre_fecha_traslado" class="gre-field" value="<?= esc_attr($datos['fecha_traslado'] ?? wp_date('Y-m-d')) ?>">
                    </p>
                    <p class="form-field form-field-wide">
                        <label for="gre_hora_traslado"><?= esc_html__('Hora de Inicio de Traslado', 'apisunatv2') ?></label>
                        <input type="time" id="gre_hora_traslado" class="gre-field" value="<?= esc_attr($datos['hora_traslado'] ?? wp_date('H:i')) ?>">
                    </p>
                    <p class="form-field form-field-wide">
                        <label for="gre_motivo_traslado"><?= esc_html__('Motivo de Traslado', 'apisunatv2') ?></label>
                        <select id="gre_motivo_traslado" class="gre-field">
                            <option value=""><?= esc_html__('Seleccionar...', 'apisunatv2') ?></option>
                            <option value="01" <?= selected($datos['motivo_traslado'] ?? '', '01', false) ?>><?= esc_html__('Traslado por Venta', 'apisunatv2') ?></option>
                            <!--option value="02" <?= selected($datos['motivo_traslado'] ?? '', '02', false) ?>><?= esc_html__('Traslado por Compra', 'apisunatv2') ?></option-->
                            <option value="03" <?= selected($datos['motivo_traslado'] ?? '', '03', false) ?>><?= esc_html__('Venta con entrega a terceros', 'apisunatv2') ?></option>
                            <option value="14" <?= selected($datos['motivo_traslado'] ?? '', '14', false) ?>><?= esc_html__('Venta sujeta a confirmación', 'apisunatv2') ?></option>
                            <!--option value="18" <?= selected($datos['motivo_traslado'] ?? '', '18', false) ?>><?= esc_html__('Traslado emisor itinerante CP', 'apisunatv2') ?></option>
                            <option value="04" <?= selected($datos['motivo_traslado'] ?? '', '04', false) ?>><?= esc_html__('Traslado entre establecimientos', 'apisunatv2') ?></option>
                            <option value="05" <?= selected($datos['motivo_traslado'] ?? '', '05', false) ?>><?= esc_html__('Traslado por Consignación', 'apisunatv2') ?></option>
                            <option value="06" <?= selected($datos['motivo_traslado'] ?? '', '06', false) ?>><?= esc_html__('Traslado por Devolución', 'apisunatv2') ?></option>
                            <option value="08" <?= selected($datos['motivo_traslado'] ?? '', '08', false) ?>><?= esc_html__('Traslado por Importación', 'apisunatv2') ?></option>
                            <option value="09" <?= selected($datos['motivo_traslado'] ?? '', '09', false) ?>><?= esc_html__('Traslado por Exportación', 'apisunatv2') ?></option>
                            <option value="17" <?= selected($datos['motivo_traslado'] ?? '', '17', false) ?>><?= esc_html__('Traslado de bienes para transformación', 'apisunatv2') ?></option>
                            <option value="07" <?= selected($datos['motivo_traslado'] ?? '', '07', false) ?>><?= esc_html__('Recojo de bienes transformados', 'apisunatv2') ?></option>
                            <option value="13" <?= selected($datos['motivo_traslado'] ?? '', '13', false) ?>><?= esc_html__('Otros', 'apisunatv2') ?></option-->
                        </select>
                    </p>

                    <p class="form-field form-field-wide">
                        <label for="gre_modalidad_transporte"><?= esc_html__('Modalidad de Transporte', 'apisunatv2') ?></label>
                        <select id="gre_modalidad_transporte" class="gre-field">
                            <option value=""><?= esc_html__('Seleccionar...', 'apisunatv2') ?></option>
                            <option value="01" <?= selected($datos['modalidad_transporte'] ?? '', '01', false) ?>><?= esc_html__('Transporte Público', 'apisunatv2') ?></option>
                            <option value="02" <?= selected($datos['modalidad_transporte'] ?? '', '02', false) ?>><?= esc_html__('Transporte Privado', 'apisunatv2') ?></option>
                        </select>
                    </p>

                    <div id="gre-vehiculo-categoria-wrapper" class="form-field form-field-wide">
                        <label class="checkbox style-e" for="gre_vehiculo_categoria">
                            <input type="checkbox" id="gre_vehiculo_categoria" value="1" <?php checked(!empty($datos['vehiculo_categoria'])); ?> />
                            <div class="checkbox__checkmark"></div>
                        </label>
                        <span style="margin-left:50px;display:inline-block;vertical-align:middle"><?= esc_html__('Vehículos Categoría M1 o L', 'apisunatv2') ?></span>
                    </div>

                    <div id="gre-transportista-section" style="<?= ($datos['modalidad_transporte'] ?? '') === '01' ? '' : 'display:none;' ?>">
                        <h4><?= esc_html__('Transportista', 'apisunatv2') ?></h4>
                        <div class="form-field form-field-wide">
                            <label for="gre_transportista_nombre"><?= esc_html__('Nombre Transportista', 'apisunatv2') ?></label>
                            <input type="text" id="gre_transportista_nombre" class="gre-field" value="<?= esc_attr($transportista['nombre'] ?? '') ?>">
                        </div>
                        <div class="form-field form-field-wide">
                            <label for="gre_transportista_ruc"><?= esc_html__('RUC Transportista', 'apisunatv2') ?></label>
                            <input type="text" id="gre_transportista_ruc" class="gre-field" value="<?= esc_attr($transportista['ruc'] ?? '') ?>">
                        </div>
                        <div class="form-field form-field-wide">
                            <label for="gre_transportista_mtc"><?= esc_html__('Registro MTC', 'apisunatv2') ?></label>
                            <input type="text" id="gre_transportista_mtc" class="gre-field" value="<?= esc_attr($transportista['registro_mtc'] ?? '') ?>">
                        </div>
                    </div>

                    <div id="gre-vehiculos-section" style="<?= ($datos['modalidad_transporte'] ?? '') === '02' && empty($datos['vehiculo_categoria']) ? '' : 'display:none;' ?>">
                        <h4><?= esc_html__('Vehículos', 'apisunatv2') ?></h4>
                        <div id="gre-vehiculos-list">
                            <?php foreach ($vehiculos as $i => $v): ?>
                            <div class="gre-vehiculo-item" data-index="<?= $i ?>">
                                <p class="form-field form-field-wide">
                                    <label><?= esc_html__('Placa', 'apisunatv2') ?></label>
                                    <input type="text" class="gre-field gre-veh-placa" value="<?= esc_attr($v['placa'] ?? '') ?>">
                                </p>
                                <p class="form-field form-field-wide">
                                    <label><?= esc_html__('N° Autorización Especial', 'apisunatv2') ?></label>
                                    <input type="text" class="gre-field gre-veh-autorizacion" value="<?= esc_attr($v['autorizacion'] ?? '') ?>">
                                </p>
                                <p class="form-field form-field-wide">
                                    <label><?= esc_html__('Entidad Emisora', 'apisunatv2') ?></label>
                                    <select class="gre-field gre-veh-entidad"><?= self::entidadEmisoraOptions($v['entidad'] ?? '') ?></select>
                                </p>
                                <button type="button" class="button gre-remove-vehiculo"><?= esc_html__('Quitar', 'apisunatv2') ?></button>
                                <hr>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button" id="gre-add-vehiculo"><?= esc_html__('Agregar Vehículo', 'apisunatv2') ?></button>
                    </div>

                    <div id="gre-conductores-section" style="<?= ($datos['modalidad_transporte'] ?? '') === '02' && empty($datos['vehiculo_categoria']) ? '' : 'display:none;' ?>">
                        <h4><?= esc_html__('Conductores', 'apisunatv2') ?></h4>
                        <div id="gre-conductores-list">
                            <?php foreach ($conductores as $i => $c): ?>
                            <div class="gre-conductor-item" data-index="<?= $i ?>">
                                <p class="form-field form-field-wide">
                                    <label><?= esc_html__('Tipo Documento', 'apisunatv2') ?></label>
                                    <select class="gre-field gre-con-tipo-doc"><?= self::tipoDocOptions($c['tipo_documento'] ?? '1') ?></select>
                                </p>
                                <p class="form-field form-field-wide">
                                    <label><?= esc_html__('N° Documento', 'apisunatv2') ?></label>
                                    <input type="text" class="gre-field gre-con-num-doc" value="<?= esc_attr($c['numero_documento'] ?? '') ?>">
                                </p>
                                <p class="form-field form-field-wide">
                                    <label><?= esc_html__('Nombres', 'apisunatv2') ?></label>
                                    <input type="text" class="gre-field gre-con-nombres" value="<?= esc_attr($c['nombres'] ?? '') ?>">
                                </p>
                                <p class="form-field form-field-wide">
                                    <label><?= esc_html__('Apellidos', 'apisunatv2') ?></label>
                                    <input type="text" class="gre-field gre-con-apellidos" value="<?= esc_attr($c['apellidos'] ?? '') ?>">
                                </p>
                                <p class="form-field form-field-wide">
                                    <label><?= esc_html__('Licencia de Conducir', 'apisunatv2') ?></label>
                                    <input type="text" class="gre-field gre-con-licencia" value="<?= esc_attr($c['licencia'] ?? '') ?>">
                                </p>
                                <button type="button" class="button gre-remove-conductor"><?= esc_html__('Quitar', 'apisunatv2') ?></button>
                                <hr>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button" id="gre-add-conductor"><?= esc_html__('Agregar Conductor', 'apisunatv2') ?></button>
                    </div>

                    <h4><?= esc_html__('Punto de Partida', 'apisunatv2') ?></h4>
                    <p class="form-field form-field-wide">
                        <label for="gre_partida_departamento"><?= esc_html__('Departamento', 'apisunatv2') ?></label>
                        <select id="gre_partida_departamento" class="gre-field gre-ubigeo-dep" data-saved="<?= esc_attr($partida['departamento'] ?? '') ?>">
                            <option value=""><?= esc_html__('Seleccionar...', 'apisunatv2') ?></option>
                        </select>
                    </p>
                    <p class="form-field form-field-wide">
                        <label for="gre_partida_provincia"><?= esc_html__('Provincia', 'apisunatv2') ?></label>
                        <select id="gre_partida_provincia" class="gre-field gre-ubigeo-prov" data-saved="<?= esc_attr($partida['provincia'] ?? '') ?>">
                            <option value=""><?= esc_html__('Seleccionar...', 'apisunatv2') ?></option>
                        </select>
                    </p>
                    <p class="form-field form-field-wide">
                        <label for="gre_partida_distrito"><?= esc_html__('Distrito', 'apisunatv2') ?></label>
                        <select id="gre_partida_distrito" class="gre-field gre-ubigeo-dist" data-saved="<?= esc_attr($partida['distrito'] ?? '') ?>">
                            <option value=""><?= esc_html__('Seleccionar...', 'apisunatv2') ?></option>
                        </select>
                    </p>
                    <p class="form-field form-field-wide">
                        <label for="gre_partida_direccion"><?= esc_html__('Dirección', 'apisunatv2') ?></label>
                        <input type="text" id="gre_partida_direccion" class="gre-field" value="<?= esc_attr($partida['direccion'] ?? '') ?>">
                    </p>

                    <h4><?= esc_html__('Punto de Llegada', 'apisunatv2') ?></h4>
                    <p class="form-field form-field-wide">
                        <label for="gre_llegada_departamento"><?= esc_html__('Departamento', 'apisunatv2') ?></label>
                        <select id="gre_llegada_departamento" class="gre-field gre-ubigeo-dep" data-saved="<?= esc_attr($llegada['departamento'] ?? '') ?>">
                            <option value=""><?= esc_html__('Seleccionar...', 'apisunatv2') ?></option>
                        </select>
                    </p>
                    <p class="form-field form-field-wide">
                        <label for="gre_llegada_provincia"><?= esc_html__('Provincia', 'apisunatv2') ?></label>
                        <select id="gre_llegada_provincia" class="gre-field gre-ubigeo-prov" data-saved="<?= esc_attr($llegada['provincia'] ?? '') ?>">
                            <option value=""><?= esc_html__('Seleccionar...', 'apisunatv2') ?></option>
                        </select>
                    </p>
                    <p class="form-field form-field-wide">
                        <label for="gre_llegada_distrito"><?= esc_html__('Distrito', 'apisunatv2') ?></label>
                        <select id="gre_llegada_distrito" class="gre-field gre-ubigeo-dist" data-saved="<?= esc_attr($llegada['distrito'] ?? '') ?>">
                            <option value=""><?= esc_html__('Seleccionar...', 'apisunatv2') ?></option>
                        </select>
                    </p>
                    <p class="form-field form-field-wide">
                        <label for="gre_llegada_direccion"><?= esc_html__('Dirección', 'apisunatv2') ?></label>
                        <input type="text" id="gre_llegada_direccion" class="gre-field" value="<?= esc_attr($llegada['direccion'] ?? '') ?>">
                    </p>

                    <div class="form-field form-field-wide">
                        <label class="checkbox style-e" for="gre_customize_items">
                            <input type="checkbox" id="gre_customize_items" value="1" <?php checked(!empty($items)); ?> />
                            <div class="checkbox__checkmark"></div>
                        </label>
                        <span style="margin-left:50px;display:inline-block;vertical-align:middle"><?= esc_html__('Personalizar ítems', 'apisunatv2') ?></span>
                    </div>

                    <div id="gre-items-wrapper" style="<?= !empty($items) ? '' : 'display:none;' ?>">
                        <h4><?= esc_html__('Productos', 'apisunatv2') ?></h4>
                        <div id="gre-items-list">
                        <?php foreach ($items as $i => $it): ?>
                        <div class="gre-item-row" data-index="<?= $i ?>">
                            <p class="form-field form-field-wide">
                                <label><?= esc_html__('Producto', 'apisunatv2') ?></label>
                                <input type="text" class="gre-field gre-item-nombre" value="<?= esc_attr($it['nombre'] ?? '') ?>">
                            </p>
                            <p class="form-field form-field-wide">
                                <label><?= esc_html__('Cantidad', 'apisunatv2') ?></label>
                                <input type="number" class="gre-field gre-item-cantidad" value="<?= esc_attr($it['cantidad'] ?? '1') ?>" min="1" step="1">
                            </p>
                            <p class="form-field form-field-wide">
                                <label><?= esc_html__('Unidad Medida', 'apisunatv2') ?></label>
                                <input type="text" class="gre-field gre-item-unidad" value="<?= esc_attr($it['unidad'] ?? 'ZZ') ?>" placeholder="ZZ">
                            </p>
                            <button type="button" class="button gre-remove-item"><?= esc_html__('Quitar', 'apisunatv2') ?></button>
                            <hr>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button" id="gre-add-item"><?= esc_html__('Agregar Producto', 'apisunatv2') ?></button>
                    </div>

                    <p class="form-field form-field-wide">
                        <label for="gre_peso_bruto"><?= esc_html__('Peso Bruto Total (kg)', 'apisunatv2') ?></label>
                        <input type="number" id="gre_peso_bruto" class="gre-field" value="<?= esc_attr($datos['peso_bruto'] ?? '') ?>" step="0.01" min="0">
                    </p>
                    <p class="form-field form-field-wide">
                        <label for="gre_observaciones"><?= esc_html__('Observaciones', 'apisunatv2') ?></label>
                        <textarea id="gre_observaciones" class="gre-field" rows="2"><?= esc_textarea($datos['observaciones'] ?? '') ?></textarea>
                    </p>
                </div>

                <p style="display:flex;gap:4px;flex-wrap:wrap;">
                    <button type="button" class="button" id="gre-save-draft-btn"><?= esc_html__('Guardar Borrador', 'apisunatv2') ?></button>
                    <button type="button" class="button button-primary" id="gre-emit-btn"><?= esc_html__('Emitir GRE', 'apisunatv2') ?></button>
                </p>
            </div>

            <div id="sunat-gre-emitido" style="<?= $gre_status === 'EMITIDO' ? '' : 'display:none;' ?>">
                <p><?= esc_html__('GRE emitida', 'apisunatv2') ?></p>
                <?php if (!empty($gre_data['numero'])): ?>
                    <p><strong><?= esc_html($gre_data['numero']) ?></strong></p>
                <?php endif; ?>
                <button type="button" class="button apisunat-void-btn" id="gre-void-btn"><?= esc_html__('Anular GRE', 'apisunatv2') ?></button>
                <div id="gre-void-reason" style="display:none;margin-top:8px;">
                    <textarea id="gre-void-reason-input" rows="2" placeholder="<?= esc_attr__('Motivo de anulación...', 'apisunatv2') ?>" style="width:100%;"></textarea>
                    <button type="button" class="button button-primary" id="gre-confirm-void" style="margin-top:4px;"><?= esc_html__('Confirmar Anulación', 'apisunatv2') ?></button>
                </div>
            </div>
        </div>

        <script>
        window.apisunatv2_ubigeo = <?= $ubigeo_js ?>;
        </script>
        <?php
    }

    private static function tipoDocOptions(string $selected): string {
        $opts = [
            '-' => __('Sin documento', 'apisunatv2'),
            '1' => __('DNI', 'apisunatv2'),
            '6' => __('RUC', 'apisunatv2'),
            '7' => __('Pasaporte', 'apisunatv2'),
            '4' => __('Carnet de extranjería', 'apisunatv2'),
        ];
        $html = '<option value="">' . esc_html__('Seleccionar...', 'apisunatv2') . '</option>';
        foreach ($opts as $v => $l) {
            $html .= '<option value="' . esc_attr($v) . '" ' . selected($selected, $v, false) . '>' . esc_html($l) . '</option>';
        }
        return $html;
    }

    private static function entidadEmisoraOptions(string $selected): string {
        $opts = [
            '01' => __('SUCAMEC', 'apisunatv2'),
            '02' => __('DIGEMID', 'apisunatv2'),
            '03' => __('DIGESA', 'apisunatv2'),
            '04' => __('SENASA', 'apisunatv2'),
            '05' => __('SERFOR', 'apisunatv2'),
            '06' => __('MTC', 'apisunatv2'),
            '07' => __('PRODUCE', 'apisunatv2'),
            '08' => __('MIN. AMBIENTE', 'apisunatv2'),
            '09' => __('SANIPES', 'apisunatv2'),
            '10' => __('MML', 'apisunatv2'),
            '11' => __('MINSA', 'apisunatv2'),
            '12' => __('GR', 'apisunatv2'),
        ];
        $html = '<option value="">' . esc_html__('Seleccionar...', 'apisunatv2') . '</option>';
        foreach ($opts as $v => $l) {
            $html .= '<option value="' . esc_attr($v) . '" ' . selected($selected, $v, false) . '>' . esc_html($l) . '</option>';
        }
        return $html;
    }

    private static function authorize(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permiso denegado', 'apisunatv2')], 403);
        }
        check_ajax_referer(OrderActions::NONCE_ACTION, 'nonce');
    }

    private static function getOrderFromRequest(): ?\WC_Order {
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if (!$order_id) {
            return null;
        }
        $order = wc_get_order($order_id);
        return $order ?: null;
    }

    public static function ajaxGetOrderData(): void {
        self::authorize();
        $order = self::getOrderFromRequest();
        if (!$order) {
            wp_send_json_error(['message' => __('Orden no encontrada', 'apisunatv2')]);
        }

        $shipping = $order->get_address('shipping');
        $billing = $order->get_address('billing');

        $addr = !empty($shipping['address_1']) ? $shipping : $billing;

        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $items[] = [
                'nombre'   => $item->get_name(),
                'cantidad' => $item->get_quantity(),
                'unidad'   => 'ZZ',
            ];
        }

        $docType = (string) ($order->get_meta('_billing_apisunat_id_type') ?: '6');
        $docNum  = (string) $order->get_meta('_billing_apisunat_id_number');
        if ($docNum === '') {
            $docNum = $billing['company'] ? '' : $billing['postcode'] ?? '';
        }

        wp_send_json_success([
            'items'            => $items,
            'partida_direccion'=> $addr['address_1'] ?? '',
            'llegada_direccion'=> $billing['address_1'] ?? '',
        ]);
    }

    public static function ajaxLoad(): void {
        self::authorize();
        $order = self::getOrderFromRequest();
        if (!$order) {
            wp_send_json_error(['message' => __('Orden no encontrada', 'apisunatv2')]);
        }
        $gre_data = $order->get_meta('_gre_data', true);
        $gre_status = $order->get_meta('_gre_status', true);
        wp_send_json_success([
            'gre_data' => is_array($gre_data) ? $gre_data : [],
            'gre_status' => is_string($gre_status) ? $gre_status : '',
        ]);
    }

    public static function ajaxSaveDraft(): void {
        self::authorize();
        $order = self::getOrderFromRequest();
        if (!$order) {
            wp_send_json_error(['message' => __('Orden no encontrada', 'apisunatv2')]);
        }

        try {
            $gre_data = self::collectGredata();
        } catch (\InvalidArgumentException $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
            return;
        }
        $order->update_meta_data('_gre_data', $gre_data);
        $order->update_meta_data('_gre_status', 'BORRADOR');
        $order->save();

        wp_send_json_success(['message' => __('Borrador guardado', 'apisunatv2')]);
    }

    public static function ajaxEmit(): void {
        self::authorize();
        $order = self::getOrderFromRequest();
        if (!$order) {
            wp_send_json_error(['message' => __('Orden no encontrada', 'apisunatv2')]);
        }

        try {
            $gre_data = self::collectGredata();
        } catch (\InvalidArgumentException $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
            return;
        }

        try {
            $result = \Atm\Apisunatwp\Services\ApiSunatService::sendGRE($order->get_id(), $gre_data);
            $gre_data['numero'] = $result['numero'] ?? '';
            $gre_data['document_id'] = $result['documentId'] ?? '';

            $order->update_meta_data('_gre_data', $gre_data);
            $order->update_meta_data('_gre_status', 'EMITIDO');
            $order->update_meta_data('_gre_document_id', $result['documentId'] ?? '');
            $order->update_meta_data('_gre_number', $result['numero'] ?? '');
            $order->save();

            $order->add_order_note(sprintf(
                __('GRE emitida: %s', 'apisunatv2'),
                $result['numero'] ?? ''
            ));

            wp_send_json_success([
                'message' => __('GRE emitida correctamente', 'apisunatv2'),
                'numero'  => $result['numero'] ?? '',
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public static function ajaxVoid(): void {
        self::authorize();
        $order = self::getOrderFromRequest();
        if (!$order) {
            wp_send_json_error(['message' => __('Orden no encontrada', 'apisunatv2')]);
        }

        $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';
        if (strlen($reason) < 3) {
            wp_send_json_error(['message' => __('Motivo requiere 3+ caracteres', 'apisunatv2')]);
        }

        $docId = (string) $order->get_meta('_gre_document_id');
        if ($docId === '') {
            wp_send_json_error(['message' => __('No hay documento que anular', 'apisunatv2')]);
        }

        try {
            \Atm\Apisunatwp\Services\ApiSunatService::voidGRE($order->get_id(), $docId, $reason);
            $order->update_meta_data('_gre_status', 'ANULADO');
            $order->save();
            $order->add_order_note(sprintf(
                __('GRE anulada. Motivo: %s', 'apisunatv2'),
                esc_html($reason)
            ));
            wp_send_json_success(['message' => __('GRE anulada correctamente', 'apisunatv2')]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private static function collectGredata(): array {
        $get = fn($key) => isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
        $getArr = fn($key) => isset($_POST[$key]) && is_array($_POST[$key]) ? $_POST[$key] : [];

        $vehiculos = [];
        $vh_placas = $getArr('vehiculo_placa');
        foreach ((array) $vh_placas as $i => $placa) {
            if (trim($placa) === '') continue;
            $vehiculos[] = [
                'placa'        => sanitize_text_field($placa),
                'autorizacion' => sanitize_text_field($getArr('vehiculo_autorizacion')[$i] ?? ''),
                'entidad'      => sanitize_text_field($getArr('vehiculo_entidad')[$i] ?? ''),
            ];
        }

        $conductores = [];
        $con_docs = $getArr('conductor_num_doc');
        foreach ((array) $con_docs as $i => $numDoc) {
            if (trim($numDoc) === '') continue;
            $conductores[] = [
                'tipo_documento'  => sanitize_text_field($getArr('conductor_tipo_doc')[$i] ?? '1'),
                'numero_documento'=> sanitize_text_field($numDoc),
                'nombres'         => sanitize_text_field($getArr('conductor_nombres')[$i] ?? ''),
                'apellidos'       => sanitize_text_field($getArr('conductor_apellidos')[$i] ?? ''),
                'licencia'        => sanitize_text_field($getArr('conductor_licencia')[$i] ?? ''),
            ];
        }

        $items = [];
        $it_nombres = $getArr('item_nombre');
        foreach ((array) $it_nombres as $i => $nombre) {
            if (trim($nombre) === '') continue;
            $items[] = [
                'nombre'   => sanitize_text_field($nombre),
                'cantidad' => sanitize_text_field($getArr('item_cantidad')[$i] ?? '1'),
                'unidad'   => sanitize_text_field($getArr('item_unidad')[$i] ?? 'ZZ'),
            ];
        }

        $data = [
            'datos' => [
                'fecha_traslado'       => $get('fecha_traslado'),
                'hora_traslado'        => $get('hora_traslado'),
                'motivo_traslado'      => $get('motivo_traslado'),
                'modalidad_transporte' => $get('modalidad_transporte'),
                'vehiculo_categoria'   => isset($_POST['vehiculo_categoria']) && $_POST['vehiculo_categoria'] === '1' ? '1' : '',
                'customize_items'     => isset($_POST['customize_items']) && $_POST['customize_items'] === '1' ? '1' : '',
                'peso_bruto'           => $get('peso_bruto'),
                'observaciones'        => $get('observaciones'),
            ],
            'partida' => [
                'ubigeo'     => $get('partida_ubigeo'),
                'departamento' => $get('partida_departamento'),
                'provincia'  => $get('partida_provincia'),
                'distrito'   => $get('partida_distrito'),
                'direccion'  => $get('partida_direccion'),
            ],
            'llegada' => [
                'ubigeo'     => $get('llegada_ubigeo'),
                'departamento' => $get('llegada_departamento'),
                'provincia'  => $get('llegada_provincia'),
                'distrito'   => $get('llegada_distrito'),
                'direccion'  => $get('llegada_direccion'),
            ],
            'transportista' => [
                'nombre'      => $get('transportista_nombre'),
                'ruc'         => $get('transportista_ruc'),
                'registro_mtc' => $get('transportista_mtc'),
            ],
            'vehiculos'   => $vehiculos,
            'conductores' => $conductores,
            'items'       => $items,
        ];

        self::validateGredata($data);
        return $data;
    }

    private static function validateGredata(array $data): void {
        $errors = [];
        $modalidad = $data['datos']['modalidad_transporte'] ?? '';

        $required = ['fecha_traslado', 'motivo_traslado', 'modalidad_transporte'];
        foreach ($required as $k) {
            if (trim($data['datos'][$k] ?? '') === '') {
                $errors[] = sprintf(__('El campo %s es obligatorio', 'apisunatv2'), $k);
            }
        }

        if (trim($data['partida']['direccion'] ?? '') === '') {
            $errors[] = __('Dirección de partida es obligatoria', 'apisunatv2');
        }
        if (trim($data['llegada']['direccion'] ?? '') === '') {
            $errors[] = __('Dirección de llegada es obligatoria', 'apisunatv2');
        }

        if (!empty($data['datos']['customize_items'])) {
            if (empty($data['items'])) {
                $errors[] = __('Agregue al menos un ítem', 'apisunatv2');
            }
        }

        if ($modalidad === '02') {
            if (empty($data['vehiculos'])) {
                $errors[] = __('Agregue al menos un vehículo', 'apisunatv2');
            }
            if (empty($data['conductores'])) {
                $errors[] = __('Agregue al menos un conductor', 'apisunatv2');
            }
        }

        if ($errors) {
            throw new \InvalidArgumentException(implode('. ', $errors));
        }
    }
}
