<?php
/**
 * Sincronización Bidireccional entre WooCommerce y Bitrix24
 * 
 * @package YeisonBTX
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class YeisonBTX_Bidirectional_Sync {
    
    /**
     * Instancia única (Singleton)
     */
    private static $instance = null;
    
    /**
     * Configuración de sincronización
     */
    private $config = array();
    
    /**
     * Constructor privado
     */
    private function __construct() {
        $this->load_config();
        $this->init_hooks();
    }
    
    /**
     * Obtener instancia única
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Cargar configuración
     */
    private function load_config() {
        $this->config = array(
            'enabled' => yeison_btx_get_option('bidirectional_sync_enabled', true),
            'sync_deal_to_order' => yeison_btx_get_option('sync_deal_to_order', true),
            'sync_contact_to_customer' => yeison_btx_get_option('sync_contact_to_customer', true),
            'auto_process' => yeison_btx_get_option('sync_auto_process', true),
            'prevent_loops' => yeison_btx_get_option('sync_prevent_loops', true),
            'update_timeout' => yeison_btx_get_option('sync_update_timeout', 300), // 5 minutos
            'allowed_fields' => yeison_btx_get_option('sync_allowed_fields', array(
                'STAGE_ID', 'TITLE', 'OPPORTUNITY', 'CURRENCY_ID',
                'NAME', 'LAST_NAME', 'EMAIL', 'PHONE'
            ))
        );
    }
    
    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        if (!$this->config['enabled']) {
            return;
        }
        
        // Hooks para procesar webhooks recibidos
        add_action('yeison_btx_deal_webhook_received', array($this, 'process_deal_webhook'), 10, 2);
        add_action('yeison_btx_contact_webhook_received', array($this, 'process_contact_webhook'), 10, 2);
        
        // Cron para procesar cola de sincronización bidireccional
        add_action('yeison_btx_process_bidirectional_queue', array($this, 'process_sync_queue'));
    }
    
    /**
     * Procesar webhook de Deal recibido
     */
    public function process_deal_webhook($event, $deal_fields) {
        try {
            yeison_btx_log('Procesando webhook Deal para sincronización', 'info', array(
                'event' => $event,
                'deal_id' => $deal_fields['ID'] ?? 'unknown',
                'stage' => $deal_fields['STAGE_ID'] ?? 'unknown'
            ));
            
            if (!$this->config['sync_deal_to_order']) {
                yeison_btx_log('Sincronización Deal → Order deshabilitada', 'info');
                return false;
            }
            
            // Buscar pedido WooCommerce correspondiente
            $order_id = $this->find_woocommerce_order_by_deal($deal_fields['ID']);
            
            if (!$order_id) {
                yeison_btx_log('No se encontró pedido WooCommerce para Deal', 'warning', array(
                    'deal_id' => $deal_fields['ID']
                ));
                return false;
            }
            
            // Verificar si se puede actualizar (prevención de loops)
            if (!$this->can_update_order($order_id, 'bitrix24')) {
                yeison_btx_log('Actualización de pedido bloqueada (prevención loop)', 'warning', array(
                    'order_id' => $order_id,
                    'deal_id' => $deal_fields['ID']
                ));
                return false;
            }
            
            // Procesar según el evento
            $result = false;
            
            switch ($event) {
                case 'ONCRMDEALUPDATE':
                    $result = $this->sync_deal_update_to_order($order_id, $deal_fields);
                    break;
                    
                case 'ONCRMDEALADD':
                    // Los deals nuevos normalmente ya están sincronizados desde WC
                    yeison_btx_log('Deal nuevo desde Bitrix24 - verificando si ya existe pedido', 'info', array(
                        'deal_id' => $deal_fields['ID']
                    ));
                    break;
                    
                default:
                    yeison_btx_log('Evento de Deal no manejado', 'warning', array('event' => $event));
                    break;
            }
            
            return $result;
            
        } catch (Exception $e) {
            yeison_btx_log('Error procesando webhook Deal', 'error', array(
                'error' => $e->getMessage(),
                'deal_id' => $deal_fields['ID'] ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ));
            return false;
        }
    }
    
    /**
     * Procesar webhook de Contacto recibido
     */

    public function process_contact_webhook($event, $contact_fields) {
        try {
            yeison_btx_log('Webhook Contact recibido para sincronización bidireccional', 'info', array(
                'event' => $event,
                'contact_id' => $contact_fields['ID'] ?? 'unknown',
                'contact_name' => trim(($contact_fields['NAME'] ?? '') . ' ' . ($contact_fields['LAST_NAME'] ?? ''))
            ));
            
            if (!$this->config['sync_contact_to_customer']) {
                yeison_btx_log('Sincronización Contact → Customer deshabilitada', 'info');
                return false;
            }
            
            // Buscar customer WooCommerce correspondiente
            $customer_id = $this->find_woocommerce_customer_by_contact($contact_fields['ID']);
            
            if (!$customer_id) {
                yeison_btx_log('No se encontró customer WooCommerce para Contact', 'warning', array(
                    'contact_id' => $contact_fields['ID'],
                    'contact_email' => $contact_fields['EMAIL'][0]['VALUE'] ?? 'sin email'
                ));
                return false;
            }
            
            // Verificar si se puede actualizar (sistema anti-loop)
            if (!$this->can_update_customer($customer_id, 'bitrix24')) {
                yeison_btx_log('Actualización de customer bloqueada por sistema anti-loop', 'warning', array(
                    'customer_id' => $customer_id,
                    'contact_id' => $contact_fields['ID'],
                    'event' => $event
                ));
                return false;
            }
            
            // Procesar actualización
            $result = $this->sync_contact_update_to_customer($customer_id, $contact_fields);
            
            if ($result) {
                yeison_btx_log('Sincronización bidireccional Contact→Customer completada', 'success', array(
                    'customer_id' => $customer_id,
                    'contact_id' => $contact_fields['ID'],
                    'event' => $event
                ));
            }
            
            return $result;
            
        } catch (Exception $e) {
            yeison_btx_log('Error procesando webhook Contact para sincronización bidireccional', 'error', array(
                'error' => $e->getMessage(),
                'contact_id' => $contact_fields['ID'] ?? 'unknown',
                'event' => $event,
                'trace' => $e->getTraceAsString()
            ));
            return false;
        }
    }











    
    /**
     * Sincronizar actualización de Deal a Pedido WooCommerce
     */
    
    private function sync_deal_update_to_order($order_id, $deal_fields) {
        // Verificar si WooCommerce está activo
        if (!class_exists('WooCommerce')) {
            return false;
        }
        
        // Sistema anti-loop para evitar sincronización circular
        $anti_loop = yeison_btx_anti_loop();
        $can_proceed = apply_filters('yeison_btx_before_sync', true, 'woo_order', $order_id, 'bitrix24');
        
        // Bloquear si el sistema anti-loop lo impide
        if (!$can_proceed) {
            yeison_btx_log('Sincronización bloqueada por sistema anti-loop avanzado', 'warning', array(
                'order_id' => $order_id,
                'deal_id' => $deal_fields['ID']
            ));
            return false;
        }
        
        // Obtener objeto pedido WooCommerce
        $order = wc_get_order($order_id);
        if (!$order) {
            yeison_btx_log('Pedido WooCommerce no encontrado', 'error', array('order_id' => $order_id));
            return false;
        }
        
        // Variables de control
        $updated = false;
        $changes = array();
        
        // Establecer lock para marcar origen de actualización
        $this->set_update_lock($order_id, 'order', 'bitrix24');
        
        try {
            // === SINCRONIZAR ESTADO/ETAPA ===
            if (isset($deal_fields['STAGE_ID'])) {
                // Mapear etapa de Bitrix24 a estado WooCommerce
                $new_wc_status = $this->map_bitrix_stage_to_wc_status($deal_fields['STAGE_ID']);
                $current_status = $order->get_status();
                
                // Actualizar estado si es diferente
                if ($new_wc_status && $new_wc_status !== $current_status) {
                    $order->update_status($new_wc_status, 'Estado actualizado desde Bitrix24');
                    $changes[] = "Estado: {$current_status} → {$new_wc_status}";
                    $updated = true;
                }
            }
            
            // === SINCRONIZAR TÍTULO ===
            if (isset($deal_fields['TITLE'])) {
                // Agregar nota con el título del deal
                $order_note = "Título en Bitrix24: " . $deal_fields['TITLE'];
                $order->add_order_note($order_note, false, false);
                $changes[] = "Título actualizado: " . $deal_fields['TITLE'];
            }
            
            // === SINCRONIZAR MONTO ===
            if (isset($deal_fields['OPPORTUNITY'])) {
                $bitrix_total = floatval($deal_fields['OPPORTUNITY']);
                $wc_total = floatval($order->get_total());
                
                // Verificar diferencia significativa (>1%)
                if (abs($bitrix_total - $wc_total) > ($wc_total * 0.01)) {
                    $order_note = "Monto en Bitrix24: " . $bitrix_total . " " . ($deal_fields['CURRENCY_ID'] ?? 'USD');
                    $order->add_order_note($order_note, false, false);
                    $changes[] = "Monto sincronizado: {$bitrix_total}";
                }
            }
            
            // === GUARDAR CAMBIOS ===
            if ($updated) {
                $order->save();
                
                // Log de éxito
                yeison_btx_log('Pedido WooCommerce actualizado desde Bitrix24', 'success', array(
                    'order_id' => $order_id,
                    'deal_id' => $deal_fields['ID'],
                    'changes' => $changes
                ));
                
                // Actualizar registro de sincronización
                $this->update_sync_record('woo_order', $order_id, $deal_fields['ID'], array(
                    'last_direction' => 'from_bitrix24',
                    'changes' => $changes
                ));
                
                // Hook post-sincronización
                do_action('yeison_btx_after_sync', 'woo_order', $order_id, 'bitrix24', true);
            }
            
            return $updated;
            
        } finally {
            // Liberar lock automáticamente después de 5 segundos
            wp_schedule_single_event(time() + 5, 'yeison_btx_release_update_lock', array($order_id, 'order'));
        }
    }



























    private function sync_contact_update_to_customer($customer_id, $contact_fields) {
        if (!class_exists('WooCommerce')) {
            yeison_btx_log('WooCommerce no disponible para sync bidireccional', 'error');
            return false;
        }
        
        // Obtener el objeto customer de WooCommerce
        $customer = new WC_Customer($customer_id);
        if (!$customer || !$customer->get_id()) {
            yeison_btx_log('Cliente WooCommerce no encontrado', 'error', array(
                'customer_id' => $customer_id,
                'contact_id' => $contact_fields['ID'] ?? 'unknown'
            ));
            return false;
        }
        
        $updated = false;
        $changes = array();
        
        // Establecer lock para prevenir loops infinitos
        $this->set_update_lock($customer_id, 'customer', 'bitrix24');
        
        try {
            // Sincronizar nombre si ha cambiado
            

            

            // Sincronizar nombre si ha cambiado
            if (isset($contact_fields['NAME']) && !empty($contact_fields['NAME'])) {
                $new_name = sanitize_text_field($contact_fields['NAME']);
                $current_name = $customer->get_first_name();
                
                yeison_btx_log('🔄 Comparando nombres', 'debug', array(
                    'bitrix_name' => $new_name,
                    'wc_current_name' => $current_name,
                    'are_different' => ($new_name !== $current_name),
                    'customer_id' => $customer_id,
                    'contact_id' => $contact_fields['ID']
                ));
                
                if ($new_name !== $current_name) {
                    $customer->set_first_name($new_name);
                    $changes[] = "Nombre: '{$current_name}' → '{$new_name}'";
                    $updated = true;
                    
                    yeison_btx_log('✅ Nombre actualizado', 'success', array(
                        'from' => $current_name,
                        'to' => $new_name,
                        'customer_id' => $customer_id
                    ));
                } else {
                    yeison_btx_log('ℹ️ Nombre sin cambios', 'info', array(
                        'name' => $current_name,
                        'customer_id' => $customer_id
                    ));
                }
            }

            // Sincronizar apellido si ha cambiado
            if (isset($contact_fields['LAST_NAME']) && !empty($contact_fields['LAST_NAME'])) {
                $new_lastname = sanitize_text_field($contact_fields['LAST_NAME']);
                $current_lastname = $customer->get_last_name();
                
                yeison_btx_log('🔄 Comparando apellidos', 'debug', array(
                    'bitrix_lastname' => $new_lastname,
                    'wc_current_lastname' => $current_lastname,
                    'are_different' => ($new_lastname !== $current_lastname),
                    'customer_id' => $customer_id,
                    'contact_id' => $contact_fields['ID']
                ));
                
                if ($new_lastname !== $current_lastname) {
                    $customer->set_last_name($new_lastname);
                    $changes[] = "Apellido: '{$current_lastname}' → '{$new_lastname}'";
                    $updated = true;
                    
                    yeison_btx_log('✅ Apellido actualizado', 'success', array(
                        'from' => $current_lastname,
                        'to' => $new_lastname,
                        'customer_id' => $customer_id
                    ));
                } else {
                    yeison_btx_log('ℹ️ Apellido sin cambios', 'info', array(
                        'lastname' => $current_lastname,
                        'customer_id' => $customer_id
                    ));
                }
            }














            
            // Sincronizar apellido si ha cambiado
            if (isset($contact_fields['LAST_NAME']) && !empty($contact_fields['LAST_NAME'])) {
                $new_lastname = sanitize_text_field($contact_fields['LAST_NAME']);
                if ($new_lastname !== $customer->get_last_name()) {
                    $customer->set_last_name($new_lastname);
                    $changes[] = "Apellido: {$customer->get_last_name()} → {$new_lastname}";
                    $updated = true;
                }
            }
            
            // Sincronizar email si ha cambiado y es válido
            if (isset($contact_fields['EMAIL']) && is_array($contact_fields['EMAIL']) && !empty($contact_fields['EMAIL'])) {
                $new_email = sanitize_email($contact_fields['EMAIL'][0]['VALUE']);
                $current_email = $customer->get_email();
                
                if (!empty($new_email) && is_email($new_email) && $new_email !== $current_email) {
                    // Verificar que el email no esté en uso por otro usuario
                    $existing_user = get_user_by('email', $new_email);
                    if (!$existing_user || $existing_user->ID == $customer_id) {
                        $customer->set_email($new_email);
                        $changes[] = "Email: {$current_email} → {$new_email}";
                        $updated = true;
                    } else {
                        yeison_btx_log('Email ya existe para otro usuario', 'warning', array(
                            'new_email' => $new_email,
                            'existing_user_id' => $existing_user->ID,
                            'customer_id' => $customer_id
                        ));
                    }
                }
            }
            
            







            // Sincronizar teléfono si ha cambiado
            if (isset($contact_fields['PHONE']) && is_array($contact_fields['PHONE']) && !empty($contact_fields['PHONE'])) {
                $new_phone = sanitize_text_field($contact_fields['PHONE'][0]['VALUE']);
                $current_phone = $customer->get_billing_phone();
                
                yeison_btx_log('🔄 Comparando teléfonos', 'debug', array(
                    'bitrix_phone' => $new_phone,
                    'wc_current_phone' => $current_phone,
                    'are_different' => ($new_phone !== $current_phone),
                    'customer_id' => $customer_id,
                    'contact_id' => $contact_fields['ID']
                ));
                
                if (!empty($new_phone) && $new_phone !== $current_phone) {
                    // Actualizar teléfono de facturación
                    $customer->set_billing_phone($new_phone);
                    
                    // También actualizar teléfono de envío si está vacío
                    if (empty($customer->get_shipping_phone())) {
                        $customer->set_shipping_phone($new_phone);
                    }
                    
                    $changes[] = "Teléfono: '{$current_phone}' → '{$new_phone}'";
                    $updated = true;
                    
                    yeison_btx_log('✅ Teléfono actualizado', 'success', array(
                        'from' => $current_phone,
                        'to' => $new_phone,
                        'customer_id' => $customer_id,
                        'updated_billing' => true,
                        'updated_shipping' => empty($customer->get_shipping_phone())
                    ));
                } else {
                    yeison_btx_log('ℹ️ Teléfono sin cambios', 'info', array(
                        'phone' => $current_phone,
                        'customer_id' => $customer_id,
                        'new_phone_empty' => empty($new_phone)
                    ));
                }
            }










            // Sincronizar empresa/compañía si ha cambiado
            if (isset($contact_fields['COMPANY_TITLE']) && !empty($contact_fields['COMPANY_TITLE'])) {
                $new_company = sanitize_text_field($contact_fields['COMPANY_TITLE']);
                $current_company = $customer->get_billing_company();
                
                yeison_btx_log('🔄 Comparando empresas', 'debug', array(
                    'bitrix_company' => $new_company,
                    'wc_current_company' => $current_company,
                    'are_different' => ($new_company !== $current_company),
                    'customer_id' => $customer_id,
                    'contact_id' => $contact_fields['ID']
                ));
                
                if ($new_company !== $current_company) {
                    // Actualizar empresa de facturación
                    $customer->set_billing_company($new_company);
                    
                    // También actualizar empresa de envío si está vacía
                    if (empty($customer->get_shipping_company())) {
                        $customer->set_shipping_company($new_company);
                    }
                    
                    $changes[] = "Empresa: '{$current_company}' → '{$new_company}'";
                    $updated = true;
                    
                    yeison_btx_log('✅ Empresa actualizada', 'success', array(
                        'from' => $current_company,
                        'to' => $new_company,
                        'customer_id' => $customer_id
                    ));
                } else {
                    yeison_btx_log('ℹ️ Empresa sin cambios', 'info', array(
                        'company' => $current_company,
                        'customer_id' => $customer_id
                    ));
                }
            }






            // Sincronizar dirección si ha cambiado
            if (isset($contact_fields['ADDRESS']) && !empty($contact_fields['ADDRESS'])) {
                $new_address = sanitize_text_field($contact_fields['ADDRESS']);
                $current_address = $customer->get_billing_address_1();
                
                yeison_btx_log('🔄 Comparando direcciones', 'debug', array(
                    'bitrix_address' => $new_address,
                    'wc_current_address' => $current_address,
                    'are_different' => ($new_address !== $current_address),
                    'customer_id' => $customer_id,
                    'contact_id' => $contact_fields['ID']
                ));
                
                if ($new_address !== $current_address) {
                    // Actualizar dirección de facturación
                    $customer->set_billing_address_1($new_address);
                    
                    // También actualizar dirección de envío si está vacía
                    if (empty($customer->get_shipping_address_1())) {
                        $customer->set_shipping_address_1($new_address);
                    }
                    
                    $changes[] = "Dirección: '{$current_address}' → '{$new_address}'";
                    $updated = true;
                    
                    yeison_btx_log('✅ Dirección actualizada', 'success', array(
                        'from' => $current_address,
                        'to' => $new_address,
                        'customer_id' => $customer_id
                    ));
                } else {
                    yeison_btx_log('ℹ️ Dirección sin cambios', 'info', array(
                        'address' => $current_address,
                        'customer_id' => $customer_id
                    ));
                }
            }

            // Sincronizar ciudad si ha cambiado
            if (isset($contact_fields['ADDRESS_CITY']) && !empty($contact_fields['ADDRESS_CITY'])) {
                $new_city = sanitize_text_field($contact_fields['ADDRESS_CITY']);
                $current_city = $customer->get_billing_city();
                
                yeison_btx_log('🔄 Comparando ciudades', 'debug', array(
                    'bitrix_city' => $new_city,
                    'wc_current_city' => $current_city,
                    'are_different' => ($new_city !== $current_city),
                    'customer_id' => $customer_id,
                    'contact_id' => $contact_fields['ID']
                ));
                
                if ($new_city !== $current_city) {
                    // Actualizar ciudad de facturación
                    $customer->set_billing_city($new_city);
                    
                    // También actualizar ciudad de envío si está vacía
                    if (empty($customer->get_shipping_city())) {
                        $customer->set_shipping_city($new_city);
                    }
                    
                    $changes[] = "Ciudad: '{$current_city}' → '{$new_city}'";
                    $updated = true;
                    
                    yeison_btx_log('✅ Ciudad actualizada', 'success', array(
                        'from' => $current_city,
                        'to' => $new_city,
                        'customer_id' => $customer_id
                    ));
                } else {
                    yeison_btx_log('ℹ️ Ciudad sin cambios', 'info', array(
                        'city' => $current_city,
                        'customer_id' => $customer_id
                    ));
                }
            }














            
            // Guardar cambios si hubo actualizaciones
            if ($updated) {
                $customer->save();
                
                // Registrar evento exitoso en logs
                yeison_btx_log('Cliente WooCommerce actualizado desde Bitrix24', 'success', array(
                    'customer_id' => $customer_id,
                    'contact_id' => $contact_fields['ID'],
                    'changes' => $changes,
                    'total_changes' => count($changes)
                ));
                
                // Actualizar registro de sincronización
                $this->update_sync_record('woo_customer', $customer_id, $contact_fields['ID'], array(
                    'last_direction' => 'from_bitrix24',
                    'changes' => $changes,
                    'updated_at' => current_time('mysql')
                ));
                
                // Hook post-sincronización
                do_action('yeison_btx_after_sync', 'woo_customer', $customer_id, 'bitrix24', true);
            } else {
                yeison_btx_log('No hay cambios para sincronizar en cliente', 'info', array(
                    'customer_id' => $customer_id,
                    'contact_id' => $contact_fields['ID']
                ));
            }
            
            return $updated;
            
        } catch (Exception $e) {
            yeison_btx_log('Error actualizando cliente desde Bitrix24', 'error', array(
                'error' => $e->getMessage(),
                'customer_id' => $customer_id,
                'contact_id' => $contact_fields['ID'],
                'trace' => $e->getTraceAsString()
            ));
            return false;
        } finally {
            // Liberar lock después de 5 segundos
            wp_schedule_single_event(time() + 5, 'yeison_btx_release_update_lock', array($customer_id, 'customer'));
        }
    }














    
    /**
     * Mapear etapa de Bitrix24 a estado de WooCommerce
     */
    private function map_bitrix_stage_to_wc_status($bitrix_stage) {
        $mapping = array(
            'NEW' => 'pending',
            'PREPARATION' => 'processing',
            'PREPAYMENT_INVOICE' => 'on-hold',
            'WON' => 'completed',
            'LOSE' => 'cancelled',
            'APOLOGY' => 'refunded'
        );
        
        // Permitir mapeo personalizado
        $custom_mapping = yeison_btx_get_option('bitrix_to_wc_status_mapping', array());
        if (!empty($custom_mapping)) {
            $mapping = array_merge($mapping, $custom_mapping);
        }
        
        return isset($mapping[$bitrix_stage]) ? $mapping[$bitrix_stage] : null;
    }
    
    /**
     * Buscar pedido WooCommerce por ID de Deal Bitrix24
     */
    private function find_woocommerce_order_by_deal($deal_id) {
        global $wpdb;
        
        $sync_record = $wpdb->get_row($wpdb->prepare(
            "SELECT local_id FROM {$wpdb->prefix}yeison_btx_sync 
            WHERE entity_type = 'woo_order' AND remote_id = %s",
            $deal_id
        ));
        
        return $sync_record ? intval($sync_record->local_id) : null;
    }
    










    /**
     * REEMPLAZAR COMPLETAMENTE esta función en class-bidirectional-sync.php
     */
    private function find_woocommerce_customer_by_contact($contact_id) {
        global $wpdb;
        
        yeison_btx_log('🔍 Buscando customer WooCommerce', 'info', array(
            'contact_id' => $contact_id
        ));
        
        // 1. Buscar en tabla de sincronización
        $sync_record = $wpdb->get_row($wpdb->prepare(
            "SELECT local_id FROM {$wpdb->prefix}yeison_btx_sync 
            WHERE entity_type IN ('woo_customer', 'woo_guest_contact') AND remote_id = %s",
            $contact_id
        ));
        
        if ($sync_record) {
            // Verificar que el customer existe en WooCommerce
            if (class_exists('WC_Customer')) {
                $customer = new WC_Customer($sync_record->local_id);
                if ($customer->get_id()) {
                    yeison_btx_log('✅ Customer encontrado por sincronización', 'success', array(
                        'contact_id' => $contact_id,
                        'customer_id' => $sync_record->local_id,
                        'customer_email' => $customer->get_email()
                    ));
                    return intval($sync_record->local_id);
                }
            } else {
                // Si no hay WooCommerce, verificar como usuario WordPress
                $user = get_user_by('id', $sync_record->local_id);
                if ($user) {
                    yeison_btx_log('✅ Usuario encontrado por sincronización', 'success', array(
                        'contact_id' => $contact_id,
                        'user_id' => $sync_record->local_id,
                        'user_email' => $user->user_email
                    ));
                    return intval($sync_record->local_id);
                }
            }
        }
        
        // 2. Buscar por email obteniendo datos del contacto desde Bitrix24
        $api = yeison_btx_api();
        if ($api && $api->is_authorized()) {
            $contact_response = $api->api_call('crm.contact.get', array('id' => $contact_id));
            
            if ($contact_response && isset($contact_response['result'])) {
                $contact_data = $contact_response['result'];
                
                // Extraer email
                $email = '';
                if (isset($contact_data['EMAIL']) && is_array($contact_data['EMAIL'])) {
                    foreach ($contact_data['EMAIL'] as $email_data) {
                        if (!empty($email_data['VALUE'])) {
                            $email = $email_data['VALUE'];
                            break;
                        }
                    }
                }
                
                if (!empty($email)) {
                    $user = get_user_by('email', $email);
                    if ($user) {
                        yeison_btx_log('✅ Usuario encontrado por email desde Bitrix24', 'success', array(
                            'contact_id' => $contact_id,
                            'email' => $email,
                            'user_id' => $user->ID
                        ));
                        
                        // Crear registro de sincronización para futuras búsquedas
                        $wpdb->insert(
                            $wpdb->prefix . 'yeison_btx_sync',
                            array(
                                'entity_type' => 'woo_customer',
                                'local_id' => $user->ID,
                                'remote_id' => $contact_id,
                                'sync_status' => 'synced',
                                'last_sync' => current_time('mysql'),
                                'sync_data' => json_encode(array(
                                    'found_by' => 'email_lookup',
                                    'email' => $email
                                ))
                            ),
                            array('%s', '%s', '%s', '%s', '%s', '%s')
                        );
                        
                        yeison_btx_log('📝 Registro de sincronización creado', 'info', array(
                            'contact_id' => $contact_id,
                            'user_id' => $user->ID
                        ));
                        
                        return $user->ID;
                    }
                }
                
                yeison_btx_log('ℹ️ Datos del contacto obtenidos de Bitrix24', 'info', array(
                    'contact_id' => $contact_id,
                    'name' => trim(($contact_data['NAME'] ?? '') . ' ' . ($contact_data['LAST_NAME'] ?? '')),
                    'email_found' => !empty($email),
                    'email' => $email
                ));
            }
        }
        
        yeison_btx_log('❌ No se encontró customer/usuario para contacto', 'warning', array(
            'contact_id' => $contact_id,
            'sync_record_found' => !empty($sync_record),
            'api_authorized' => $api ? $api->is_authorized() : false
        ));
        
        return null;
    }













    
    /**
     * Verificar si se puede actualizar un pedido (prevención de loops)
     */
    private function can_update_order($order_id, $source) {
        if (!$this->config['prevent_loops']) {
            return true;
        }
        
        return $this->can_update_entity($order_id, 'order', $source);
    }
    
    /**
     * Verificar si se puede actualizar un cliente (prevención de loops)
     */
    private function can_update_customer($customer_id, $source) {
        if (!$this->config['prevent_loops']) {
            return true;
        }
        
        return $this->can_update_entity($customer_id, 'customer', $source);
    }
    
    /**
     * Verificar si se puede actualizar una entidad
     */
    private function can_update_entity($entity_id, $entity_type, $source) {
        $lock_key = "yeison_btx_update_lock_{$entity_type}_{$entity_id}";
        $lock_data = get_transient($lock_key);
        
        if ($lock_data) {
            $lock_info = json_decode($lock_data, true);
            
            // Si el lock es del mismo origen, permitir
            if ($lock_info['source'] === $source) {
                return true;
            }
            
            // Si el lock es reciente (menos de timeout), bloquear
            if ((time() - $lock_info['timestamp']) < $this->config['update_timeout']) {
                yeison_btx_log('Actualización bloqueada por lock activo', 'warning', array(
                    'entity_type' => $entity_type,
                    'entity_id' => $entity_id,
                    'lock_source' => $lock_info['source'],
                    'current_source' => $source,
                    'lock_age' => time() - $lock_info['timestamp']
                ));
                return false;
            }
        }
        
        return true;
    }
    
    private function set_update_lock($entity_id, $entity_type, $source) {
        $lock_key = "yeison_btx_update_lock_{$entity_type}_{$entity_id}";
        $lock_data = wp_json_encode(array(
            'source' => $source,
            'timestamp' => time(),
            'entity_id' => $entity_id,
            'entity_type' => $entity_type
        ));
        
        set_transient($lock_key, $lock_data, $this->config['update_timeout']);
        
        yeison_btx_log('Lock de actualización establecido', 'info', array(
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'source' => $source,
            'timeout' => $this->config['update_timeout']
        ));
    }
    
    public function release_update_lock($entity_id, $entity_type) {
        $lock_key = "yeison_btx_update_lock_{$entity_type}_{$entity_id}";
        delete_transient($lock_key);
        
        yeison_btx_log('Lock de actualización liberado', 'info', array(
            'entity_type' => $entity_type,
            'entity_id' => $entity_id
        ));
    }
    
    private function update_sync_record($entity_type, $local_id, $remote_id, $sync_data = array()) {
        global $wpdb;
        
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}yeison_btx_sync 
            WHERE entity_type = %s AND local_id = %s",
            $entity_type, $local_id
        ));
        
        $data = array(
            'last_sync' => current_time('mysql'),
            'sync_data' => wp_json_encode(array_merge(
                json_decode($existing->sync_data ?? '{}', true) ?: array(),
                $sync_data,
                array('last_sync_timestamp' => time())
            ))
        );
        
        if ($existing) {
            $wpdb->update(
                $wpdb->prefix . 'yeison_btx_sync',
                $data,
                array('id' => $existing->id),
                array('%s', '%s'),
                array('%d')
            );
        }
    }
    
    public function process_sync_queue() {
        // Esta función procesará elementos en cola para sincronización bidireccional
        // Por ahora, los webhooks se procesan en tiempo real
        yeison_btx_log('Procesando cola de sincronización bidireccional', 'info');
        
        // Limpiar locks expirados
        $this->cleanup_expired_locks();
        
        return true;
    }
    
    private function cleanup_expired_locks() {
        global $wpdb;
        
        // Buscar transients de locks expirados
        $expired_locks = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} 
            WHERE option_name LIKE %s 
            AND option_value < %d",
            '_transient_timeout_yeison_btx_update_lock_%',
            time()
        ));
        
        foreach ($expired_locks as $lock) {
            $transient_name = str_replace('_transient_timeout_', '', $lock->option_name);
            delete_transient($transient_name);
        }
        
        if (count($expired_locks) > 0) {
            yeison_btx_log('Locks expirados limpiados', 'info', array(
                'cleaned_count' => count($expired_locks)
            ));
        }
    }
    
    public function get_sync_stats() {
        global $wpdb;
        
        return array(
            'enabled' => $this->config['enabled'],
            'deals_synced_from_bitrix' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}yeison_btx_logs 
                WHERE message LIKE %s AND type = 'success' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                '%actualizado desde Bitrix24%'
            )),
            'contacts_synced_from_bitrix' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}yeison_btx_logs 
                WHERE message LIKE %s AND type = 'success' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                '%Cliente WooCommerce actualizado desde Bitrix24%'
            )),
            'active_locks' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} 
                WHERE option_name LIKE %s",
                '_transient_yeison_btx_update_lock_%'
            )),
            'prevented_loops' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}yeison_btx_logs 
                WHERE message LIKE %s AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                '%bloqueada (prevención loop)%'
            ))
        );
    }




}

// Hook para liberar locks
add_action('yeison_btx_release_update_lock', function($entity_id, $entity_type) {
    if (class_exists('YeisonBTX_Bidirectional_Sync')) {
        $sync = YeisonBTX_Bidirectional_Sync::get_instance();
        $sync->release_update_lock($entity_id, $entity_type);
    }
}, 10, 2);

/**
 * Función global para obtener instancia
 */
function yeison_btx_bidirectional_sync() {
    return YeisonBTX_Bidirectional_Sync::get_instance();
}