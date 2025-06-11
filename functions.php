<?php
/**
 * Funciones auxiliares del plugin Yeison BTX
 * 
 * @package YeisonBTX
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

function yeison_btx_log($message, $type = 'info', $data = array()) {
    global $wpdb;
    
    // Validar tipo
    $valid_types = array('info', 'error', 'warning', 'success', 'debug');
    if (!in_array($type, $valid_types)) {
        $type = 'info';
    }
    
    // Preparar datos
    $log_data = array(
        'type' => $type,
        'action' => current_action() ?: 'manual',
        'message' => $message,
        'data' => !empty($data) ? wp_json_encode($data) : null,
        'user_id' => get_current_user_id(),
        'ip_address' => yeison_btx_get_ip(),
        'created_at' => current_time('mysql')
    );
    
    // Insertar en DB
    $result = $wpdb->insert(
        $wpdb->prefix . 'yeison_btx_logs',
        $log_data,
        array('%s', '%s', '%s', '%s', '%d', '%s', '%s')
    );
    
    // Si es error crítico, también log en error_log
    if ($type === 'error') {
        error_log(sprintf('[Yeison BTX] %s: %s', $message, wp_json_encode($data)));
    }
    
    return $result ? $wpdb->insert_id : false;
}

function yeison_btx_get_ip() {
    $ip_keys = array('HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
    
    foreach ($ip_keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = filter_var($_SERVER[$key], FILTER_VALIDATE_IP);
            if ($ip !== false) {
                return $ip;
            }
        }
    }
    
    return '0.0.0.0';
}



function yeison_btx_get_option($option, $default = null) {
    $options = get_option('yeison_btx_settings', array());
    return isset($options[$option]) ? $options[$option] : $default;
}

function yeison_btx_update_option($option, $value) {
    $options = get_option('yeison_btx_settings', array());
    $options[$option] = $value;
    return update_option('yeison_btx_settings', $options);
}

function yeison_btx_is_configured() {
    $domain = yeison_btx_get_option('bitrix_domain');
    $client_id = yeison_btx_get_option('client_id');
    $client_secret = yeison_btx_get_option('client_secret');
    
    return !empty($domain) && !empty($client_id) && !empty($client_secret);
}

function yeison_btx_has_valid_tokens() {
    $access_token = yeison_btx_get_option('access_token');
    $refresh_token = yeison_btx_get_option('refresh_token');
    
    return !empty($access_token) && !empty($refresh_token);
}


function yeison_btx_sanitize_form_data($data) {
    $sanitized = array();
    
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $sanitized[$key] = yeison_btx_sanitize_form_data($value);
        } elseif (is_email($value)) {
            $sanitized[$key] = sanitize_email($value);
        } elseif (strpos($key, 'url') !== false || filter_var($value, FILTER_VALIDATE_URL)) {
            $sanitized[$key] = esc_url_raw($value);
        } else {
            $sanitized[$key] = sanitize_text_field($value);
        }
    }
    
    return $sanitized;
}


function yeison_btx_check_webhook_config() {
    $webhooks = yeison_btx_webhooks();
    $api = yeison_btx_api();
    
    return array(
        'webhooks_enabled' => yeison_btx_get_option('webhooks_enabled', true),
        'api_authorized' => $api->is_authorized(),
        'webhook_secret' => !empty(yeison_btx_get_option('webhook_secret')),
        'endpoints_accessible' => yeison_btx_test_webhook_endpoints(),
        'webhooks_registered' => yeison_btx_count_registered_webhooks()
    );
}



function yeison_btx_test_webhook_endpoints() {
    $test_url = rest_url('yeison-bitrix/v1/webhook/status');
    
    $response = wp_remote_get($test_url, array(
        'timeout' => 10
    ));
    
    if (is_wp_error($response)) {
        return false;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    return $status_code === 200;
}


function yeison_btx_count_registered_webhooks() {
    $api = yeison_btx_api();
    
    if (!$api->is_authorized()) {
        return 0;
    }
    
    $response = $api->api_call('event.get');
    
    if (!$response || !isset($response['result'])) {
        return 0;
    }
    
    $site_url = parse_url(home_url(), PHP_URL_HOST);
    $count = 0;
    
    foreach ($response['result'] as $webhook) {
        if (strpos($webhook['HANDLER'], $site_url) !== false) {
            $count++;
        }
    }
    
    return $count;
}



function yeison_btx_map_form_to_lead($form_data) {
    $lead_data = array(
        'TITLE' => 'Lead desde ' . parse_url(home_url(), PHP_URL_HOST),
        'SOURCE_ID' => 'WEB',
        'STATUS_ID' => 'NEW',
        'OPENED' => 'Y',
        'ASSIGNED_BY_ID' => 1,
        'CREATED_DATE' => date('c'),
        'COMMENTS' => ''
    );
    
    // Mapeo inteligente de campos comunes
    $field_mappings = array(
        // Campo formulario => Campo Bitrix24
        'name' => 'NAME',
        'first_name' => 'NAME',
        'nombre' => 'NAME',
        'last_name' => 'LAST_NAME',
        'apellido' => 'LAST_NAME',
        'email' => 'EMAIL',
        'correo' => 'EMAIL',
        'phone' => 'PHONE',
        'telefono' => 'PHONE',
        'tel' => 'PHONE',
        'message' => 'COMMENTS',
        'mensaje' => 'COMMENTS',
        'comments' => 'COMMENTS',
        'comentarios' => 'COMMENTS'
    );
    
    // Aplicar mapeo
    foreach ($form_data as $key => $value) {
        $key_lower = strtolower($key);
        
        // Buscar coincidencia directa
        if (isset($field_mappings[$key_lower])) {
            $bitrix_field = $field_mappings[$key_lower];
            
            // Manejar campos múltiples (email, phone)
            if (in_array($bitrix_field, array('EMAIL', 'PHONE'))) {
                $lead_data[$bitrix_field] = array(
                    array('VALUE' => $value, 'VALUE_TYPE' => 'WORK')
                );
            } else {
                $lead_data[$bitrix_field] = $value;
            }
        }
        
        // Buscar coincidencia parcial
        foreach ($field_mappings as $form_field => $bitrix_field) {
            if (strpos($key_lower, $form_field) !== false && !isset($lead_data[$bitrix_field])) {
                if (in_array($bitrix_field, array('EMAIL', 'PHONE'))) {
                    $lead_data[$bitrix_field] = array(
                        array('VALUE' => $value, 'VALUE_TYPE' => 'WORK')
                    );
                } else {
                    $lead_data[$bitrix_field] = $value;
                }
                break;
            }
        }
    }
    
    // Agregar URL de origen
    if (isset($_SERVER['HTTP_REFERER'])) {
        $lead_data['SOURCE_DESCRIPTION'] = 'Formulario enviado desde: ' . $_SERVER['HTTP_REFERER'];
    }
    
    // Agregar todos los campos al comentario
    $all_fields = array();
    foreach ($form_data as $key => $value) {
        if (!is_array($value)) {
            $all_fields[] = ucfirst(str_replace('_', ' ', $key)) . ': ' . $value;
        }
    }
    
    if (empty($lead_data['COMMENTS'])) {
        $lead_data['COMMENTS'] = implode("\n", $all_fields);
    } else {
        $lead_data['COMMENTS'] .= "\n\n--- Datos adicionales ---\n" . implode("\n", $all_fields);
    }
    
    // Título descriptivo
    if (!empty($lead_data['NAME'])) {
        $lead_data['TITLE'] = 'Lead: ' . $lead_data['NAME'];
    } elseif (isset($lead_data['EMAIL'][0]['VALUE'])) {
        $lead_data['TITLE'] = 'Lead: ' . $lead_data['EMAIL'][0]['VALUE'];
    }
    
    return $lead_data;
}

















add_action('wp_ajax_yeison_btx_test_connection', 'yeison_btx_handle_test_connection');
function yeison_btx_handle_test_connection() {
    // Verificar nonce
    if (!wp_verify_nonce($_POST['nonce'], 'yeison_btx_test')) {
        wp_die('Nonce inválido');
    }
    
    // Verificar permisos
    if (!current_user_can('manage_options')) {
        wp_die('Sin permisos');
    }
    
    $api = yeison_btx_api();
    $result = $api->test_connection();
    
    wp_send_json_success($result);
}



function yeison_btx_validate_domain($domain) {
    // Limpiar dominio
    $domain = trim($domain);
    $domain = str_replace(array('http://', 'https://'), '', $domain);
    $domain = rtrim($domain, '/');
    
    // Verificar formato
    if (empty($domain)) {
        return 'El dominio no puede estar vacío';
    }
    
    if (!preg_match('/^[a-zA-Z0-9.-]+\.bitrix24\.(com|es|de|fr|it|pl|br|uk|eu)$/', $domain)) {
        return 'Formato de dominio inválido. Debe ser: miempresa.bitrix24.com';
    }
    
    return true;
}

/**
 * Limpiar tokens de autenticación
 */
