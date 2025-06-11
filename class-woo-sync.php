<?php
/**
 * Sincronización WooCommerce ↔ Bitrix24
 * 
 * @package YeisonBTX
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class YeisonBTX_WooCommerce_Sync {
    
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
            'enabled' => yeison_btx_get_option('woo_sync_enabled', true),
            'sync_orders' => yeison_btx_get_option('woo_sync_orders', true),
            'sync_customers' => yeison_btx_get_option('woo_sync_customers', true),
            'sync_products' => yeison_btx_get_option('woo_sync_products', false),
            'order_statuses' => yeison_btx_get_option('woo_order_statuses', array('processing', 'completed')),
            'create_contacts' => yeison_btx_get_option('woo_create_contacts', true),
            'create_deals' => yeison_btx_get_option('woo_create_deals', true),
            'update_existing' => yeison_btx_get_option('woo_update_existing', true)
        );
    }
    
    /**
     * Inicializar hooks de WooCommerce
     */
    private function init_hooks() {
        // Solo si WooCommerce está activo y sincronización habilitada
        if (!$this->is_woocommerce_active() || !$this->config['enabled']) {
            return;
        }
        
        // Hooks de pedidos
        if ($this->config['sync_orders']) {
            add_action('woocommerce_new_order', array($this, 'on_new_order'), 10, 2);
            add_action('woocommerce_order_status_changed', array($this, 'on_order_status_changed'), 10, 4);
            add_action('woocommerce_payment_complete', array($this, 'on_payment_complete'));
        }
        
        // Hooks de clientes
        if ($this->config['sync_customers']) {
            add_action('woocommerce_created_customer', array($this, 'on_customer_created'), 10, 3);
            add_action('woocommerce_save_account_details', array($this, 'on_customer_updated'));
        }
        
        // Hooks de productos
        if ($this->config['sync_products']) {
            add_action('woocommerce_update_product', array($this, 'on_product_updated'));
            add_action('woocommerce_new_product', array($this, 'on_product_created'));
        }
        
        // Hook de checkout completado
        add_action('woocommerce_thankyou', array($this, 'on_checkout_complete'));
        
        // Cron para sincronización periódica
        add_action('yeison_btx_woo_sync_cron', array($this, 'sync_pending_orders'));
    }
    
    /**
     * Verificar si WooCommerce está activo
     */
    private function is_woocommerce_active() {
        return class_exists('WooCommerce');
    }
    
    /**
     * Nuevo pedido creado
     */
    public function on_new_order($order_id, $order = null) {
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        
        if (!$order) {
            return;
        }
        
        yeison_btx_log('Nuevo pedido WooCommerce', 'info', array(
            'order_id' => $order_id,
            'status' => $order->get_status(),
            'total' => $order->get_total()
        ));
        
        // Sincronizar según configuración
        $this->sync_order_to_bitrix($order);
    }
    
    /**
     * Estado de pedido cambiado
     */
    public function on_order_status_changed($order_id, $from_status, $to_status, $order) {
        yeison_btx_log('Estado de pedido cambiado', 'info', array(
            'order_id' => $order_id,
            'from_status' => $from_status,
            'to_status' => $to_status
        ));
        
        // Solo sincronizar si el nuevo estado está en la lista configurada
        if (in_array($to_status, $this->config['order_statuses'])) {
            $this->sync_order_to_bitrix($order);
        }
    }
    
    /**
     * Pago completado
     */
    public function on_payment_complete($order_id) {
        $order = wc_get_order($order_id);
        
        yeison_btx_log('Pago completado', 'success', array(
            'order_id' => $order_id,
            'total' => $order->get_total(),
            'payment_method' => $order->get_payment_method()
        ));
        
        $this->sync_order_to_bitrix($order, 'payment_complete');
    }
    
    /**
     * Cliente creado
     */
    public function on_customer_created($customer_id, $new_customer_data, $password_generated) {
        $customer = new WC_Customer($customer_id);
        
        yeison_btx_log('Cliente WooCommerce creado', 'info', array(
            'customer_id' => $customer_id,
            'email' => $customer->get_email(),
            'first_name' => $customer->get_first_name()
        ));
        
        $this->sync_customer_to_bitrix($customer);
    }
    
    /**
     * Cliente actualizado
     */
    public function on_customer_updated($user_id) {
        $customer = new WC_Customer($user_id);
        
        yeison_btx_log('Cliente WooCommerce actualizado', 'info', array(
            'customer_id' => $user_id,
            'email' => $customer->get_email()
        ));
        
        $this->sync_customer_to_bitrix($customer, 'update');
    }
    
    /**
     * Checkout completado (para capturar leads adicionales)
     */
    public function on_checkout_complete($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        yeison_btx_log('Checkout completado', 'success', array(
            'order_id' => $order_id,
            'customer_email' => $order->get_billing_email()
        ));
        
        // Crear lead adicional si es un cliente nuevo
        if (!$order->get_customer_id()) {
            $this->create_guest_customer_lead($order);
        }
    }
    
    /**
     * Sincronizar pedido a Bitrix24
     */
    public function sync_order_to_bitrix($order, $trigger = 'order_created') {
        $api = yeison_btx_api();
        
        if (!$api->is_authorized()) {
            yeison_btx_log('No se puede sincronizar: API no autorizada', 'error');
            return false;
        }
        
        try {
            // Verificar si ya existe sincronización
            $existing_sync = $this->get_sync_record('woo_order', $order->get_id());
            
            $result = false;
            
            if ($this->config['create_contacts'] && $order->get_customer_id()) {
                // Sincronizar cliente primero
                $customer = new WC_Customer($order->get_customer_id());
                $contact_id = $this->sync_customer_to_bitrix($customer);
            }
            
            if ($this->config['create_deals']) {
                // Crear o actualizar deal
                if ($existing_sync && $this->config['update_existing']) {
                    $result = $this->update_deal_in_bitrix($order, $existing_sync['remote_id']);
                } else {
                    $result = $this->create_deal_in_bitrix($order);
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            yeison_btx_log('Error sincronizando pedido', 'error', array(
                'order_id' => $order->get_id(),
                'error' => $e->getMessage()
            ));
            return false;
        }
    }
    



    private function create_deal_in_bitrix($order) {
        $api = yeison_btx_api(); // Obtener instancia de la API de Bitrix24
        
        // Crear o buscar el contacto asociado al pedido
        $contact_id = null;
        if ($order->get_customer_id()) {
            // Cliente registrado: sincronizar datos del customer
            $customer = new WC_Customer($order->get_customer_id());
            $contact_id = $this->sync_customer_to_bitrix($customer);
        } else {
            // Cliente invitado: crear contacto temporal
            $contact_id = $this->create_guest_contact_for_order($order);
        }
        
        // Preparar datos del deal usando el mapeo configurado
        $deal_data = $this->map_order_to_deal($order);
        
        // Asignar el contacto al deal si se creó exitosamente
        if ($contact_id) {
            $deal_data['CONTACT_ID'] = $contact_id; // Campo clave para asociar el deal al contacto
        }
        
        // Enviar datos a Bitrix24 para crear el deal
        $deal_response = $api->api_call('crm.deal.add', array(
            'fields' => $deal_data
        ));
        
        if ($deal_response && isset($deal_response['result'])) {
            $deal_id = $deal_response['result']; // ID del deal creado en Bitrix24
            
            // Crear registro de sincronización en la base de datos local
            $this->create_sync_record('woo_order', $order->get_id(), $deal_id, array(
                'type' => 'deal',
                'trigger' => 'order_sync',
                'order_status' => $order->get_status(),
                'order_total' => $order->get_total(),
                'contact_id' => $contact_id // Guardar referencia del contacto asociado
            ));
            
            // Registrar evento exitoso en los logs del sistema
            yeison_btx_log('Deal creado en Bitrix24 con contacto asignado', 'success', array(
                'order_id' => $order->get_id(),
                'deal_id' => $deal_id,
                'contact_id' => $contact_id,
                'deal_title' => $deal_data['TITLE']
            ));
            
            return $deal_id; // Retornar ID del deal creado
        }
        
        return false; // Error al crear el deal
    }


    private function create_guest_contact_for_order($order) {
        $api = yeison_btx_api(); // Obtener instancia de la API de Bitrix24
        
        // Verificar si ya existe un contacto con este email en Bitrix24
        $existing_contact = $this->find_contact_by_email($order->get_billing_email());
        if ($existing_contact) {
            return $existing_contact; // Si ya existe, usar ese contacto para evitar duplicados
        }
        
        // Preparar datos del nuevo contacto desde la información del pedido
        $contact_data = array(
            'NAME' => $order->get_billing_first_name(), // Nombre del cliente desde facturación
            'LAST_NAME' => $order->get_billing_last_name(), // Apellido del cliente
            'OPENED' => 'Y', // Contacto abierto para edición por el equipo
            'SOURCE_ID' => 'WEBFORM', // Origen del contacto: formulario web
            'COMMENTS' => 'Cliente invitado de WooCommerce' // Nota indicando origen del contacto
        );
        
        // Agregar email del cliente si está disponible en el pedido
        if ($order->get_billing_email()) {
            $contact_data['EMAIL'] = array(
                array('VALUE' => $order->get_billing_email(), 'VALUE_TYPE' => 'WORK')
            );
        }
        
        // Agregar teléfono del cliente si está disponible en el pedido
        if ($order->get_billing_phone()) {
            $contact_data['PHONE'] = array(
                array('VALUE' => $order->get_billing_phone(), 'VALUE_TYPE' => 'WORK')
            );
        }
        
        // Construir dirección completa desde los datos de facturación
        if ($order->get_billing_address_1()) {
            $address_parts = array();
            if ($order->get_billing_address_1()) $address_parts[] = $order->get_billing_address_1(); // Dirección línea 1
            if ($order->get_billing_city()) $address_parts[] = $order->get_billing_city(); // Ciudad
            if ($order->get_billing_state()) $address_parts[] = $order->get_billing_state(); // Estado/Provincia
            if ($order->get_billing_country()) $address_parts[] = $order->get_billing_country(); // País
            
            $contact_data['ADDRESS'] = implode(', ', $address_parts); // Combinar dirección en formato texto
        }
        
        // Enviar datos a Bitrix24 para crear el nuevo contacto
        $response = $api->api_call('crm.contact.add', array(
            'fields' => $contact_data
        ));
        
        if ($response && isset($response['result'])) {
            $contact_id = $response['result']; // ID del contacto creado en Bitrix24
            
            // Crear registro de sincronización en base de datos local
            $this->create_sync_record('woo_guest_contact', $order->get_id(), $contact_id, array(
                'type' => 'guest_contact',
                'email' => $order->get_billing_email(),
                'created_for_order' => $order->get_id()
            ));
            
            // Registrar evento exitoso en los logs del sistema
            yeison_btx_log('Contacto invitado creado para pedido', 'success', array(
                'order_id' => $order->get_id(),
                'contact_id' => $contact_id,
                'email' => $order->get_billing_email()
            ));
            
            return $contact_id; // Retornar ID del contacto creado
        }
        
        return null; // Error al crear el contacto
    }




    private function find_contact_by_email($email) {
        if (empty($email)) {
            return null; // No buscar si el email está vacío
        }
        
        $api = yeison_btx_api(); // Obtener instancia de la API de Bitrix24
        
        // Buscar contacto existente por email en Bitrix24
        $response = $api->api_call('crm.contact.list', array(
            'filter' => array(
                'EMAIL' => $email // Filtrar por dirección de email
            ),
            'select' => array('ID', 'EMAIL') // Solo obtener ID y email para optimizar
        ));
        
        if ($response && isset($response['result']) && !empty($response['result'])) {
            $contact_id = $response['result'][0]['ID']; // Tomar el primer contacto encontrado
            
            // Registrar que se encontró un contacto existente
            yeison_btx_log('Contacto existente encontrado por email', 'info', array(
                'email' => $email,
                'contact_id' => $contact_id
            ));
            
            return $contact_id; // Retornar ID del contacto encontrado
        }
        
        return null; // No se encontró contacto con ese email
    }







    private function update_deal_in_bitrix($order, $deal_id) {
        $api = yeison_btx_api();
        
        $deal_data = $this->map_order_to_deal($order, 'update');
        
        $deal_response = $api->api_call('crm.deal.update', array(
            'id' => $deal_id,
            'fields' => $deal_data
        ));
        
        if ($deal_response && isset($deal_response['result'])) {
            yeison_btx_log('Deal actualizado en Bitrix24', 'success', array(
                'order_id' => $order->get_id(),
                'deal_id' => $deal_id
            ));
            
            return true;
        }
        
        return false;
    }
    
    private function map_order_to_deal($order, $action = 'create') {
        $deal_data = array(
            'TITLE' => 'Pedido #' . $order->get_order_number() . ' - ' . $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'TYPE_ID' => 'SALE',
            'STAGE_ID' => $this->map_order_status_to_stage($order->get_status()),
            'OPPORTUNITY' => floatval($order->get_total()),
            'CURRENCY_ID' => $order->get_currency(),
            'OPENED' => 'Y',
            'SOURCE_ID' => 'WEBFORM',
            'ASSIGNED_BY_ID' => 1
        );
        
        // Información de contacto
        if ($order->get_billing_email()) {
            $deal_data['CONTACT_EMAIL'] = $order->get_billing_email();
        }
        
        if ($order->get_billing_phone()) {
            $deal_data['CONTACT_PHONE'] = $order->get_billing_phone();
        }
        
        // Comentarios con información del pedido
        $comments = array();
        $comments[] = "Pedido WooCommerce: #" . $order->get_order_number();
        $comments[] = "Estado: " . wc_get_order_status_name($order->get_status());
        $comments[] = "Total: " . $order->get_formatted_order_total();
        $comments[] = "Método de pago: " . $order->get_payment_method_title();
        $comments[] = "Fecha: " . $order->get_date_created()->format('Y-m-d H:i:s');
        
        // Productos
        $products = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $products[] = $item->get_name() . " (x" . $item->get_quantity() . ") - " . wc_price($item->get_total());
        }
        
        if (!empty($products)) {
            $comments[] = "\nProductos:";
            $comments = array_merge($comments, $products);
        }
        
        // Dirección de facturación
        $billing_address = array();
        if ($order->get_billing_address_1()) {
            $billing_address[] = $order->get_billing_address_1();
        }
        if ($order->get_billing_city()) {
            $billing_address[] = $order->get_billing_city();
        }
        if ($order->get_billing_state()) {
            $billing_address[] = $order->get_billing_state();
        }
        if ($order->get_billing_country()) {
            $billing_address[] = $order->get_billing_country();
        }
        
        if (!empty($billing_address)) {
            $comments[] = "\nDirección: " . implode(', ', $billing_address);
        }
        
        $deal_data['COMMENTS'] = implode("\n", $comments);
        
        // Campos personalizados
        $deal_data['UTM_SOURCE'] = 'woocommerce';
        $deal_data['UTM_MEDIUM'] = 'ecommerce';
        $deal_data['UTM_CAMPAIGN'] = 'order_' . $order->get_id();
        
        return $deal_data;
    }
    
    
    private function map_order_status_to_stage($woo_status) {
        $mapping = array(
            'pending' => 'NEW',
            'processing' => 'EXECUTING',  // Cambiado de PREPARATION a EXECUTING
            'on-hold' => 'PREPAYMENT_INVOICE',
            'completed' => 'WON',
            'cancelled' => 'LOSE',
            'refunded' => 'LOSE',
            'failed' => 'LOSE'
        );
        
        return isset($mapping[$woo_status]) ? $mapping[$woo_status] : 'NEW';
    }












    
    public function sync_customer_to_bitrix($customer, $action = 'create') {
        $api = yeison_btx_api();
        
        if (!$api->is_authorized()) {
            return false;
        }
        
        // Verificar si ya existe
        $existing_sync = $this->get_sync_record('woo_customer', $customer->get_id());
        
        if ($existing_sync && $action === 'create') {
            return $existing_sync['remote_id']; // Ya existe, devolver ID
        }
        
        // Preparar datos del contacto
        $contact_data = array(
            'NAME' => $customer->get_first_name(),
            'LAST_NAME' => $customer->get_last_name(),
            'OPENED' => 'Y',
            'SOURCE_ID' => 'WEBFORM'
        );
        
        // Email
        if ($customer->get_email()) {
            $contact_data['EMAIL'] = array(
                array('VALUE' => $customer->get_email(), 'VALUE_TYPE' => 'WORK')
            );
        }
        
        // Teléfono
        if ($customer->get_billing_phone()) {
            $contact_data['PHONE'] = array(
                array('VALUE' => $customer->get_billing_phone(), 'VALUE_TYPE' => 'WORK')
            );
        }
        
        // Crear o actualizar
        if ($existing_sync && $action === 'update') {
            $response = $api->api_call('crm.contact.update', array(
                'id' => $existing_sync['remote_id'],
                'fields' => $contact_data
            ));
        } else {
            $response = $api->api_call('crm.contact.add', array(
                'fields' => $contact_data
            ));
        }
        
        if ($response && isset($response['result'])) {
            $contact_id = $response['result'];
            
            if ($action === 'create') {
                $this->create_sync_record('woo_customer', $customer->get_id(), $contact_id, array(
                    'type' => 'contact',
                    'email' => $customer->get_email()
                ));
            }
            
            yeison_btx_log('Cliente sincronizado con Bitrix24', 'success', array(
                'customer_id' => $customer->get_id(),
                'contact_id' => $contact_id,
                'action' => $action
            ));
            
            return $contact_id;
        }
        
        return false;
    }
    
    private function create_guest_customer_lead($order) {
        $api = yeison_btx_api();
        
        $lead_data = array(
            'TITLE' => 'Cliente invitado - Pedido #' . $order->get_order_number(),
            'NAME' => $order->get_billing_first_name(),
            'LAST_NAME' => $order->get_billing_last_name(),
            'STATUS_ID' => 'NEW',
            'SOURCE_ID' => 'WEBFORM',
            'OPENED' => 'Y',
            'COMMENTS' => 'Cliente invitado de WooCommerce con pedido #' . $order->get_order_number()
        );
        
        if ($order->get_billing_email()) {
            $lead_data['EMAIL'] = array(
                array('VALUE' => $order->get_billing_email(), 'VALUE_TYPE' => 'WORK')
            );
        }
        
        if ($order->get_billing_phone()) {
            $lead_data['PHONE'] = array(
                array('VALUE' => $order->get_billing_phone(), 'VALUE_TYPE' => 'WORK')
            );
        }
        
        $lead_id = $api->create_lead($lead_data);
        
        if ($lead_id) {
            $this->create_sync_record('woo_guest', $order->get_id(), $lead_id, array(
                'type' => 'guest_lead',
                'email' => $order->get_billing_email()
            ));
        }
        
        return $lead_id;
    }
    
    private function get_sync_record($entity_type, $local_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}yeison_btx_sync 
            WHERE entity_type = %s AND local_id = %s",
            $entity_type, $local_id
        ), ARRAY_A);
    }
    
    
    private function create_sync_record($entity_type, $local_id, $remote_id, $sync_data = array()) {
        global $wpdb;
        
        return $wpdb->insert(
            $wpdb->prefix . 'yeison_btx_sync',
            array(
                'entity_type' => $entity_type,
                'local_id' => $local_id,
                'remote_id' => $remote_id,
                'sync_status' => 'synced',
                'last_sync' => current_time('mysql'),
                'sync_data' => wp_json_encode($sync_data)
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s')
        );
    }
    
    public function sync_pending_orders() {
        if (!$this->config['enabled']) {
            return;
        }
        
        // Obtener pedidos de las últimas 24h que no han sido sincronizados
        $orders = wc_get_orders(array(
            'status' => $this->config['order_statuses'],
            'date_created' => '>' . (time() - DAY_IN_SECONDS),
            'limit' => 50
        ));
        
        $synced = 0;
        foreach ($orders as $order) {
            $existing = $this->get_sync_record('woo_order', $order->get_id());
            
            if (!$existing) {
                if ($this->sync_order_to_bitrix($order)) {
                    $synced++;
                }
            }
        }
        
        if ($synced > 0) {
            yeison_btx_log("Sincronización automática completada: {$synced} pedidos", 'info');
        }
    }
    
    public function get_sync_stats() {
        global $wpdb;
        
        return array(
            'total_orders_synced' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}yeison_btx_sync WHERE entity_type = 'woo_order'"
            ),
            'total_customers_synced' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}yeison_btx_sync WHERE entity_type = 'woo_customer'"
            ),
            'synced_today' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}yeison_btx_sync 
                WHERE DATE(last_sync) = %s",
                current_time('Y-m-d')
            )),
            'pending_orders' => $this->get_pending_orders_count(),
            'woocommerce_active' => $this->is_woocommerce_active(),
            'sync_enabled' => $this->config['enabled']
        );
    }
    
    private function get_pending_orders_count() {
        if (!$this->is_woocommerce_active()) {
            return 0;
        }
        
        global $wpdb;
        
        // Contar pedidos recientes no sincronizados
        $synced_order_ids = $wpdb->get_col(
            "SELECT local_id FROM {$wpdb->prefix}yeison_btx_sync WHERE entity_type = 'woo_order'"
        );
        
        $args = array(
            'status' => $this->config['order_statuses'],
            'date_created' => '>' . (time() - WEEK_IN_SECONDS),
            'return' => 'ids',
            'limit' => -1
        );
        
        if (!empty($synced_order_ids)) {
            $args['exclude'] = $synced_order_ids;
        }
        
        $pending_orders = wc_get_orders($args);
        
        return count($pending_orders);
    }
}



/**
 * Función global para obtener instancia
 */
function yeison_btx_woo_sync() {
    return YeisonBTX_WooCommerce_Sync::get_instance();
}