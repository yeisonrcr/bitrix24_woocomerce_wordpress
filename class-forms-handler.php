<?php
/**
 * Manejador de formularios universal - VERSIÓN CON DEBUGGING MEJORADO
 * 
 * @package YeisonBTX
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class YeisonBTX_Forms_Handler {
    
    /**
     * Instancia única (Singleton)
     */
    private static $instance = null;
    
    /**
     * Configuración del handler
     */
    private $config = array();
    
    /**
     * Constructor privado
     */
    private function __construct() {
        $this->load_config();
        $this->init_hooks();
        
        // 🚀 LOG INICIAL PARA DEBUGGING
        yeison_btx_log('🚀 Forms Handler inicializado', 'info', array(
            'config_enabled' => $this->config['enabled'],
            'auto_process' => $this->config['auto_process']
        ));
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
            'enabled' => yeison_btx_get_option('forms_capture_enabled', true),
            'auto_process' => yeison_btx_get_option('forms_auto_process', true),
            'allowed_origins' => yeison_btx_get_option('forms_allowed_origins', array()),
            'excluded_fields' => array('password', 'pass', 'pwd', '_token', '_nonce'),
            'honeypot_fields' => array('website', 'url', 'homepage')
        );
        
        // 🔧 LOG DE CONFIGURACIÓN
        yeison_btx_log('⚙️ Configuración Forms Handler cargada', 'debug', $this->config);
    }
    
    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        // 🔧 LOG DE HOOKS
        yeison_btx_log('🔗 Inicializando hooks Forms Handler', 'debug');
        
        // Endpoint REST API
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Endpoint clásico (por si REST API falla)
        add_action('wp_ajax_nopriv_yeison_btx_form', array($this, 'handle_form_ajax'));
        add_action('wp_ajax_yeison_btx_form', array($this, 'handle_form_ajax'));
        
        // Procesar cola automáticamente
        add_action('yeison_btx_process_queue', array($this, 'process_queue'));
        
        // Shortcode para formulario de prueba
        add_shortcode('yeison_btx_test_form', array($this, 'test_form_shortcode'));
        
        // 🔧 NUEVO: Hook para interceptar TODOS los envíos de formularios
        add_action('init', array($this, 'setup_universal_form_capture'), 1);
        
        yeison_btx_log('✅ Hooks Forms Handler registrados', 'success');
    }
    
    /**
     * 🆕 NUEVO: Configurar captura universal de formularios
     */
    public function setup_universal_form_capture() {
        if (!$this->config['enabled']) {
            yeison_btx_log('❌ Captura universal deshabilitada en config', 'warning');
            return;
        }
        
        // 🔧 Agregar JavaScript para captura universal
        add_action('wp_footer', array($this, 'inject_universal_capture_script'));
        add_action('admin_footer', array($this, 'inject_universal_capture_script'));
        
        yeison_btx_log('🌐 Captura universal de formularios configurada', 'info');
    }
    
    /**
     * 🆕 NUEVO: Inyectar script de captura universal
     */
    public function inject_universal_capture_script() {
        // Solo en páginas que no sean de login
        if (is_login()) {
            return;
        }
        
        ?>
        <script type="text/javascript">
        (function() {
            console.log('🚀 Yeison BTX: Script de captura universal cargado');
            
            // 🔧 Función para capturar envío de formularios
            function captureFormSubmission(event) {
                const form = event.target;
                
                // Skip si es el formulario de login o admin
                if (form.id && (form.id.includes('login') || form.id.includes('admin'))) {
                    console.log('⏭️ Yeison BTX: Saltando formulario de login/admin:', form.id);
                    return;
                }
                
                console.log('📝 Yeison BTX: Formulario detectado:', form);
                
                // Recoger datos del formulario
                const formData = new FormData(form);
                const data = {};
                
                // Convertir FormData a objeto
                for (let [key, value] of formData.entries()) {
                    data[key] = value;
                }
                
                // Agregar metadatos
                data._yeison_meta = {
                    form_id: form.id || 'no-id',
                    form_action: form.action || window.location.href,
                    form_method: form.method || 'post',
                    page_url: window.location.href,
                    page_title: document.title,
                    timestamp: new Date().toISOString(),
                    user_agent: navigator.userAgent
                };
                
                console.log('📋 Yeison BTX: Datos capturados:', data);
                
                // Enviar a nuestro endpoint
                fetch('<?php echo rest_url('yeison-btx/v1/form'); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                    },
                    body: JSON.stringify({form_data: data})
                })
                .then(response => response.json())
                .then(result => {
                    console.log('✅ Yeison BTX: Formulario enviado exitosamente:', result);
                })
                .catch(error => {
                    console.error('❌ Yeison BTX: Error enviando formulario:', error);
                });
            }
            
            // 🔧 Interceptar TODOS los envíos de formularios
            document.addEventListener('submit', captureFormSubmission, true);
            
            // 🔧 También escuchar eventos de formularios AJAX
            const originalFetch = window.fetch;
            window.fetch = function(...args) {
                console.log('🌐 Yeison BTX: Fetch intercepted:', args[0]);
                return originalFetch.apply(this, args);
            };
            
            console.log('🎯 Yeison BTX: Listeners de captura configurados');
        })();
        </script>
        <?php
    }
    
    /**
     * Registrar rutas REST API - CON DEBUGGING MEJORADO
     */
    public function register_rest_routes() {
        yeison_btx_log('📡 Registrando rutas REST API Forms Handler', 'debug');
        
        $form_route_registered = register_rest_route('yeison-btx/v1', '/form', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_form_rest'),
            'permission_callback' => '__return_true', // Permitir sin autenticación
            'args' => array(
                'form_data' => array(
                    'required' => true,
                    'type' => 'object'
                )
            )
        ));
        
        $status_route_registered = register_rest_route('yeison-btx/v1', '/status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_status'),
            'permission_callback' => '__return_true'
        ));
        
        yeison_btx_log('📡 Rutas REST API registradas', 'info', array(
            'form_route' => $form_route_registered,
            'status_route' => $status_route_registered,
            'form_endpoint' => rest_url('yeison-btx/v1/form'),
            'status_endpoint' => rest_url('yeison-btx/v1/status')
        ));
    }
    
    /**
     * Manejar formulario via REST API - CON DEBUGGING MEJORADO
     */
    public function handle_form_rest($request) {
        // 🔧 LOG DETALLADO DE LA REQUEST
        yeison_btx_log('📥 REST API: Formulario recibido', 'info', array(
            'method' => $request->get_method(),
            'content_type' => $request->get_header('content-type'),
            'user_agent' => $request->get_header('user-agent'),
            'origin' => $request->get_header('origin'),
            'referer' => $request->get_header('referer'),
            'body_size' => strlen($request->get_body())
        ));
        
        $form_data = $request->get_param('form_data');
        $origin = $request->get_header('origin') ?: $request->get_header('referer');
        
        // 🔧 LOG DE DATOS RECIBIDOS
        yeison_btx_log('📋 REST API: Datos del formulario', 'debug', array(
            'form_data_keys' => array_keys($form_data ?: array()),
            'form_data_count' => count($form_data ?: array()),
            'origin' => $origin,
            'has_yeison_meta' => isset($form_data['_yeison_meta'])
        ));
        
        if (empty($form_data)) {
            yeison_btx_log('❌ REST API: No hay datos en form_data', 'error');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'No hay datos de formulario',
                'debug' => array(
                    'all_params' => $request->get_params(),
                    'body' => $request->get_body()
                )
            ), 400);
        }
        
        $result = $this->process_form_submission($form_data, 'rest_api', $origin);
        
        if ($result['success']) {
            return new WP_REST_Response($result, 200);
        } else {
            return new WP_REST_Response($result, 400);
        }
    }
    
    /**
     * Procesar envío de formulario - CON DEBUGGING MEJORADO
     *//* 
    public function process_form_submission($form_data, $source = 'unknown', $origin = '') {
        $result = array(
            'success' => false,
            'message' => '',
            'data' => array()
        );
        
        try {
            // 🔧 LOG DEL INICIO DEL PROCESAMIENTO
            yeison_btx_log('🔄 Iniciando procesamiento de formulario', 'info', array(
                'source' => $source,
                'origin' => $origin,
                'fields_count' => count($form_data),
                'field_keys' => array_keys($form_data),
                'config_enabled' => $this->config['enabled']
            ));
            
            // Verificar si está habilitado
            if (!$this->config['enabled']) {
                $result['message'] = 'Captura de formularios deshabilitada';
                yeison_btx_log('❌ Captura deshabilitada en configuración', 'warning');
                return $result;
            }
            
            // Detectar spam/honeypot
            if ($this->is_spam($form_data)) {
                yeison_btx_log('🛡️ Formulario marcado como spam', 'warning', array(
                    'origin' => $origin,
                    'reason' => 'honeypot_triggered'
                ));
                
                // Responder como si fuera exitoso para no revelar detección
                $result['success'] = true;
                $result['message'] = 'Formulario procesado correctamente';
                return $result;
            }
            
            // Sanitizar datos
            $clean_data = $this->sanitize_form_data($form_data);
            
            yeison_btx_log('🧹 Datos sanitizados', 'debug', array(
                'original_count' => count($form_data),
                'clean_count' => count($clean_data),
                'clean_keys' => array_keys($clean_data)
            ));
            
            // Determinar tipo de formulario
            $form_type = $this->detect_form_type($clean_data, $origin);
            
            yeison_btx_log('🔍 Tipo de formulario detectado', 'info', array(
                'form_type' => $form_type,
                'origin' => $origin
            ));
            
            // Agregar metadatos
            $clean_data['_meta'] = array(
                'source' => $source,
                'origin' => $origin,
                'ip' => yeison_btx_get_ip(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'timestamp' => current_time('mysql'),
                'form_type' => $form_type
            );
            
            // Agregar a la cola
            $queue_id = yeison_btx_add_to_queue($form_type, $clean_data);
            
            if ($queue_id) {
                yeison_btx_log('✅ Formulario agregado a la cola', 'success', array(
                    'queue_id' => $queue_id,
                    'form_type' => $form_type
                ));
                
                $result['success'] = true;
                $result['message'] = 'Formulario recibido y procesado';
                $result['data'] = array(
                    'queue_id' => $queue_id,
                    'form_type' => $form_type
                );
                
                // Procesar inmediatamente si está configurado
                if ($this->config['auto_process']) {
                    yeison_btx_log('⚡ Procesando inmediatamente', 'info', array('queue_id' => $queue_id));
                    $this->process_single_queue_item($queue_id);
                }
                
            } else {
                $result['message'] = 'Error al procesar formulario';
                yeison_btx_log('❌ Error agregando formulario a la cola', 'error');
            }
            
        } catch (Exception $e) {
            yeison_btx_log('💥 Error crítico procesando formulario', 'error', array(
                'error' => $e->getMessage(),
                'origin' => $origin,
                'trace' => $e->getTraceAsString()
            ));
            
            $result['message'] = 'Error interno del servidor';
        }
        
        return $result;
    }
     */


    /**
     * ACTUALIZACIÓN ESPECÍFICA para class-forms-handler.php
     * 
     */

    /**
     * Procesar envío de formulario - CON FIX DE TIMING
     */
    public function process_form_submission($form_data, $source = 'unknown', $origin = '') {
        $result = array(
            'success' => false,
            'message' => '',
            'data' => array()
        );
        
        try {
            // 🔧 LOG DEL INICIO DEL PROCESAMIENTO
            yeison_btx_log('🔄 Iniciando procesamiento de formulario', 'info', array(
                'source' => $source,
                'origin' => $origin,
                'fields_count' => count($form_data),
                'field_keys' => array_keys($form_data),
                'config_enabled' => $this->config['enabled']
            ));
            
            // Verificar si está habilitado
            if (!$this->config['enabled']) {
                $result['message'] = 'Captura de formularios deshabilitada';
                yeison_btx_log('❌ Captura deshabilitada en configuración', 'warning');
                return $result;
            }
            
            // Detectar spam/honeypot
            if ($this->is_spam($form_data)) {
                yeison_btx_log('🛡️ Formulario marcado como spam', 'warning', array(
                    'origin' => $origin,
                    'reason' => 'honeypot_triggered'
                ));
                
                // Responder como si fuera exitoso para no revelar detección
                $result['success'] = true;
                $result['message'] = 'Formulario procesado correctamente';
                return $result;
            }
            
            // Sanitizar datos
            $clean_data = $this->sanitize_form_data($form_data);
            
            yeison_btx_log('🧹 Datos sanitizados', 'debug', array(
                'original_count' => count($form_data),
                'clean_count' => count($clean_data),
                'clean_keys' => array_keys($clean_data)
            ));
            
            // Determinar tipo de formulario
            $form_type = $this->detect_form_type($clean_data, $origin);
            
            yeison_btx_log('🔍 Tipo de formulario detectado', 'info', array(
                'form_type' => $form_type,
                'origin' => $origin
            ));
            
            // Agregar metadatos
            $clean_data['_meta'] = array(
                'source' => $source,
                'origin' => $origin,
                'ip' => yeison_btx_get_ip(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'timestamp' => current_time('mysql'),
                'form_type' => $form_type
            );
            
            // Agregar a la cola
            $queue_id = yeison_btx_add_to_queue($form_type, $clean_data);
            
            if ($queue_id) {
                yeison_btx_log('✅ Formulario agregado a la cola', 'success', array(
                    'queue_id' => $queue_id,
                    'form_type' => $form_type
                ));
                
                $result['success'] = true;
                $result['message'] = 'Formulario recibido y procesado';
                $result['data'] = array(
                    'queue_id' => $queue_id,
                    'form_type' => $form_type
                );
                
                // 🔧 FIX: Procesar con delay para evitar problemas de timing
                if ($this->config['auto_process']) {
                    yeison_btx_log('⚡ Programando procesamiento inmediato', 'info', array('queue_id' => $queue_id));
                    
                    // Opción 1: Procesar directamente (método mejorado)
                    $this->process_single_queue_item_with_retry($queue_id);
                    
                    // Opción 2: Programar para 2 segundos después (backup)
                    wp_schedule_single_event(time() + 2, 'yeison_btx_process_delayed_queue', array($queue_id));
                }
                
            } else {
                $result['message'] = 'Error al procesar formulario';
                yeison_btx_log('❌ Error agregando formulario a la cola', 'error');
            }
            
        } catch (Exception $e) {
            yeison_btx_log('💥 Error crítico procesando formulario', 'error', array(
                'error' => $e->getMessage(),
                'origin' => $origin,
                'trace' => $e->getTraceAsString()
            ));
            
            $result['message'] = 'Error interno del servidor';
        }
        
        return $result;
    }














    /**
     * Procesar un elemento específico de la cola - CON DEBUGGING MEJORADO
     *//* 
    public function process_single_queue_item($queue_id) {
        global $wpdb;
        
        yeison_btx_log('🔄 Procesando elemento de cola', 'info', array('queue_id' => $queue_id));
        
        $queue_item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}yeison_btx_queue WHERE id = %d AND status = 'pending'",
            $queue_id
        ));
        
        if (!$queue_item) {
            yeison_btx_log('❌ Elemento de cola no encontrado', 'error', array('queue_id' => $queue_id));
            return false;
        }
        
        $form_data = json_decode($queue_item->form_data, true);
        
        yeison_btx_log('📋 Datos de cola obtenidos', 'debug', array(
            'queue_id' => $queue_id,
            'form_type' => $queue_item->form_type,
            'data_keys' => array_keys($form_data)
        ));
        
        $api = yeison_btx_api();
        
        if (!$api->is_authorized()) {
            yeison_btx_log('❌ API de Bitrix24 no autorizada', 'error');
            return false;
        }
        
        // Mapear a lead de Bitrix24
        $lead_data = yeison_btx_map_form_to_lead($form_data);
        
        yeison_btx_log('🗺️ Datos mapeados para Lead', 'debug', array(
            'lead_data_keys' => array_keys($lead_data),
            'lead_title' => $lead_data['TITLE'] ?? 'Sin título'
        ));
        
        // Intentar crear lead
        $lead_id = $api->create_lead($lead_data);
        
        if ($lead_id) {
            // Marcar como procesado
            $wpdb->update(
                $wpdb->prefix . 'yeison_btx_queue',
                array(
                    'status' => 'processed',
                    'processed_at' => current_time('mysql')
                ),
                array('id' => $queue_id),
                array('%s', '%s'),
                array('%d')
            );
            
            // Crear registro de sincronización
            $wpdb->insert(
                $wpdb->prefix . 'yeison_btx_sync',
                array(
                    'entity_type' => 'form_lead',
                    'local_id' => $queue_id,
                    'remote_id' => $lead_id,
                    'sync_status' => 'synced',
                    'sync_data' => wp_json_encode(array(
                        'form_type' => $queue_item->form_type,
                        'lead_title' => $lead_data['TITLE']
                    ))
                ),
                array('%s', '%s', '%s', '%s', '%s')
            );
            
            yeison_btx_log('🎉 Lead creado exitosamente en Bitrix24', 'success', array(
                'queue_id' => $queue_id,
                'lead_id' => $lead_id,
                'form_type' => $queue_item->form_type,
                'lead_title' => $lead_data['TITLE']
            ));
            
            return true;
        } else {
            // Marcar como error e incrementar intentos
            $attempts = intval($queue_item->attempts) + 1;
            $status = $attempts >= 3 ? 'failed' : 'pending';
            
            $wpdb->update(
                $wpdb->prefix . 'yeison_btx_queue',
                array(
                    'status' => $status,
                    'attempts' => $attempts,
                    'error_message' => 'Error creando lead en Bitrix24'
                ),
                array('id' => $queue_id),
                array('%s', '%d', '%s'),
                array('%d')
            );
            
            yeison_btx_log('❌ Error creando Lead en Bitrix24', 'error', array(
                'queue_id' => $queue_id,
                'attempts' => $attempts,
                'status' => $status
            ));
            
            return false;
        }
    }
     */
    // ... mantener todos los demás métodos exactamente iguales (sanitize_form_data, detect_form_type, is_spam, etc.)
    
    /**
     * Sanitizar datos del formulario
     */











    /**
     * 🆕 NUEVO: Procesar elemento de cola con reintentos
     */
    public function process_single_queue_item_with_retry($queue_id, $max_retries = 3) {
        global $wpdb;
        
        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            yeison_btx_log("🔄 Intento #{$attempt} de procesar cola", 'info', array(
                'queue_id' => $queue_id,
                'attempt' => $attempt,
                'max_retries' => $max_retries
            ));
            
            // Buscar el elemento en la cola
            $queue_item = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}yeison_btx_queue WHERE id = %d",
                $queue_id
            ));
            
            if ($queue_item) {
                yeison_btx_log('✅ Elemento de cola encontrado', 'success', array(
                    'queue_id' => $queue_id,
                    'status' => $queue_item->status,
                    'attempt_used' => $attempt
                ));
                
                // Si ya fue procesado, no hacer nada más
                if ($queue_item->status === 'processed') {
                    yeison_btx_log('ℹ️ Elemento ya procesado previamente', 'info', array('queue_id' => $queue_id));
                    return true;
                }
                
                // Procesar el elemento
                return $this->process_single_queue_item($queue_id);
            }
            
            yeison_btx_log("⏳ Elemento no encontrado, esperando... (intento #{$attempt})", 'warning', array(
                'queue_id' => $queue_id
            ));
            
            // Esperar 1 segundo antes del siguiente intento
            if ($attempt < $max_retries) {
                sleep(1);
            }
        }
        
        yeison_btx_log('❌ No se pudo encontrar elemento después de todos los intentos', 'error', array(
            'queue_id' => $queue_id,
            'max_retries' => $max_retries
        ));
        
        return false;
    }














    /**
     * Procesar un elemento específico de la cola - VERSIÓN MEJORADA
     */
    public function process_single_queue_item($queue_id) {
        global $wpdb;
        
        yeison_btx_log('🔄 Procesando elemento de cola', 'info', array('queue_id' => $queue_id));
        
        // 🔧 Buscar el elemento sin restricción de estado para debugging
        $queue_item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}yeison_btx_queue WHERE id = %d",
            $queue_id
        ));
        
        if (!$queue_item) {
            yeison_btx_log('❌ Elemento de cola no encontrado', 'error', array('queue_id' => $queue_id));
            return false;
        }
        
        // Verificar estado
        if ($queue_item->status !== 'pending') {
            yeison_btx_log('ℹ️ Elemento no está pendiente', 'info', array(
                'queue_id' => $queue_id,
                'current_status' => $queue_item->status
            ));
            return $queue_item->status === 'processed';
        }
        
        $form_data = json_decode($queue_item->form_data, true);
        
        if (!$form_data) {
            yeison_btx_log('❌ Error decodificando datos de formulario', 'error', array(
                'queue_id' => $queue_id,
                'raw_data' => $queue_item->form_data
            ));
            return false;
        }
        
        yeison_btx_log('📋 Datos de cola obtenidos', 'debug', array(
            'queue_id' => $queue_id,
            'form_type' => $queue_item->form_type,
            'data_keys' => array_keys($form_data)
        ));
        
        $api = yeison_btx_api();
        
        if (!$api->is_authorized()) {
            yeison_btx_log('❌ API de Bitrix24 no autorizada', 'error');
            
            // Marcar como fallido por falta de autorización
            $wpdb->update(
                $wpdb->prefix . 'yeison_btx_queue',
                array(
                    'status' => 'failed',
                    'error_message' => 'API de Bitrix24 no autorizada'
                ),
                array('id' => $queue_id),
                array('%s', '%s'),
                array('%d')
            );
            
            return false;
        }
        
        // Mapear a lead de Bitrix24
        $lead_data = yeison_btx_map_form_to_lead($form_data);
        
        yeison_btx_log('🗺️ Datos mapeados para Lead', 'debug', array(
            'lead_data_keys' => array_keys($lead_data),
            'lead_title' => $lead_data['TITLE'] ?? 'Sin título',
            'lead_name' => $lead_data['NAME'] ?? 'Sin nombre',
            'lead_email' => isset($lead_data['EMAIL'][0]['VALUE']) ? $lead_data['EMAIL'][0]['VALUE'] : 'Sin email'
        ));
        
        // Intentar crear lead
        $lead_id = $api->create_lead($lead_data);
        
        if ($lead_id) {
            // Marcar como procesado
            $wpdb->update(
                $wpdb->prefix . 'yeison_btx_queue',
                array(
                    'status' => 'processed',
                    'processed_at' => current_time('mysql')
                ),
                array('id' => $queue_id),
                array('%s', '%s'),
                array('%d')
            );
            
            // Crear registro de sincronización
            $wpdb->insert(
                $wpdb->prefix . 'yeison_btx_sync',
                array(
                    'entity_type' => 'form_lead',
                    'local_id' => $queue_id,
                    'remote_id' => $lead_id,
                    'sync_status' => 'synced',
                    'sync_data' => wp_json_encode(array(
                        'form_type' => $queue_item->form_type,
                        'lead_title' => $lead_data['TITLE'],
                        'processed_at' => current_time('mysql')
                    ))
                ),
                array('%s', '%s', '%s', '%s', '%s')
            );
            
            yeison_btx_log('🎉 Lead creado exitosamente en Bitrix24', 'success', array(
                'queue_id' => $queue_id,
                'lead_id' => $lead_id,
                'form_type' => $queue_item->form_type,
                'lead_title' => $lead_data['TITLE']
            ));
            
            return true;
        } else {
            // Marcar como error e incrementar intentos
            $attempts = intval($queue_item->attempts) + 1;
            $status = $attempts >= 3 ? 'failed' : 'pending';
            
            $wpdb->update(
                $wpdb->prefix . 'yeison_btx_queue',
                array(
                    'status' => $status,
                    'attempts' => $attempts,
                    'error_message' => 'Error creando lead en Bitrix24'
                ),
                array('id' => $queue_id),
                array('%s', '%d', '%s'),
                array('%d')
            );
            
            yeison_btx_log('❌ Error creando Lead en Bitrix24', 'error', array(
                'queue_id' => $queue_id,
                'attempts' => $attempts,
                'status' => $status
            ));
            
            return false;
        }
    }











    private function sanitize_form_data($form_data) {
        $clean_data = array();
        
        foreach ($form_data as $key => $value) {
            // Saltar campos excluidos
            if (in_array(strtolower($key), $this->config['excluded_fields'])) {
                continue;
            }
            
            // Sanitizar según tipo
            if (is_array($value)) {
                $clean_data[$key] = $this->sanitize_form_data($value);
            } elseif (is_email($value)) {
                $clean_data[$key] = sanitize_email($value);
            } elseif (filter_var($value, FILTER_VALIDATE_URL)) {
                $clean_data[$key] = esc_url_raw($value);
            } elseif (is_numeric($value)) {
                $clean_data[$key] = sanitize_text_field($value);
            } else {
                $clean_data[$key] = sanitize_textarea_field($value);
            }
        }
        
        return $clean_data;
    }
    
    /**
     * Detectar tipo de formulario
     */
    private function detect_form_type($form_data, $origin = '') {
        // Patrones para diferentes tipos
        $patterns = array(
            'contact' => array('email', 'name', 'message', 'subject'),
            'quote' => array('budget', 'project', 'price', 'cost'),
            'newsletter' => array('email', 'subscribe'),
            'registration' => array('register', 'signup', 'account'),
            'support' => array('help', 'support', 'issue', 'problem')
        );
        
        $fields = array_keys($form_data);
        $field_string = strtolower(implode(' ', $fields));
        
        // También considerar el origen
        $origin_string = strtolower($origin);
        
        foreach ($patterns as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($field_string, $keyword) !== false || 
                    strpos($origin_string, $keyword) !== false) {
                    return $type;
                }
            }
        }
        
        return 'general';
    }
    
    /**
     * Detectar spam usando honeypot y otras técnicas
     */
    private function is_spam($form_data) {
        // Verificar honeypot fields
        foreach ($this->config['honeypot_fields'] as $honeypot) {
            if (isset($form_data[$honeypot]) && !empty($form_data[$honeypot])) {
                return true;
            }
        }
        
        // Verificar tiempo de envío muy rápido (menos de 3 segundos)
        if (isset($form_data['_start_time'])) {
            $time_taken = time() - intval($form_data['_start_time']);
            if ($time_taken < 3) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Obtener estado del handler
     */
    public function get_status() {
        global $wpdb;
        
        $stats = array(
            'enabled' => $this->config['enabled'],
            'auto_process' => $this->config['auto_process'],
            'pending_queue' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}yeison_btx_queue WHERE status = 'pending'"),
            'processed_today' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}yeison_btx_queue WHERE status = 'processed' AND DATE(processed_at) = %s",
                current_time('Y-m-d')
            )),
            'endpoints' => array(
                'rest_api' => rest_url('yeison-btx/v1/form'),
                'ajax' => admin_url('admin-ajax.php?action=yeison_btx_form')
            )
        );
        
        return rest_ensure_response($stats);
    }
    
    // ... mantener todos los demás métodos exactamente iguales
}

/**
 * Función global para obtener instancia
 */
function yeison_btx_forms() {
    return YeisonBTX_Forms_Handler::get_instance();
}