function yeison_btx_clear_tokens() {
    yeison_btx_update_option('access_token', '');
    yeison_btx_update_option('refresh_token', '');
    
    yeison_btx_log('Tokens limpiados', 'info');
}


function yeison_btx_get_api_status() {
    $api = yeison_btx_api();
    
    return array(
        'configured' => $api->is_configured(),
        'authorized' => $api->is_authorized(),
        'domain' => yeison_btx_get_option('bitrix_domain'),
        'client_id' => yeison_btx_get_option('client_id') ? 'Configurado' : 'No configurado',
        'has_tokens' => !empty(yeison_btx_get_option('access_token'))
    );
}




function yeison_btx_add_to_queue($form_type, $form_data) {
    global $wpdb;
    
    // 🔧 LOG DE INICIO
    yeison_btx_log('🔄 Iniciando yeison_btx_add_to_queue', 'debug', array(
        'form_type' => $form_type,
        'data_keys' => array_keys($form_data),
        'data_count' => count($form_data)
    ));
    
    // Verificar que la tabla existe
    $table_name = $wpdb->prefix . 'yeison_btx_queue';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
    
    if (!$table_exists) {
        yeison_btx_log('❌ Tabla yeison_btx_queue no existe', 'error', array(
            'table_name' => $table_name
        ));
        
        // Intentar crear la tabla
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            form_type varchar(50) NOT NULL,
            form_data longtext NOT NULL,
            status varchar(20) DEFAULT 'pending',
            attempts int(11) DEFAULT 0,
            processed_at datetime DEFAULT NULL,
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status_index (status)
        ) {$wpdb->get_charset_collate()};");
        
        yeison_btx_log('🔧 Tabla creada automáticamente', 'info');
    }
    
    // Preparar datos para insertar
    $queue_data = array(
        'form_type' => sanitize_text_field($form_type),
        'form_data' => wp_json_encode($form_data),
        'status' => 'pending',
        'attempts' => 0,
        'created_at' => current_time('mysql')
    );
    
    yeison_btx_log('📋 Datos preparados para insertar', 'debug', array(
        'form_type' => $queue_data['form_type'],
        'form_data_length' => strlen($queue_data['form_data']),
        'status' => $queue_data['status'],
        'created_at' => $queue_data['created_at']
    ));
    
    // Intentar insertar en la base de datos
    $result = $wpdb->insert(
        $table_name,
        $queue_data,
        array('%s', '%s', '%s', '%d', '%s')
    );
    
    // 🔧 LOG DEL RESULTADO
    if ($result === false) {
        yeison_btx_log('❌ Error insertando en cola', 'error', array(
            'wpdb_error' => $wpdb->last_error,
            'wpdb_query' => $wpdb->last_query,
            'table_name' => $table_name,
            'data' => $queue_data
        ));
        return false;
    }
    
    $queue_id = $wpdb->insert_id;
    
    if (!$queue_id) {
        yeison_btx_log('❌ No se obtuvo insert_id', 'error', array(
            'result' => $result,
            'insert_id' => $queue_id,
            'wpdb_error' => $wpdb->last_error
        ));
        return false;
    }
    
    // Verificar que realmente se insertó
    $verification = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE id = %d",
        $queue_id
    ));
    
    if (!$verification) {
        yeison_btx_log('❌ Elemento no se encuentra después de insertar', 'error', array(
            'queue_id' => $queue_id,
            'table_name' => $table_name
        ));
        return false;
    }
    
    yeison_btx_log('✅ Elemento insertado y verificado en cola', 'success', array(
        'queue_id' => $queue_id,
        'form_type' => $form_type,
        'status' => $verification->status,
        'created_at' => $verification->created_at
    ));
    
    return $queue_id;
}

















