<?php
/**
 * Sistema SIMPLIFICADO de Prevención de Loops
 * 
 * @package YeisonBTX
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class YeisonBTX_Anti_Loop {
    
    /**
     * Instancia única (Singleton)
     */
    private static $instance = null;
    
    /**
     * Configuración del sistema anti-loop SIMPLE
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
     * Cargar configuración SIMPLIFICADA
     */
    private function load_config() {
        $this->config = array(
            'enabled' => yeison_btx_get_option('anti_loop_enabled', true),
            // ✅ CONFIGURACIÓN MÁS PERMISIVA
            'max_updates_per_minute' => 20,  // Aumentado de 5 a 20
            'max_updates_per_hour' => 100,   // Aumentado de 20 a 100
            'simple_timeout' => 30,          // Solo 30 segundos de timeout simple
            'use_db_comparison' => true,     // Usar comparación de timestamps de BD
            // ❌ DESHABILITAMOS características complejas
            'pattern_detection_enabled' => false,  // YA NO detectar patrones complejos
            'bounce_detection_enabled' => false,   // YA NO detectar rebotes
            'concurrent_check' => false             // YA NO verificar concurrencia compleja
        );
    }
    
    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        if (!$this->config['enabled']) {
            return;
        }
        
        // ✅ Hook SIMPLE antes de sincronización
        add_filter('yeison_btx_before_sync', array($this, 'simple_check_before_sync'), 10, 4);
        
        // ✅ Hook después de sincronización para actualizar timestamps
        add_action('yeison_btx_after_sync', array($this, 'update_sync_timestamp'), 10, 4);
    }
    
    /**
     * ✅ VERIFICACIÓN SIMPLE - Solo verificar límites básicos y timestamps
     */
    public function simple_check_before_sync($allow_sync, $entity_type, $entity_id, $source) {
        if (!$this->config['enabled']) {
            return $allow_sync;
        }
        
        try {
            // 1. Verificar límites básicos de frecuencia (MÁS PERMISIVOS)
            if (!$this->check_simple_frequency_limits($entity_type, $entity_id)) {
                yeison_btx_log('⚠️ Límite de frecuencia superado (SIMPLE)', 'warning', array(
                    'entity_type' => $entity_type,
                    'entity_id' => $entity_id,
                    'source' => $source,
                    'reason' => 'frequency_limit'
                ));
                return false;
            }
            
            // 2. Verificar timeout simple (evitar múltiples actualizaciones simultáneas)
            if (!$this->check_simple_timeout($entity_type, $entity_id, $source)) {
                yeison_btx_log('⚠️ Timeout simple activo', 'info', array(
                    'entity_type' => $entity_type,
                    'entity_id' => $entity_id,
                    'source' => $source,
                    'timeout_seconds' => $this->config['simple_timeout']
                ));
                return false;
            }
            
            // 3. ✅ COMPARACIÓN DE TIMESTAMPS DE BASE DE DATOS
            if ($this->config['use_db_comparison'] && !$this->should_sync_by_timestamp($entity_type, $entity_id, $source)) {
                yeison_btx_log('ℹ️ No sincronizar - timestamps indican que no hay cambios', 'info', array(
                    'entity_type' => $entity_type,
                    'entity_id' => $entity_id,
                    'source' => $source
                ));
                return false;
            }
            
            // ✅ TODO OK - Permitir sincronización
            yeison_btx_log('✅ Anti-loop: Permitiendo sincronización', 'success', array(
                'entity_type' => $entity_type,
                'entity_id' => $entity_id,
                'source' => $source,
                'system' => 'simple'
            ));
            
            return $allow_sync;
            
        } catch (Exception $e) {
            yeison_btx_log('❌ Error en anti-loop simple', 'error', array(
                'error' => $e->getMessage(),
                'entity_type' => $entity_type,
                'entity_id' => $entity_id,
                'source' => $source
            ));
            
            // ✅ En caso de error, SER PERMISIVO y permitir sync
            return true;
        }
    }
    
    /**
     * ✅ Verificar límites de frecuencia SIMPLES y PERMISIVOS
     */
    private function check_simple_frequency_limits($entity_type, $entity_id) {
        $key = "yeison_btx_simple_freq_{$entity_type}_{$entity_id}";
        
        // Verificar cuántas veces se ha actualizado en la última hora
        $hourly_count = get_transient($key . '_hourly') ?: 0;
        
        if ($hourly_count >= $this->config['max_updates_per_hour']) {
            yeison_btx_log('🔴 Límite horario superado', 'warning', array(
                'hourly_count' => $hourly_count,
                'max_allowed' => $this->config['max_updates_per_hour'],
                'entity_type' => $entity_type,
                'entity_id' => $entity_id
            ));
            return false;
        }
        
        // Verificar cuántas veces se ha actualizado en el último minuto
        $minute_count = get_transient($key . '_minute') ?: 0;
        
        if ($minute_count >= $this->config['max_updates_per_minute']) {
            yeison_btx_log('🔴 Límite por minuto superado', 'warning', array(
                'minute_count' => $minute_count,
                'max_allowed' => $this->config['max_updates_per_minute'],
                'entity_type' => $entity_type,
                'entity_id' => $entity_id
            ));
            return false;
        }
        
        // ✅ Incrementar contadores
        set_transient($key . '_hourly', $hourly_count + 1, 3600); // 1 hora
        set_transient($key . '_minute', $minute_count + 1, 60);   // 1 minuto
        
        return true;
    }
    
    /**
     * ✅ Verificar timeout simple entre actualizaciones
     */
    private function check_simple_timeout($entity_type, $entity_id, $source) {
        $lock_key = "yeison_btx_simple_lock_{$entity_type}_{$entity_id}";
        $existing_lock = get_transient($lock_key);
        
        if ($existing_lock) {
            $lock_data = json_decode($existing_lock, true);
            
            // Si el lock es del MISMO origen, permitir (no es loop)
            if ($lock_data['source'] === $source) {
                yeison_btx_log('✅ Mismo origen, permitiendo', 'info', array(
                    'lock_source' => $lock_data['source'],
                    'current_source' => $source
                ));
                return true;
            }
            
            // Si el lock es reciente (menos de timeout), bloquear
            if ((time() - $lock_data['timestamp']) < $this->config['simple_timeout']) {
                return false;
            }
        }
        
        // ✅ Establecer nuevo lock simple
        $lock_data = wp_json_encode(array(
            'source' => $source,
            'timestamp' => time(),
            'entity_type' => $entity_type,
            'entity_id' => $entity_id
        ));
        
        set_transient($lock_key, $lock_data, $this->config['simple_timeout']);
        
        return true;
    }
    
    /* 
    Funcion para tener un anti loops mas sewuro, no repite mismo ip 
    private function should_sync_by_timestamp($entity_type, $entity_id, $source) {
        global $wpdb;
        
        // Buscar registro de sincronización existente
        $sync_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}yeison_btx_sync 
            WHERE entity_type = %s AND local_id = %s",
            $entity_type, $entity_id
        ));
        
        if (!$sync_record) {
            // Si no hay registro, es nueva sincronización - permitir
            yeison_btx_log('✅ Nueva entidad, permitir sincronización', 'info', array(
                'entity_type' => $entity_type,
                'entity_id' => $entity_id,
                'source' => $source
            ));
            return true;
        }
        
        // Obtener datos de sincronización
        $sync_data = json_decode($sync_record->sync_data, true) ?: array();
        
        // Verificar timestamp de la última sincronización
        $last_sync_time = strtotime($sync_record->last_sync);
        $now = time();
        
        // Si han pasado más de 10 segundos desde la última sync, permitir
        if (($now - $last_sync_time) > 3) {
            yeison_btx_log('✅ Ha pasado suficiente tiempo, permitir sync', 'info', array(
                'entity_type' => $entity_type,
                'entity_id' => $entity_id,
                'seconds_since_last' => $now - $last_sync_time,
                'last_direction' => $sync_data['last_direction'] ?? 'unknown',
                'current_source' => $source
            ));
            return true;
        }
        



        // Si la dirección cambió (ej: era de WC, ahora es de Bitrix24), permitir
        $last_direction = $sync_data['last_direction'] ?? '';
        $current_direction = $source === 'bitrix24' ? 'from_bitrix24' : 'to_bitrix24';

        if ($last_direction !== $current_direction) {
            yeison_btx_log('✅ Dirección cambió, permitir sync', 'info', array(
                'entity_type' => $entity_type,
                'entity_id' => $entity_id,
                'last_direction' => $last_direction,
                'current_direction' => $current_direction
            ));
            return true;
        }

        // ✅ NUEVA LÓGICA: Si es cambio bidireccional manual, ser más permisivo
        if (($now - $last_sync_time) < 60) { // Menos de 1 minuto
            // Permitir cambios bidireccionales si han pasado al menos 3 segundos
            if (($now - $last_sync_time) >= 3) {
                yeison_btx_log('✅ Cambio bidireccional reciente pero permitido', 'info', array(
                    'entity_type' => $entity_type,
                    'entity_id' => $entity_id,
                    'seconds_since_last' => $now - $last_sync_time,
                    'direction' => $current_direction
                ));
                return true;
            }
        }

        // Si es muy reciente Y misma dirección, bloquear para evitar loop real
        yeison_btx_log('⚠️ Muy reciente y misma dirección, bloquear loop real', 'warning', array(
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'seconds_since_last' => $now - $last_sync_time,
            'direction' => $current_direction
        ));
        return false;



    }
    */

    
    private function should_sync_by_timestamp($entity_type, $entity_id, $source) {
        // ✅ SISTEMA SIMPLIFICADO - Solo logging básico, sin restricciones
        yeison_btx_log('✅ Verificación timestamp simplificada - SIEMPRE PERMITIR', 'info', array(
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'source' => $source,
            'restriction' => 'DISABLED'
        ));
        
        // ✅ SIEMPRE PERMITIR - Sin verificación de timestamps
        return true;
    }
















    
    
    /**
     * ✅ Actualizar timestamp después de sincronización exitosa
     */
    public function update_sync_timestamp($entity_type, $entity_id, $source, $success) {
        if (!$success) {
            return; // Solo actualizar si la sync fue exitosa
        }
        
        global $wpdb;
        
        $direction = $source === 'bitrix24' ? 'from_bitrix24' : 'to_bitrix24';
        
        // Buscar registro existente
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}yeison_btx_sync 
            WHERE entity_type = %s AND local_id = %s",
            $entity_type, $entity_id
        ));
        
        $sync_data = array(
            'last_direction' => $direction,
            'last_source' => $source,
            'last_sync_timestamp' => time(),
            'updated_at' => current_time('mysql')
        );
        
        if ($existing) {
            // Actualizar registro existente
            $current_data = json_decode($existing->sync_data, true) ?: array();
            $merged_data = array_merge($current_data, $sync_data);
            
            $wpdb->update(
                $wpdb->prefix . 'yeison_btx_sync',
                array(
                    'last_sync' => current_time('mysql'),
                    'sync_data' => wp_json_encode($merged_data)
                ),
                array('id' => $existing->id),
                array('%s', '%s'),
                array('%d')
            );
        }
        
        yeison_btx_log('📝 Timestamp de sincronización actualizado', 'info', array(
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'direction' => $direction,
            'source' => $source
        ));
    }
    
    /**
     * ✅ Obtener estadísticas SIMPLES del sistema anti-loop
     */
    public function get_simple_anti_loop_stats() {
        global $wpdb;
        
        return array(
            'enabled' => $this->config['enabled'],
            'system_type' => 'SIMPLE',
            'max_updates_per_minute' => $this->config['max_updates_per_minute'],
            'max_updates_per_hour' => $this->config['max_updates_per_hour'],
            'simple_timeout_seconds' => $this->config['simple_timeout'],
            'pattern_detection' => false,  // Deshabilitado
            'bounce_detection' => false,   // Deshabilitado
            'blocked_syncs_24h' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}yeison_btx_logs 
                WHERE message LIKE %s 
                AND type = 'warning' 
                AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                '%limite%'
            )),
            'successful_syncs_24h' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}yeison_btx_logs 
                WHERE message LIKE %s 
                AND type = 'success' 
                AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                '%permitiendo sincronización%'
            ))
        );
    }
    
    /**
     * ✅ Limpiar locks y contadores expirados
     */
    public function cleanup_simple_locks() {
        global $wpdb;
        
        // Limpiar transients expirados
        $expired_transients = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} 
            WHERE option_name LIKE %s 
            AND option_value < %d",
            '_transient_timeout_yeison_btx_simple_%',
            time()
        ));
        
        $cleaned = 0;
        foreach ($expired_transients as $transient) {
            $transient_name = str_replace('_transient_timeout_', '', $transient->option_name);
            delete_transient($transient_name);
            $cleaned++;
        }
        
        if ($cleaned > 0) {
            yeison_btx_log('🧹 Locks simples limpiados', 'info', array(
                'cleaned_count' => $cleaned
            ));
        }
        
        return $cleaned;
    }
}

/**
 * Función global para obtener instancia
 */
function yeison_btx_anti_loop() {
    return YeisonBTX_Anti_Loop::get_instance();
}

/**
 * ✅ Hook para limpiar automáticamente
 */
add_action('yeison_btx_cleanup_patterns', function() {
    $anti_loop = yeison_btx_anti_loop();
    $anti_loop->cleanup_simple_locks();
});