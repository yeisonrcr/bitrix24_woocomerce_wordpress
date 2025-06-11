<?php
/**
 * Sistema Avanzado de Mapeo Bidireccional de Datos
 * 
 * @package YeisonBTX
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class YeisonBTX_Data_Mapping {
    
    /**
     * Instancia única (Singleton)
     */
    private static $instance = null;
    
    /**
     * Configuración de mapeo
     */
    private $config = array();
    
    /**
     * Mapeos personalizados
     */
    private $custom_mappings = array();
    
    /**
     * Constructor privado
     */
    private function __construct() {
        $this->load_config();
        $this->load_custom_mappings();
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
            'enabled' => yeison_btx_get_option('data_mapping_enabled', true),
            'auto_transform' => yeison_btx_get_option('data_mapping_auto_transform', true),
            'validate_data' => yeison_btx_get_option('data_mapping_validate', true),
            'log_transformations' => yeison_btx_get_option('data_mapping_log_transforms', true),
            'fallback_values' => yeison_btx_get_option('data_mapping_fallbacks', array()),
            'transformation_rules' => yeison_btx_get_option('data_mapping_transform_rules', array()),
            'sync_direction' => yeison_btx_get_option('data_mapping_sync_direction', 'bidirectional') // bidirectional, to_bitrix, from_bitrix
        );
    }
    
    /**
     * Cargar mapeos personalizados
     */
    private function load_custom_mappings() {
        $this->custom_mappings = array(
            'order_fields' => yeison_btx_get_option('custom_order_mapping', $this->get_default_order_mapping()),
            'customer_fields' => yeison_btx_get_option('custom_customer_mapping', $this->get_default_customer_mapping()),
            'product_fields' => yeison_btx_get_option('custom_product_mapping', $this->get_default_product_mapping()),
            'form_fields' => yeison_btx_get_option('custom_form_mapping', $this->get_default_form_mapping()),
            'status_mapping' => yeison_btx_get_option('custom_status_mapping', $this->get_default_status_mapping())
        );
    }
    
    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        if (!$this->config['enabled']) {
            return;
        }
        
        // Filtros para transformación de datos
        add_filter('yeison_btx_transform_order_data', array($this, 'transform_order_data'), 10, 3);
        add_filter('yeison_btx_transform_customer_data', array($this, 'transform_customer_data'), 10, 3);
        add_filter('yeison_btx_transform_form_data', array($this, 'transform_form_data'), 10, 3);
        
        // Hooks para validación
        add_filter('yeison_btx_validate_data_before_sync', array($this, 'validate_data_before_sync'), 10, 4);
        
        // Hook para logs de transformación
        add_action('yeison_btx_data_transformed', array($this, 'log_data_transformation'), 10, 4);
    }
    
    /**
     * Transformar datos de pedido
     */
    public function transform_order_data($data, $direction, $context = array()) {
        if (!$this->config['auto_transform']) {
            return $data;
        }
        
        try {
            $mapping = $this->custom_mappings['order_fields'];
            $transformed_data = array();
            
            if ($direction === 'to_bitrix') {
                // WooCommerce → Bitrix24
                foreach ($mapping['wc_to_bitrix'] as $wc_field => $bitrix_field) {
                    if (isset($data[$wc_field])) {
                        $value = $data[$wc_field];
                        
                        // Aplicar transformaciones específicas
                        $value = $this->apply_field_transformation($wc_field, $value, $direction);
                        
                        // Mapear a campo de Bitrix24
                        if (is_array($bitrix_field)) {
                            // Campo complejo (ej: email, phone)
                            $transformed_data[$bitrix_field['field']] = $this->format_complex_field($value, $bitrix_field);
                        } else {
                            $transformed_data[$bitrix_field] = $value;
                        }
                    }
                }
                
                // Agregar campos calculados
                $transformed_data = $this->add_calculated_fields($transformed_data, $data, 'order', $direction);
                
            } elseif ($direction === 'from_bitrix') {
                // Bitrix24 → WooCommerce
                foreach ($mapping['bitrix_to_wc'] as $bitrix_field => $wc_field) {
                    if (isset($data[$bitrix_field])) {
                        $value = $data[$bitrix_field];
                        
                        // Aplicar transformaciones específicas
                        $value = $this->apply_field_transformation($bitrix_field, $value, $direction);
                        
                        $transformed_data[$wc_field] = $value;
                    }
                }
            }
            
            // Aplicar valores por defecto si faltan campos requeridos
            $transformed_data = $this->apply_fallback_values($transformed_data, 'order', $direction);
            
            // Log de transformación
            if ($this->config['log_transformations']) {
                do_action('yeison_btx_data_transformed', 'order', $direction, $data, $transformed_data);
            }
            
            return $transformed_data;
            
        } catch (Exception $e) {
            yeison_btx_log('Error en transformación de datos de pedido', 'error', array(
                'error' => $e->getMessage(),
                'direction' => $direction,
                'original_data' => $data
            ));
            return $data; // Devolver datos originales si hay error
        }
    }
    
    /**
     * Transformar datos de cliente
     */
    public function transform_customer_data($data, $direction, $context = array()) {
        if (!$this->config['auto_transform']) {
            return $data;
        }
        
        try {
            $mapping = $this->custom_mappings['customer_fields'];
            $transformed_data = array();
            
            if ($direction === 'to_bitrix') {
                foreach ($mapping['wc_to_bitrix'] as $wc_field => $bitrix_field) {
                    if (isset($data[$wc_field])) {
                        $value = $data[$wc_field];
                        $value = $this->apply_field_transformation($wc_field, $value, $direction);
                        
                        if (is_array($bitrix_field)) {
                            $transformed_data[$bitrix_field['field']] = $this->format_complex_field($value, $bitrix_field);
                        } else {
                            $transformed_data[$bitrix_field] = $value;
                        }
                    }
                }
            } elseif ($direction === 'from_bitrix') {
                foreach ($mapping['bitrix_to_wc'] as $bitrix_field => $wc_field) {
                    if (isset($data[$bitrix_field])) {
                        $value = $data[$bitrix_field];
                        $value = $this->apply_field_transformation($bitrix_field, $value, $direction);
                        $transformed_data[$wc_field] = $value;
                    }
                }
            }
            
            $transformed_data = $this->add_calculated_fields($transformed_data, $data, 'customer', $direction);
            $transformed_data = $this->apply_fallback_values($transformed_data, 'customer', $direction);
            
            if ($this->config['log_transformations']) {
                do_action('yeison_btx_data_transformed', 'customer', $direction, $data, $transformed_data);
            }
            
            return $transformed_data;
            
        } catch (Exception $e) {
            yeison_btx_log('Error en transformación de datos de cliente', 'error', array(
                'error' => $e->getMessage(),
                'direction' => $direction
            ));
            return $data;
        }
    }
    
    /**
     * Transformar datos de formulario
     */
    public function transform_form_data($data, $direction, $context = array()) {
        if (!$this->config['auto_transform']) {
            return $data;
        }
        
        try {
            $mapping = $this->custom_mappings['form_fields'];
            $transformed_data = array();
            
            // Los formularios generalmente solo van hacia Bitrix24
            if ($direction === 'to_bitrix') {
                foreach ($data as $form_field => $value) {
                    // Buscar mapeo personalizado
                    if (isset($mapping['form_to_bitrix'][$form_field])) {
                        $bitrix_field = $mapping['form_to_bitrix'][$form_field];
                    } else {
                        // Usar mapeo inteligente automático
                        $bitrix_field = $this->intelligent_field_mapping($form_field, 'form_to_bitrix');
                    }
                    
                    if ($bitrix_field) {
                        $value = $this->apply_field_transformation($form_field, $value, $direction);
                        
                        if (is_array($bitrix_field)) {
                            $transformed_data[$bitrix_field['field']] = $this->format_complex_field($value, $bitrix_field);
                        } else {
                            $transformed_data[$bitrix_field] = $value;
                        }
                    }
                }
            }
            
            $transformed_data = $this->add_calculated_fields($transformed_data, $data, 'form', $direction);
            $transformed_data = $this->apply_fallback_values($transformed_data, 'form', $direction);
            
            if ($this->config['log_transformations']) {
                do_action('yeison_btx_data_transformed', 'form', $direction, $data, $transformed_data);
            }
            
            return $transformed_data;
            
        } catch (Exception $e) {
            yeison_btx_log('Error en transformación de datos de formulario', 'error', array(
                'error' => $e->getMessage(),
                'direction' => $direction
            ));
            return $data;
        }
    }
    
    /**
     * Aplicar transformación específica a un campo
     */
    private function apply_field_transformation($field, $value, $direction) {
        // Transformaciones específicas por tipo de campo
        switch ($field) {
            case 'billing_phone':
            case 'phone':
            case 'PHONE':
                return $this->normalize_phone_number($value);
                
            case 'billing_email':
            case 'email':
            case 'EMAIL':
                return $this->normalize_email($value);
                
            case 'total':
            case 'OPPORTUNITY':
                return $this->normalize_currency($value);
                
            case 'status':
            case 'STAGE_ID':
                return $this->map_status($value, $direction);
                
            case 'billing_country':
            case 'shipping_country':
                return $this->normalize_country_code($value);
                
            default:
                return $this->apply_custom_transformation($field, $value, $direction);
        }
    }
    
    /**
     * Normalizar número de teléfono
     */
    private function normalize_phone_number($phone) {
        if (empty($phone)) {
            return $phone;
        }
        
        // Limpiar caracteres no numéricos excepto +
        $phone = preg_replace('/[^\d+]/', '', $phone);
        
        // Si no tiene código de país, agregar código por defecto si está configurado
        $default_country_code = yeison_btx_get_option('default_country_code', '+506');
        if (!empty($phone) && $phone[0] !== '+') {
            $phone = $default_country_code . $phone;
        }
        
        return $phone;
    }
    
    /**
     * Normalizar email
     */
    private function normalize_email($email) {
        if (empty($email)) {
            return $email;
        }
        
        return strtolower(trim($email));
    }
    
    /**
     * Normalizar valor de moneda
     */
    private function normalize_currency($value) {
        if (empty($value)) {
            return 0;
        }
        
        // Remover símbolos de moneda y convertir a float
        $value = preg_replace('/[^\d.,]/', '', $value);
        $value = str_replace(',', '.', $value);
        
        return floatval($value);
    }
    
    /**
     * Mapear estados
     */
    private function map_status($status, $direction) {
        $status_mapping = $this->custom_mappings['status_mapping'];
        
        if ($direction === 'to_bitrix') {
            return $status_mapping['wc_to_bitrix'][$status] ?? $status;
        } elseif ($direction === 'from_bitrix') {
            return $status_mapping['bitrix_to_wc'][$status] ?? $status;
        }
        
        return $status;
    }
    
    /**
     * Normalizar código de país
     */
    private function normalize_country_code($country) {
        // Mapeo de códigos de país comunes
        $country_mapping = array(
            'CR' => 'Costa Rica',
            'US' => 'United States',
            'MX' => 'Mexico',
            'ES' => 'Spain',
            'CO' => 'Colombia'
        );
        
        return $country_mapping[$country] ?? $country;
    }
    
    /**
     * Aplicar transformación personalizada
     */
    private function apply_custom_transformation($field, $value, $direction) {
        $transformation_rules = $this->config['transformation_rules'];
        
        if (isset($transformation_rules[$field])) {
            $rule = $transformation_rules[$field];
            
            switch ($rule['type']) {
                case 'uppercase':
                    return strtoupper($value);
                    
                case 'lowercase':
                    return strtolower($value);
                    
                case 'capitalize':
                    return ucwords($value);
                    
                case 'prefix':
                    return $rule['prefix'] . $value;
                    
                case 'suffix':
                    return $value . $rule['suffix'];
                    
                case 'regex_replace':
                    return preg_replace($rule['pattern'], $rule['replacement'], $value);
                    
                case 'custom_function':
                    if (function_exists($rule['function'])) {
                        return call_user_func($rule['function'], $value);
                    }
                    break;
            }
        }
        
        return $value;
    }
    
    /**
     * Formatear campo complejo (email, phone)
     */
    private function format_complex_field($value, $config) {
        if (empty($value)) {
            return array();
        }
        
        switch ($config['type']) {
            case 'multifield':
                return array(
                    array(
                        'VALUE' => $value,
                        'VALUE_TYPE' => $config['value_type'] ?? 'WORK'
                    )
                );
                
            case 'object':
                return array($config['property'] => $value);
                
            default:
                return $value;
        }
    }
    
    /**
     * Agregar campos calculados
     */
    private function add_calculated_fields($transformed_data, $original_data, $entity_type, $direction) {
        switch ($entity_type) {
            case 'order':
                if ($direction === 'to_bitrix') {
                    // Agregar título calculado para el Deal
                    if (!isset($transformed_data['TITLE']) && isset($original_data['id'])) {
                        $customer_name = ($original_data['billing_first_name'] ?? '') . ' ' . ($original_data['billing_last_name'] ?? '');
                        $transformed_data['TITLE'] = 'Pedido #' . $original_data['id'] . ' - ' . trim($customer_name);
                    }
                    
                    // Agregar fuente
                    $transformed_data['SOURCE_ID'] = 'WEBFORM';
                    $transformed_data['UTM_SOURCE'] = 'woocommerce';
                    $transformed_data['UTM_MEDIUM'] = 'ecommerce';
                    
                    // Agregar fecha si no está presente
                    if (!isset($transformed_data['DATE_CREATE'])) {
                        $transformed_data['DATE_CREATE'] = date('c');
                    }
                }
                break;
                
            case 'customer':
                if ($direction === 'to_bitrix') {
                    // Agregar nombre completo si no está presente
                    if (!isset($transformed_data['FULL_NAME'])) {
                        $first_name = $original_data['first_name'] ?? '';
                        $last_name = $original_data['last_name'] ?? '';
                        $transformed_data['FULL_NAME'] = trim($first_name . ' ' . $last_name);
                    }
                }
                break;
                
            case 'form':
                if ($direction === 'to_bitrix') {
                    // Agregar título para el Lead
                    if (!isset($transformed_data['TITLE'])) {
                        $name = $transformed_data['NAME'] ?? 'Lead';
                        $transformed_data['TITLE'] = 'Formulario: ' . $name . ' - ' . parse_url(home_url(), PHP_URL_HOST);
                    }
                    
                    // Agregar fuente y estado por defecto
                    $transformed_data['SOURCE_ID'] = 'WEB';
                    $transformed_data['STATUS_ID'] = 'NEW';
                    $transformed_data['OPENED'] = 'Y';
                }
                break;
        }
        
        return $transformed_data;
    }
    
    /**
     * Aplicar valores por defecto
     */
    private function apply_fallback_values($data, $entity_type, $direction) {
        $fallbacks = $this->config['fallback_values'][$entity_type] ?? array();
        
        foreach ($fallbacks as $field => $fallback_value) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $data[$field] = $fallback_value;
            }
        }
        
        return $data;
    }
    
    /**
     * Mapeo inteligente automático de campos
     */
    private function intelligent_field_mapping($field_name, $context) {
        $field_lower = strtolower($field_name);
        
        // Patrones comunes para mapeo automático
        $patterns = array(
            // Email patterns
            '/email|correo|mail/' => array('field' => 'EMAIL', 'type' => 'multifield', 'value_type' => 'WORK'),
            
            // Phone patterns  
            '/phone|telefono|tel/' => array('field' => 'PHONE', 'type' => 'multifield', 'value_type' => 'WORK'),
            
            // Name patterns
            '/^(name|nombre)$/' => 'NAME',
            '/first.*name|nombre/' => 'NAME',
            '/last.*name|apellido/' => 'LAST_NAME',
            
            // Message patterns
            '/message|mensaje|comentario|comment/' => 'COMMENTS',
            
            // Company patterns
            '/company|empresa|organizacion/' => 'COMPANY_TITLE',
            
            // Address patterns
            '/address|direccion/' => 'ADDRESS',
            '/city|ciudad/' => 'ADDRESS_CITY',
            '/country|pais/' => 'ADDRESS_COUNTRY'
        );
        
        foreach ($patterns as $pattern => $bitrix_field) {
            if (preg_match($pattern, $field_lower)) {
                return $bitrix_field;
            }
        }
        
        return null;
    }
    
    /**
     * Validar datos antes de sincronización
     */
    public function validate_data_before_sync($is_valid, $data, $entity_type, $direction) {
        if (!$this->config['validate_data']) {
            return $is_valid;
        }
        
        $validation_errors = array();
        
        // Validaciones específicas por tipo de entidad
        switch ($entity_type) {
            case 'order':
                if ($direction === 'to_bitrix') {
                    if (empty($data['TITLE'])) {
                        $validation_errors[] = 'Título del Deal es requerido';
                    }
                    if (empty($data['OPPORTUNITY']) || !is_numeric($data['OPPORTUNITY'])) {
                        $validation_errors[] = 'Monto del Deal debe ser numérico';
                    }
                }
                break;
                
            case 'customer':
                if (isset($data['EMAIL']) && !empty($data['EMAIL'])) {
                    $email = is_array($data['EMAIL']) ? $data['EMAIL'][0]['VALUE'] : $data['EMAIL'];
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $validation_errors[] = 'Email no tiene formato válido';
                    }
                }
                break;
                
            case 'form':
                if ($direction === 'to_bitrix') {
                    if (empty($data['TITLE'])) {
                        $validation_errors[] = 'Título del Lead es requerido';
                    }
                }
                break;
        }
        
        if (!empty($validation_errors)) {
            yeison_btx_log('Errores de validación en sincronización', 'warning', array(
                'entity_type' => $entity_type,
                'direction' => $direction,
                'errors' => $validation_errors,
                'data' => $data
            ));
            return false;
        }
        
        return $is_valid;
    }
    
    /**
     * Log de transformación de datos
     */
    public function log_data_transformation($entity_type, $direction, $original_data, $transformed_data) {
        $changes = array();
        
        // Detectar cambios principales
        foreach ($transformed_data as $field => $value) {
            if (!isset($original_data[$field]) || $original_data[$field] !== $value) {
                $changes[] = $field;
            }
        }
        
        yeison_btx_log('Datos transformados para sincronización', 'info', array(
            'entity_type' => $entity_type,
            'direction' => $direction,
            'fields_transformed' => count($changes),
            'changed_fields' => $changes
        ));
    }
    
    /**
     * Obtener mapeo por defecto de pedidos
     */
    private function get_default_order_mapping() {
        return array(
            'wc_to_bitrix' => array(
                'id' => 'ORDER_ID',
                'total' => 'OPPORTUNITY',
                'currency' => 'CURRENCY_ID',
                'status' => 'STAGE_ID',
                'billing_first_name' => 'CONTACT_NAME',
                'billing_last_name' => 'CONTACT_LAST_NAME',
                'billing_email' => array('field' => 'CONTACT_EMAIL', 'type' => 'multifield', 'value_type' => 'WORK'),
                'billing_phone' => array('field' => 'CONTACT_PHONE', 'type' => 'multifield', 'value_type' => 'WORK'),
                'payment_method' => 'PAYMENT_METHOD'
            ),
            'bitrix_to_wc' => array(
                'STAGE_ID' => 'status',
                'OPPORTUNITY' => 'total',
                'CURRENCY_ID' => 'currency'
            )
        );
    }
    
    /**
     * Obtener mapeo por defecto de clientes
     */
    private function get_default_customer_mapping() {
        return array(
            'wc_to_bitrix' => array(
                'first_name' => 'NAME',
                'last_name' => 'LAST_NAME',
                'email' => array('field' => 'EMAIL', 'type' => 'multifield', 'value_type' => 'WORK'),
                'billing_phone' => array('field' => 'PHONE', 'type' => 'multifield', 'value_type' => 'WORK'),
                'billing_company' => 'COMPANY_TITLE',
                'billing_address_1' => 'ADDRESS',
                'billing_city' => 'ADDRESS_CITY',
                'billing_country' => 'ADDRESS_COUNTRY'
            ),
            'bitrix_to_wc' => array(
                'NAME' => 'first_name',
                'LAST_NAME' => 'last_name',
                'EMAIL' => 'email',
                'PHONE' => 'billing_phone'
            )
        );
    }
    
    /**
     * Obtener mapeo por defecto de productos
     */
    private function get_default_product_mapping() {
        return array(
            'wc_to_bitrix' => array(
                'name' => 'NAME',
                'price' => 'PRICE',
                'sku' => 'ARTICLE',
                'description' => 'DESCRIPTION',
                'stock_quantity' => 'QUANTITY'
            ),
            'bitrix_to_wc' => array(
                'NAME' => 'name',
                'PRICE' => 'price',
                'ARTICLE' => 'sku'
            )
        );
    }
    
    /**
     * Obtener mapeo por defecto de formularios
     */
    private function get_default_form_mapping() {
        return array(
            'form_to_bitrix' => array(
                'name' => 'NAME',
                'first_name' => 'NAME',
                'last_name' => 'LAST_NAME',
                'email' => array('field' => 'EMAIL', 'type' => 'multifield', 'value_type' => 'WORK'),
                'phone' => array('field' => 'PHONE', 'type' => 'multifield', 'value_type' => 'WORK'),
                'message' => 'COMMENTS',
                'subject' => 'TITLE',
                'company' => 'COMPANY_TITLE'
            )
        );
    }
    
    /**
     * Obtener mapeo por defecto de estados
     */
    
    private function get_default_status_mapping() {
        return array(
            'wc_to_bitrix' => array(
                'pending' => 'NEW',
                'processing' => 'EXECUTING',
                'on-hold' => 'PREPAYMENT_INVOICE', 
                'completed' => 'WON',
                'cancelled' => 'LOSE',
                'refunded' => 'LOSE',
                'failed' => 'LOSE'
            ),
            'bitrix_to_wc' => array(
                'NEW' => 'pending',
                'PREPARATION' => 'processing',    // Para compatibilidad
                'EXECUTING' => 'processing',      // Mapeo principal
                'PREPAYMENT_INVOICE' => 'on-hold',
                'WON' => 'completed',
                'LOSE' => 'cancelled',
                'APOLOGY' => 'cancelled'
            )
        );
    }






    
    /**
     * Obtener estadísticas de mapeo
     */
    public function get_mapping_stats() {
        global $wpdb;
        
        return array(
            'enabled' => $this->config['enabled'],
            'auto_transform' => $this->config['auto_transform'],
            'validate_data' => $this->config['validate_data'],
            'transformations_logged' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}yeison_btx_logs 
                WHERE message LIKE %s AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                '%transformados%'
            )),
            'validation_errors' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}yeison_btx_logs 
                WHERE message LIKE %s AND type = 'warning' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                '%validación%'
            )),
            'custom_mappings_count' => count($this->custom_mappings),
            'transformation_rules_count' => count($this->config['transformation_rules'])
        );
    }
    
    /**
     * Obtener todos los mapeos configurados
     */
    public function get_all_mappings() {
        return $this->custom_mappings;
    }
    
    /**
     * Actualizar mapeo personalizado
     */
    public function update_custom_mapping($mapping_type, $mapping_data) {
        $this->custom_mappings[$mapping_type] = $mapping_data;
        yeison_btx_update_option('custom_' . $mapping_type . '_mapping', $mapping_data);
        
        yeison_btx_log('Mapeo personalizado actualizado', 'info', array(
            'mapping_type' => $mapping_type,
            'fields_count' => count($mapping_data)
        ));
        
        return true;
    }
    
}

/**
 * Función global para obtener instancia
 */
function yeison_btx_data_mapping() {
    return YeisonBTX_Data_Mapping::get_instance();
}