function yeison_btx_get_stats() {
    global $wpdb;
    
    $stats = array(
        'total_logs' => 0,
        'total_synced' => 0,
        'pending_queue' => 0,
        'errors_today' => 0,
        'last_sync' => null
    );
    
    // Total logs
    $stats['total_logs'] = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}yeison_btx_logs"
    );
    
    // Total sincronizados
    $stats['total_synced'] = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}yeison_btx_sync WHERE sync_status = 'synced'"
    );
    
    // Cola pendiente
    $stats['pending_queue'] = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}yeison_btx_queue WHERE status = 'pending'"
    );
    
    // Errores de hoy
    $stats['errors_today'] = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}yeison_btx_logs 
        WHERE type = 'error' AND DATE(created_at) = %s",
        current_time('Y-m-d')
    ));
    
    // Última sincronización
    $last_sync = $wpdb->get_var(
        "SELECT MAX(created_at) FROM {$wpdb->prefix}yeison_btx_logs 
        WHERE action LIKE '%sync%' AND type = 'success'"
    );
    
    if ($last_sync) {
        $stats['last_sync'] = human_time_diff(strtotime($last_sync), current_time('timestamp')) . ' atrás';
    }
    
    return $stats;
}

function yeison_btx_is_ajax() {
    return defined('DOING_AJAX') && DOING_AJAX;
}

function yeison_btx_json_response($data, $status_code = 200) {
    wp_send_json($data, $status_code);
}


function yeison_btx_debug($data, $label = '') {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[Yeison BTX Debug] ' . $label . ': ' . print_r($data, true));
    }
}





/**
 * Disparar evento personalizado cuando API se autoriza
 */
add_action('yeison_btx_oauth_success', function() {
    do_action('yeison_btx_api_authorized');
});

/**
 * Disparar evento cuando API se desautoriza
 */
add_action('yeison_btx_clear_tokens', function() {
    do_action('yeison_btx_api_deauthorized');
});


/**
 * Vaciar cola pendiente - URL: /wp-admin/admin-ajax.php?action=yeison_btx_clear_queue
 */
add_action('wp_ajax_yeison_btx_clear_queue', 'yeison_btx_clear_pending_queue');
function yeison_btx_clear_pending_queue() {
    if (!current_user_can('manage_options')) {
        wp_die('Sin permisos');
    }
    
    global $wpdb;
    
    // Contar elementos pendientes
    $pending_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}yeison_btx_queue WHERE status = 'pending'");
    
    if ($pending_count == 0) {
        echo '<h2>✅ Cola ya está vacía</h2>';
        exit;
    }
    
    // Mostrar elementos antes de borrar
    $pending_items = $wpdb->get_results("SELECT id, form_type, created_at FROM {$wpdb->prefix}yeison_btx_queue WHERE status = 'pending' ORDER BY created_at DESC");
    
    echo '<h2>🗑️ Elementos en Cola Pendiente (' . $pending_count . ')</h2>';
    echo '<table border="1" style="border-collapse: collapse; width: 100%;">';
    echo '<tr><th>ID</th><th>Tipo</th><th>Fecha</th></tr>';
    
    foreach ($pending_items as $item) {
        echo '<tr>';
        echo '<td>' . $item->id . '</td>';
        echo '<td>' . $item->form_type . '</td>';
        echo '<td>' . $item->created_at . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    
    // Botón de confirmación
    if (!isset($_GET['confirm'])) {
        echo '<br><p style="color: red;"><strong>⚠️ ¿Estás seguro de eliminar todos estos elementos?</strong></p>';
        echo '<a href="' . admin_url('admin-ajax.php?action=yeison_btx_clear_queue&confirm=yes') . '" 
              style="background: red; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">
              🗑️ SÍ, ELIMINAR TODO</a>';
        echo ' ';
        echo '<a href="' . admin_url('admin.php?page=yeison-btx') . '" 
              style="background: gray; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">
              ❌ Cancelar</a>';
    } else {
        // Eliminar elementos
        $deleted = $wpdb->query("DELETE FROM {$wpdb->prefix}yeison_btx_queue WHERE status = 'pending'");
        
        // Log de la acción
        yeison_btx_log('Cola pendiente vaciada manualmente', 'info', array(
            'deleted_count' => $deleted,
            'user_id' => get_current_user_id()
        ));
        
        echo '<br><div style="background: green; color: white; padding: 15px; border-radius: 5px;">';
        echo '<h3>✅ Cola Vaciada Exitosamente</h3>';
        echo '<p>Se eliminaron <strong>' . $deleted . '</strong> elementos de la cola.</p>';
        echo '</div>';
        
        echo '<p><a href="' . admin_url('admin.php?page=yeison-btx') . '">← Volver al Dashboard</a></p>';
    }
    
    exit;
}




/**
 * Forzar registro de webhooks - Acceder: /wp-admin/admin-ajax.php?action=yeison_btx_force_webhooks
 */
add_action('wp_ajax_yeison_btx_force_webhooks', 'yeison_btx_force_webhooks');
function yeison_btx_force_webhooks() {
    if (!current_user_can('manage_options')) {
        wp_die('Sin permisos');
    }
    
    echo '<h2>🔄 Forzando Registro de Webhooks</h2>';
    
    $webhooks = yeison_btx_webhooks();
    $registered = $webhooks->force_register_webhooks();
    
    echo "<div style='background: green; color: white; padding: 15px; margin: 10px 0;'>";
    echo "✅ Webhooks registrados: {$registered}";
    echo "</div>";
    
    // Verificar estado
    $status = $webhooks->get_webhook_status();
    $response_data = $status->get_data();
    
    echo "<h3>📋 Estado Actual:</h3>";
    echo "<pre>" . print_r($response_data, true) . "</pre>";
    
    exit;
}





// Normalizar eventos de webhook
function yeison_btx_normalize_event($raw_event) {
    $event_mapping = array(
        'onCrmContactUpdate' => 'ONCRMCONTACTUPDATE',
        'onCrmContactAdd' => 'ONCRMCONTACTADD',
        'onCrmDealUpdate' => 'ONCRMDEALUPDATE', 
        'onCrmDealAdd' => 'ONCRMDEALADD',
        'onCrmLeadAdd' => 'ONCRMLEADADD',
        'onCrmLeadUpdate' => 'ONCRMLEADUPDATE'
    );
    
    if (isset($event_mapping[$raw_event])) {
        return $event_mapping[$raw_event];
    }
    
    $normalized = strtoupper($raw_event);
    
    yeison_btx_log('🔄 Evento normalizado', 'debug', array(
        'raw_event' => $raw_event,
        'normalized_event' => $normalized,
        'mapping_used' => isset($event_mapping[$raw_event]) ? 'direct' : 'uppercase'
    ));
    
    return $normalized;
}





add_action('admin_init', 'yeison_btx_set_default_options', 1);
function yeison_btx_set_default_options() {
    // Solo ejecutar una vez
    if (get_option('yeison_btx_defaults_set')) {
        return;
    }
    
    // Establecer configuraciones por defecto
    yeison_btx_update_option('bidirectional_sync_enabled', true);
    yeison_btx_update_option('sync_contact_to_customer', true);
    yeison_btx_update_option('sync_deal_to_order', true);
    yeison_btx_update_option('webhooks_enabled', true);
    yeison_btx_update_option('sync_auto_process', true);
    yeison_btx_update_option('prevent_loops', true);
    
    // Marcar como configurado
    update_option('yeison_btx_defaults_set', true);
    
    yeison_btx_log('✅ Configuraciones por defecto establecidas', 'success', array(
        'bidirectional_sync_enabled' => true,
        'sync_contact_to_customer' => true,
        'webhooks_enabled' => true
    ));
}










































































/**
 * Hook para interceptar y debuggear la sincronización Deal → Order
 */
add_action('yeison_btx_deal_webhook_received', 'yeison_btx_debug_deal_to_order_sync', 999, 2);
function yeison_btx_debug_deal_to_order_sync($event, $deal_fields) {
    if ($event !== 'ONCRMDEALUPDATE') {
        return; // Solo procesar actualizaciones
    }
    
    yeison_btx_log('🚀 INICIANDO debug de sincronización Deal → Order', 'info', array(
        'deal_id' => $deal_fields['ID'] ?? 'unknown',
        'stage' => $deal_fields['STAGE_ID'] ?? 'unknown'
    ));
    
    // 1. Verificar si la sincronización bidireccional está habilitada
    $bidirectional_enabled = yeison_btx_get_option('bidirectional_sync_enabled', false);
    $deal_to_order_enabled = yeison_btx_get_option('sync_deal_to_order', false);
    
    yeison_btx_log('⚙️ Verificando configuración de sincronización', 'info', array(
        'bidirectional_sync_enabled' => $bidirectional_enabled,
        'sync_deal_to_order' => $deal_to_order_enabled
    ));
    
    if (!$bidirectional_enabled || !$deal_to_order_enabled) {
        yeison_btx_log('❌ Sincronización Deal → Order DESHABILITADA en configuración', 'warning');
        return;
    }
    
    // 2. Buscar el pedido WooCommerce
    global $wpdb;
    $sync_record = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}yeison_btx_sync 
        WHERE entity_type = 'woo_order' AND remote_id = %s",
        $deal_fields['ID']
    ));
    
    if (!$sync_record) {
        yeison_btx_log('❌ No se encontró pedido WooCommerce para el Deal', 'error', array(
            'deal_id' => $deal_fields['ID']
        ));
        return;
    }
    
    $order_id = $sync_record->local_id;
    yeison_btx_log('✅ Pedido WooCommerce encontrado', 'success', array(
        'order_id' => $order_id,
        'deal_id' => $deal_fields['ID']
    ));
    
    // 3. Verificar que WooCommerce esté activo
    if (!class_exists('WooCommerce')) {
        yeison_btx_log('❌ WooCommerce no está activo', 'error');
        return;
    }
    
    // 4. Obtener el pedido
    $order = wc_get_order($order_id);
    if (!$order) {
        yeison_btx_log('❌ Pedido WooCommerce no encontrado', 'error', array(
            'order_id' => $order_id
        ));
        return;
    }
    
    yeison_btx_log('📦 Estado actual del pedido WooCommerce', 'info', array(
        'order_id' => $order_id,
        'current_woo_status' => $order->get_status(),
        'bitrix_stage' => $deal_fields['STAGE_ID'] ?? 'unknown'
    ));
    
    // 5. Mapear el estado de Bitrix24 a WooCommerce
    $new_wc_status = yeison_btx_map_bitrix_stage_to_wc_status($deal_fields['STAGE_ID']);
    yeison_btx_log('🔄 Mapeando estado Bitrix → WooCommerce', 'info', array(
        'bitrix_stage' => $deal_fields['STAGE_ID'],
        'mapped_wc_status' => $new_wc_status,
        'current_wc_status' => $order->get_status()
    ));
    
    if (!$new_wc_status) {
        yeison_btx_log('⚠️ No se pudo mapear el estado de Bitrix24 a WooCommerce', 'warning', array(
            'bitrix_stage' => $deal_fields['STAGE_ID']
        ));
        return;
    }
    
    if ($new_wc_status === $order->get_status()) {
        yeison_btx_log('ℹ️ El estado ya es el mismo, no se necesita actualizar', 'info', array(
            'current_status' => $order->get_status(),
            'new_status' => $new_wc_status
        ));
        return;
    }
    
    // 6. Verificar sistema anti-loop
    $anti_loop = yeison_btx_anti_loop();
    $can_proceed = apply_filters('yeison_btx_before_sync', true, 'woo_order', $order_id, 'bitrix24');
    
    if (!$can_proceed) {
        yeison_btx_log('🛡️ Sistema anti-loop bloqueó la actualización', 'warning', array(
            'order_id' => $order_id,
            'deal_id' => $deal_fields['ID']
        ));
        return;
    }
    
    // 7. INTENTAR ACTUALIZAR EL PEDIDO
    yeison_btx_log('🔄 Intentando actualizar estado del pedido', 'info', array(
        'order_id' => $order_id,
        'from_status' => $order->get_status(),
        'to_status' => $new_wc_status
    ));
    
    try {
        // Actualizar el estado del pedido
        $update_result = $order->update_status(
            $new_wc_status, 
            sprintf('Estado actualizado desde Bitrix24 Deal #%s - Etapa: %s', 
                $deal_fields['ID'], 
                $deal_fields['STAGE_ID']
            )
        );
        
        if ($update_result) {
            yeison_btx_log('🎉 ÉXITO: Pedido actualizado correctamente', 'success', array(
                'order_id' => $order_id,
                'new_status' => $new_wc_status,
                'deal_id' => $deal_fields['ID'],
                'bitrix_stage' => $deal_fields['STAGE_ID']
            ));
            
            // Actualizar registro de sincronización
            $wpdb->update(
                $wpdb->prefix . 'yeison_btx_sync',
                array(
                    'last_sync' => current_time('mysql'),
                    'sync_data' => wp_json_encode(array(
                        'last_direction' => 'from_bitrix24',
                        'last_status_change' => $order->get_status() . ' → ' . $new_wc_status,
                        'deal_stage' => $deal_fields['STAGE_ID']
                    ))
                ),
                array('id' => $sync_record->id),
                array('%s', '%s'),
                array('%d')
            );
            
            // Hook post-sincronización
            do_action('yeison_btx_after_sync', 'woo_order', $order_id, 'bitrix24', true);
            
        } else {
            yeison_btx_log('❌ FALLO: No se pudo actualizar el pedido', 'error', array(
                'order_id' => $order_id,
                'attempted_status' => $new_wc_status,
                'current_status' => $order->get_status()
            ));
        }
        
    } catch (Exception $e) {
        yeison_btx_log('💥 EXCEPCIÓN al actualizar pedido', 'error', array(
            'order_id' => $order_id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ));
    }
}

/**
 * Función helper para mapear estados Bitrix24 → WooCommerce
 */
function yeison_btx_map_bitrix_stage_to_wc_status($bitrix_stage) {
    $mapping = array(
        'NEW' => 'pending',
        'PREPARATION' => 'processing',
        'EXECUTING' => 'processing',      // ← ESTA ES LA CLAVE
        'PREPAYMENT_INVOICE' => 'on-hold',
        'WON' => 'completed',
        'LOSE' => 'cancelled',
        'APOLOGY' => 'cancelled'
    );
    
    // Permitir mapeo personalizado
    $custom_mapping = yeison_btx_get_option('bitrix_to_wc_status_mapping', array());
    if (!empty($custom_mapping)) {
        $mapping = array_merge($mapping, $custom_mapping);
    }
    
    $result = isset($mapping[$bitrix_stage]) ? $mapping[$bitrix_stage] : null;
    
    yeison_btx_log('🗺️ Mapeo de estado realizado', 'debug', array(
        'bitrix_stage' => $bitrix_stage,
        'wc_status' => $result,
        'mapping_used' => $mapping
    ));
    
    return $result;
}








// Hook para procesamiento diferido de cola
add_action('yeison_btx_process_delayed_queue', function($queue_id) {
    $forms_handler = yeison_btx_forms();
    $forms_handler->process_single_queue_item($queue_id);
});







function yeison_btx_debug_queue_status() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'yeison_btx_queue';
    
    // Verificar si la tabla existe
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
    
    if (!$table_exists) {
        yeison_btx_log('❌ DEBUG: Tabla de cola no existe', 'error', array(
            'table_name' => $table_name
        ));
        return false;
    }
    
    // Contar elementos
    $total_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    $pending_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'pending'");
    $processed_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'processed'");
    $failed_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'failed'");
    
    // Últimos 5 elementos
    $recent_items = $wpdb->get_results("SELECT id, form_type, status, created_at FROM {$table_name} ORDER BY created_at DESC LIMIT 5");
    
    yeison_btx_log('📊 DEBUG: Estado de la cola', 'info', array(
        'table_exists' => true,
        'total_count' => $total_count,
        'pending_count' => $pending_count,
        'processed_count' => $processed_count,
        'failed_count' => $failed_count,
        'recent_items' => $recent_items
    ));
    
    return array(
        'table_exists' => true,
        'counts' => array(
            'total' => $total_count,
            'pending' => $pending_count,
            'processed' => $processed_count,
            'failed' => $failed_count
        ),
        'recent_items' => $recent_items
    );
}




// Endpoint temporal para debugging de cola   :   wp-admin/admin-ajax.php?action=yeison_btx_debug_queue
add_action('wp_ajax_yeison_btx_debug_queue', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Sin permisos');
    }
    
    echo '<h2>🔍 Debug de Cola de Formularios</h2>';
    
    $status = yeison_btx_debug_queue_status();
    
    echo '<pre>';
    print_r($status);
    echo '</pre>';
    
    exit;
});



