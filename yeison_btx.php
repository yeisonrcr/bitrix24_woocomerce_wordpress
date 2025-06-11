<?php
/**
 * Plugin Name: Yeison BTX - Bitrix24 Integration
 * Plugin URI: https://yeison.guruxdev.com/
 * Description: Sincronización bidireccional WooCommerce ↔ Bitrix24 + Captura universal de formularios
 * Version: 1.0.0
 * Author: Yeison Araya
 * Author URI: https://yeison.guruxdev.com/
 * Text Domain: yeison-enterprice-plugin-bitrix
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit('No direct script access allowed');
}

// Definir constantes del plugin
define('YEISON_BTX_VERSION', '1.0.0');
define('YEISON_BTX_PLUGIN_FILE', __FILE__);
define('YEISON_BTX_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('YEISON_BTX_PLUGIN_URL', plugin_dir_url(__FILE__));
define('YEISON_BTX_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Clase principal del plugin
 */
class YeisonBTX {
    
    /**
     * Instancia única del plugin (Singleton)
     */
    private static $instance = null;
    
    /**
     * Constructor privado (Singleton)
     */
    private function __construct() {
        // Cargar funciones primero
        $this->load_functions();
        
        // Inicializar hooks básicos
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
     * Cargar archivo de funciones y todas las clases
     */
    private function load_functions() {
        // Funciones básicas
        $functions_file = YEISON_BTX_PLUGIN_DIR . 'includes/functions.php';
        if (file_exists($functions_file)) {
            require_once $functions_file;
        }
        
        // API de Bitrix24
        $api_file = YEISON_BTX_PLUGIN_DIR . 'includes/class-bitrix-api.php';
        if (file_exists($api_file)) {
            require_once $api_file;
        }
        
        // Manejador de formularios
        $forms_file = YEISON_BTX_PLUGIN_DIR . 'includes/class-forms-handler.php';
        if (file_exists($forms_file)) {
            require_once $forms_file;
        }
        
        // Sincronización WooCommerce
        $woo_file = YEISON_BTX_PLUGIN_DIR . 'includes/class-woo-sync.php';
        if (file_exists($woo_file)) {
            require_once $woo_file;
        }
        
        // Manejador de webhooks
        $webhooks_file = YEISON_BTX_PLUGIN_DIR . 'includes/class-webhook-handler.php';
        if (file_exists($webhooks_file)) {
            require_once $webhooks_file;
        }
        
        // Sincronización bidireccional
        $bidirectional_file = YEISON_BTX_PLUGIN_DIR . 'includes/class-bidirectional-sync.php';
        if (file_exists($bidirectional_file)) {
            require_once $bidirectional_file;
        }
        
        // Sistema anti-loop avanzado
        $anti_loop_file = YEISON_BTX_PLUGIN_DIR . 'includes/class-anti-loop.php';
        if (file_exists($anti_loop_file)) {
            require_once $anti_loop_file;
        }
        
        // Sistema de mapeo de datos
        $data_mapping_file = YEISON_BTX_PLUGIN_DIR . 'includes/class-data-mapping.php';
        if (file_exists($data_mapping_file)) {
            require_once $data_mapping_file;
        }
    }

    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        // Hooks de activación/desactivación
        register_activation_hook(YEISON_BTX_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(YEISON_BTX_PLUGIN_FILE, array($this, 'deactivate'));
        
        // Verificar requisitos
        add_action('admin_init', array($this, 'check_requirements'));
        add_action('admin_init', array($this, 'handle_oauth_callback'));
        
        // Cargar textdomain
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        
        // Solo en admin
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        }
        
        // Inicializar todas las clases del sistema
        $this->init_all_components();
    }
    
    /**
     * Inicializar todos los componentes del sistema
     */
    private function init_all_components() {
        // API de Bitrix24
        if (class_exists('YeisonBTX_Bitrix_API')) {
            yeison_btx_api();
        }
        
        // Manejador de formularios
        if (class_exists('YeisonBTX_Forms_Handler')) {
            yeison_btx_forms();
        }
        
        // Sincronización WooCommerce
        if (class_exists('YeisonBTX_WooCommerce_Sync')) {
            yeison_btx_woo_sync();
        }
        
        // Manejador de webhooks
        if (class_exists('YeisonBTX_Webhook_Handler')) {
            yeison_btx_webhooks();
        }
        
        // Sincronización bidireccional
        if (class_exists('YeisonBTX_Bidirectional_Sync')) {
            yeison_btx_bidirectional_sync();
        }
        
        // Sistema anti-loop avanzado
        if (class_exists('YeisonBTX_Anti_Loop')) {
            yeison_btx_anti_loop();
        }
        
        // Sistema de mapeo de datos
        if (class_exists('YeisonBTX_Data_Mapping')) {
            yeison_btx_data_mapping();
        }
    }

    /**
     * Manejar callback OAuth
     */
    public function handle_oauth_callback() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'yeison-btx') {
            return;
        }
        
        if (!isset($_GET['action']) || $_GET['action'] !== 'oauth') {
            return;
        }
        
        if (!isset($_GET['code']) || !isset($_GET['state'])) {
            return;
        }
        
        $api = yeison_btx_api();
        $result = $api->exchange_code_for_tokens($_GET['code'], $_GET['state']);
        
        if ($result) {
            wp_redirect(admin_url('admin.php?page=yeison-btx&oauth=success'));
            do_action('yeison_btx_oauth_success');
        } else {
            wp_redirect(admin_url('admin.php?page=yeison-btx&oauth=error'));
        }
        exit;
    }
    
    /**
     * Agregar menú de administración
     */
    public function add_admin_menu() {
        // Menú principal
        add_menu_page(
            'Yeison BTX',
            'Yeison BTX',
            'manage_options',
            'yeison-btx',
            array($this, 'admin_page'),
            'dashicons-arrow-right-alt2',
            30
        );
        
        // Submenú - Configuración (renombrar el principal para evitar duplicación)
        add_submenu_page(
            'yeison-btx',
            'Configuración General',
            'Configuración',
            'manage_options',
            'yeison-btx',
            array($this, 'admin_page')
        );
        
        // Submenú - Configuración Avanzada
        add_submenu_page(
            'yeison-btx',
            'Configuración Avanzada',
            'Config. Avanzada',
            'manage_options',
            'yeison-btx-advanced',
            array($this, 'advanced_config_page')
        );
        
        // Submenú - Logs y Monitoreo
        add_submenu_page(
            'yeison-btx',
            'Logs y Monitoreo',
            'Logs',
            'manage_options',
            'yeison-btx-logs',
            array($this, 'logs_page')
        );
    }
    
    /**
     * Página principal de administración
     */
    public function admin_page() {
        $api = yeison_btx_api();
        
        // Procesar formulario de configuración
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['yeison_btx_config'])) {
            check_admin_referer('yeison_btx_config');
            
            $domain = sanitize_text_field($_POST['bitrix_domain']);
            $client_id = sanitize_text_field($_POST['client_id']);
            $client_secret = sanitize_text_field($_POST['client_secret']);
            
            yeison_btx_update_option('bitrix_domain', $domain);
            yeison_btx_update_option('client_id', $client_id);
            yeison_btx_update_option('client_secret', $client_secret);
            
            yeison_btx_log('Configuración guardada', 'info', array(
                'domain' => $domain,
                'client_id' => $client_id
            ));
            
            echo '<div class="notice notice-success"><p>Configuración guardada correctamente.</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="notice notice-info">
                <p><strong>🎉 Sistema Yeison BTX Completado - Versión Final</strong></p>
                <p>Versión: <?php echo YEISON_BTX_VERSION; ?> | Sistema de sincronización bidireccional WooCommerce ↔ Bitrix24</p>
            </div>
            
            <?php if (isset($_GET['oauth'])): ?>
                <?php if ($_GET['oauth'] === 'success'): ?>
                <div class="notice notice-success">
                    <p><strong>¡Autorización exitosa!</strong> Tu sitio está conectado con Bitrix24.</p>
                </div>
                <?php else: ?>
                <div class="notice notice-error">
                    <p><strong>Error en autorización.</strong> Revisa los logs para más detalles.</p>
                </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <!-- Estado general del sistema -->
            <div class="card" style="margin-bottom: 20px;">
                <h2>🚀 Estado del Sistema</h2>
                <?php
                $system_health = $this->get_system_health();
                $health_color = $system_health['score'] >= 90 ? '#28a745' : 
                               ($system_health['score'] >= 70 ? '#ffc107' : '#dc3545');
                ?>
                <div style="display: grid; grid-template-columns: 200px 1fr; gap: 20px; align-items: center;">
                    <div style="text-align: center;">
                        <div style="font-size: 3em; font-weight: bold; color: <?php echo $health_color; ?>;">
                            <?php echo $system_health['score']; ?>%
                        </div>
                        <div style="font-weight: bold;">Salud del Sistema</div>
                    </div>
                    <div>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px;">
                            <?php foreach ($system_health['components'] as $component => $status): ?>
                            <div style="text-align: center; padding: 10px; background: <?php echo $status ? '#d4edda' : '#f8d7da'; ?>; border-radius: 5px;">
                                <div><?php echo $status ? '✅' : '❌'; ?></div>
                                <small><?php echo $component; ?></small>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Configuración principal -->
            <div class="card">
                <h2>1. Configuración de Bitrix24</h2>
                <form method="post">
                    <?php wp_nonce_field('yeison_btx_config'); ?>
                    <input type="hidden" name="yeison_btx_config" value="1">
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Dominio de Bitrix24</th>
                            <td>
                                <input type="text" 
                                       name="bitrix_domain" 
                                       value="<?php echo esc_attr(yeison_btx_get_option('bitrix_domain')); ?>" 
                                       class="regular-text" 
                                       placeholder="miempresa.bitrix24.com">
                                <p class="description">Sin https://, solo el dominio</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Client ID</th>
                            <td>
                                <input type="text" 
                                       name="client_id" 
                                       value="<?php echo esc_attr(yeison_btx_get_option('client_id')); ?>" 
                                       class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Client Secret</th>
                            <td>
                                <input type="password" 
                                       name="client_secret" 
                                       value="<?php echo esc_attr(yeison_btx_get_option('client_secret')); ?>" 
                                       class="regular-text">
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button('Guardar Configuración'); ?>
                </form>
            </div>

            <!-- Autorización -->
            <div class="card">
                <h2>2. Autorización</h2>
                <?php if ($api->is_configured()): ?>
                    <?php if ($api->is_authorized()): ?>
                        <p><span class="dashicons dashicons-yes-alt" style="color: green;"></span> 
                           <strong>Autorizado correctamente</strong></p>
                        <p>
                            <a href="#" onclick="testConnection()" class="button">
                                Probar Conexión
                            </a>
                        </p>
                        <div id="connection-result" style="margin-top: 10px;"></div>
                    <?php else: ?>
                        <p>Configuración completa. Ahora autoriza la conexión:</p>
                        <p>
                            <a href="<?php echo esc_url($api->get_auth_url()); ?>" 
                               class="button button-primary">
                                Autorizar con Bitrix24
                            </a>
                        </p>
                    <?php endif; ?>
                <?php else: ?>
                    <p>Completa la configuración primero.</p>
                <?php endif; ?>
            </div>
            
            <!-- Estadísticas del sistema -->
            <?php if (function_exists('yeison_btx_get_stats')): ?>
            <div class="card">
                <h2>3. Estadísticas del Sistema</h2>
                <?php $stats = yeison_btx_get_stats(); ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 5px;">
                        <div style="font-size: 2em; font-weight: bold; color: #007cba;"><?php echo $stats['total_logs']; ?></div>
                        <div>Total de Logs</div>
                    </div>
                    <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 5px;">
                        <div style="font-size: 2em; font-weight: bold; color: #28a745;"><?php echo $stats['total_synced']; ?></div>
                        <div>Registros Sincronizados</div>
                    </div>
                    <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 5px;">
                        <div style="font-size: 2em; font-weight: bold; color: <?php echo $stats['pending_queue'] > 10 ? '#ffc107' : '#28a745'; ?>;"><?php echo $stats['pending_queue']; ?></div>
                        <div>Cola Pendiente</div>
                    </div>
                    <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 5px;">
                        <div style="font-size: 2em; font-weight: bold; color: <?php echo $stats['errors_today'] > 5 ? '#dc3545' : '#28a745'; ?>;"><?php echo $stats['errors_today']; ?></div>
                        <div>Errores Hoy</div>
                    </div>
                </div>
                
                <!-- Logs Recientes -->
                <h3 style="margin-top: 20px;">📝 Actividad Reciente</h3>
                <?php
                global $wpdb;
                $recent_logs = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}yeison_btx_logs 
                    ORDER BY created_at DESC 
                    LIMIT %d",
                    10
                ));
                
                if ($recent_logs): ?>
                <div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; border-radius: 5px; margin-top: 10px;">
                    <?php foreach ($recent_logs as $log): 
                        $color_map = array(
                            'error' => '#dc3545',
                            'success' => '#28a745', 
                            'warning' => '#ffc107',
                            'info' => '#17a2b8'
                        );
                        $color = $color_map[$log->type] ?? '#6c757d';
                    ?>
                    <div style="padding: 8px 12px; border-bottom: 1px solid #eee;">
                        <span style="color: <?php echo $color; ?>; font-weight: bold; font-size: 12px;">
                            [<?php echo strtoupper($log->type); ?>]
                        </span>
                        <small style="color: #666; margin-left: 10px;">
                            <?php echo date('Y-m-d H:i:s', strtotime($log->created_at)); ?>
                        </small>
                        <br>
                        <span style="font-size: 13px;"><?php echo esc_html($log->message); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p style="color: #666; font-style: italic;">No hay logs recientes</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Tests del sistema -->
            <div class="card">
                <h2>4. Tests y Verificación</h2>
                <p>Ejecuta tests para verificar que todo el sistema funciona correctamente</p>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                   
                    <a href="<?php echo admin_url('admin.php?page=yeison-btx-advanced'); ?>" 
                       class="button button-secondary">
                        ⚙️ Configuración Avanzada
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=yeison-btx-logs'); ?>" 
                       class="button button-secondary">
                        📝 Ver Logs
                    </a>
                </div>
            </div>
        </div>
        
        <script>
        function testConnection() {
            document.getElementById('connection-result').innerHTML = 'Probando conexión...';
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=yeison_btx_test_connection&nonce=<?php echo wp_create_nonce('yeison_btx_test'); ?>'
            })
            .then(response => response.json())
            .then(data => {
                const result = document.getElementById('connection-result');
                if (data.success) {
                    result.innerHTML = '<div class="notice notice-success inline"><p>' + data.data.message + '</p></div>';
                } else {
                    result.innerHTML = '<div class="notice notice-error inline"><p>' + data.data + '</p></div>';
                }
            })
            .catch(error => {
                document.getElementById('connection-result').innerHTML = 
                    '<div class="notice notice-error inline"><p>Error en la prueba</p></div>';
            });
        }
        </script>
        <?php
    }
    
    /**
     * Página de configuración avanzada
     */
    public function advanced_config_page() {
        ?>
        <div class="wrap">
            <h1>⚙️ Configuración Avanzada - Yeison BTX</h1>
            <p class="description">Configuración detallada de mapeo y reglas de sincronización</p>
            
            <div class="card">
                <h2>🗺️ Mapeo de Datos</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Mapeo Automático</th>
                        <td>
                            <label>
                                <input type="checkbox" name="yeison_btx_auto_mapping" value="1" <?php checked(yeison_btx_get_option('auto_mapping', true)); ?>>
                                Activar mapeo automático de campos
                            </label>
                            <p class="description">El sistema mapeará automáticamente campos similares entre WooCommerce y Bitrix24</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Validación de Datos</th>
                        <td>
                            <label>
                                <input type="checkbox" name="yeison_btx_validate_data" value="1" <?php checked(yeison_btx_get_option('validate_data', true)); ?>>
                                Validar datos antes de sincronizar
                            </label>
                            <p class="description">Verificar que los datos cumplan con los formatos requeridos</p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="card">
                <h2>🔄 Reglas de Sincronización</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Dirección de Sincronización</th>
                        <td>
                            <select name="yeison_btx_sync_direction">
                                <option value="bidirectional" <?php selected(yeison_btx_get_option('sync_direction', 'bidirectional'), 'bidirectional'); ?>>Bidireccional</option>
                                <option value="to_bitrix" <?php selected(yeison_btx_get_option('sync_direction'), 'to_bitrix'); ?>>Solo WooCommerce → Bitrix24</option>
                                <option value="from_bitrix" <?php selected(yeison_btx_get_option('sync_direction'), 'from_bitrix'); ?>>Solo Bitrix24 → WooCommerce</option>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Frecuencia de Sincronización</th>
                        <td>
                            <select name="yeison_btx_sync_frequency">
                                <option value="immediate" <?php selected(yeison_btx_get_option('sync_frequency', 'immediate'), 'immediate'); ?>>Inmediata</option>
                                <option value="hourly" <?php selected(yeison_btx_get_option('sync_frequency'), 'hourly'); ?>>Cada hora</option>
                                <option value="daily" <?php selected(yeison_btx_get_option('sync_frequency'), 'daily'); ?>>Diaria</option>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="card">
                <h2>🛡️ Sistema Anti-Loop</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Prevención de Loops</th>
                        <td>
                            <label>
                                <input type="checkbox" name="yeison_btx_anti_loop" value="1" <?php checked(yeison_btx_get_option('anti_loop_enabled', true)); ?>>
                                Activar sistema anti-loop avanzado
                            </label>
                            <p class="description">Previene sincronizaciones infinitas entre sistemas</p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <p>
                <button type="button" class="button button-primary">Guardar Configuración Avanzada</button>
                <a href="<?php echo admin_url('admin.php?page=yeison-btx'); ?>" class="button button-secondary">Volver a Configuración</a>
            </p>
        </div>
        
        <style>
        .card { 
            background: white; 
            border: 1px solid #ccd0d4; 
            border-radius: 4px; 
            padding: 20px; 
            margin: 20px 0; 
        }
        </style>
        <?php
    }
    
    /**
     * Página de logs
     */
    public function logs_page() {
        global $wpdb;
        
        // Parámetros de filtrado
        $type_filter = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
        
        // Construir query
        $where_clause = '';
        if (!empty($type_filter) && in_array($type_filter, ['info', 'error', 'warning', 'success'])) {
            $where_clause = $wpdb->prepare("WHERE type = %s", $type_filter);
        }
        
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}yeison_btx_logs 
            {$where_clause}
            ORDER BY created_at DESC 
            LIMIT %d",
            $limit
        ));
        
        // Estadísticas de logs
        $stats = array(
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}yeison_btx_logs"),
            'errors' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}yeison_btx_logs WHERE type = 'error'"),
            'today' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}yeison_btx_logs WHERE DATE(created_at) = %s",
                current_time('Y-m-d')
            ))
        );
        
        ?>
        <div class="wrap">
            <h1>📝 Logs y Monitoreo - Yeison BTX</h1>
            <p class="description">Registro detallado de todas las actividades del sistema</p>
            
            <!-- Estadísticas -->
            <div class="card">
                <h2>📊 Estadísticas de Logs</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                    <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 5px;">
                        <div style="font-size: 2em; font-weight: bold; color: #007cba;"><?php echo $stats['total']; ?></div>
                        <div>Total Logs</div>
                    </div>
                    <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 5px;">
                        <div style="font-size: 2em; font-weight: bold; color: #dc3545;"><?php echo $stats['errors']; ?></div>
                        <div>Errores</div>
                    </div>
                    <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 5px;">
                        <div style="font-size: 2em; font-weight: bold; color: #28a745;"><?php echo $stats['today']; ?></div>
                        <div>Hoy</div>
                    </div>
                </div>
            </div>
            
            <!-- Filtros -->
            <div class="card">
                <form method="get" style="display: flex; gap: 10px; align-items: center;">
                    <input type="hidden" name="page" value="yeison-btx-logs">
                    
                    <label>Tipo:</label>
                    <select name="type">
                        <option value="">Todos</option>
                        <option value="info" <?php selected($type_filter, 'info'); ?>>Info</option>
                        <option value="success" <?php selected($type_filter, 'success'); ?>>Success</option>
                        <option value="warning" <?php selected($type_filter, 'warning'); ?>>Warning</option>
                        <option value="error" <?php selected($type_filter, 'error'); ?>>Error</option>
                    </select>
                    
                    <label>Límite:</label>
                    <select name="limit">
                        <option value="50" <?php selected($limit, 50); ?>>50</option>
                        <option value="100" <?php selected($limit, 100); ?>>100</option>
                        <option value="200" <?php selected($limit, 200); ?>>200</option>
                        <option value="500" <?php selected($limit, 500); ?>>500</option>
                    </select>
                    
                    <input type="submit" class="button" value="Filtrar">
                    <a href="?page=yeison-btx-logs" class="button">Reset</a>
                </form>
            </div>
            
            <!-- Tabla de logs -->
            <div style="overflow-x: auto;">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 150px;">Fecha/Hora</th>
                            <th style="width: 80px;">Tipo</th>
                            <th style="width: 120px;">Acción</th>
                            <th>Mensaje</th>
                            <th style="width: 100px;">Usuario</th>
                            <th style="width: 100px;">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 20px; color: #666;">
                                No hay logs para mostrar
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo date('Y-m-d H:i:s', strtotime($log->created_at)); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $log->type; ?>">
                                    <?php echo strtoupper($log->type); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($log->action); ?></td>
                            <td>
                                <?php echo esc_html($log->message); ?>
                                <?php if (!empty($log->data)): ?>
                                <details style="margin-top: 5px;">
                                    <summary style="cursor: pointer; color: #0073aa;">Ver datos</summary>
                                    <pre style="background: #f0f0f0; padding: 10px; margin-top: 5px; font-size: 11px; max-height: 200px; overflow-y: auto;"><?php echo esc_html($log->data); ?></pre>
                                </details>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                if ($log->user_id) {
                                    $user = get_user_by('id', $log->user_id);
                                    echo $user ? $user->user_login : 'Usuario #' . $log->user_id;
                                } else {
                                    echo 'Sistema';
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html($log->ip_address); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>



            <!-- FORMULARIO DE PRUEBA PARA CAPTURA UNIVERSAL -->
            <div class="card" style="margin-top: 30px; border: 2px solid #007cba;">
                <h2>🧪 Formulario de Prueba - Captura Universal</h2>
                <p class="description">Prueba la captura automática de formularios que se envía a Bitrix24 como Lead</p>
                
                <!-- Formulario HTML estándar -->
                <form id="yeison-test-form" method="post" style="background: #f9f9f9; padding: 20px; border-radius: 5px;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 5px;">Nombre:</label>
                            <input type="text" name="name" required 
                                   style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 3px;">
                        </div>
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 5px;">Apellido:</label>
                            <input type="text" name="last_name" 
                                   style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 3px;">
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 5px;">Email:</label>
                            <input type="email" name="email" required 
                                   style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 3px;">
                        </div>
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 5px;">Teléfono:</label>
                            <input type="tel" name="phone" 
                                   style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 3px;">
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-weight: bold; margin-bottom: 5px;">Empresa:</label>
                        <input type="text" name="company" 
                               style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 3px;">
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-weight: bold; margin-bottom: 5px;">Mensaje:</label>
                        <textarea name="message" rows="4" 
                                  style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 3px;"></textarea>
                    </div>
                    
                    <!-- Campos ocultos para testing -->
                    <input type="hidden" name="_start_time" value="<?php echo time(); ?>">
                    <input type="text" name="website" style="display:none;" placeholder="No llenar">
                    
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <button type="submit" style="background: #007cba; color: white; padding: 12px 24px; border: none; border-radius: 5px; cursor: pointer; font-weight: bold;">
                            🚀 Enviar Formulario de Prueba
                        </button>
                        <span id="form-status" style="font-weight: bold;"></span>
                    </div>
                </form>
                
                <!-- Resultado del envío -->
                <div id="form-result" style="margin-top: 15px; padding: 10px; border-radius: 5px; display: none;"></div>
                
                <!-- JavaScript para manejar el envío -->
                <script>
                document.getElementById('yeison-test-form').addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    // Mostrar estado de envío
                    const statusEl = document.getElementById('form-status');
                    const resultEl = document.getElementById('form-result');
                    
                    statusEl.textContent = '⏳ Enviando...';
                    statusEl.style.color = '#007cba';
                    resultEl.style.display = 'none';
                    
                    // Recoger datos del formulario
                    const formData = new FormData(this);
                    const data = {};
                    formData.forEach((value, key) => {
                        data[key] = value;
                    });
                    
                    // Agregar metadata de prueba
                    data._meta = {
                        test_form: true,
                        origin: window.location.href,
                        timestamp: new Date().toISOString(),
                        user_agent: navigator.userAgent
                    };
                    
                    // Enviar vía REST API
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
                        console.log('📊 Respuesta completa:', result);
                        
                        if (result.success || result.data) {
                            // Éxito
                            statusEl.textContent = '✅ ¡Enviado exitosamente!';
                            statusEl.style.color = '#28a745';
                            
                            resultEl.innerHTML = `
                                <div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; border-radius: 5px;">
                                    <h4>✅ Formulario procesado correctamente</h4>
                                    <p><strong>Estado:</strong> ${result.message || 'Procesado'}</p>
                                    ${result.data?.queue_id ? `<p><strong>ID Cola:</strong> ${result.data.queue_id}</p>` : ''}
                                    ${result.data?.form_type ? `<p><strong>Tipo detectado:</strong> ${result.data.form_type}</p>` : ''}
                                    <p><strong>Endpoint usado:</strong> REST API (/yeison-btx/v1/form)</p>
                                    <p><em>Revisa los logs arriba para ver el procesamiento detallado</em></p>
                                </div>
                            `;
                            resultEl.style.display = 'block';
                            
                            // Limpiar formulario
                            this.reset();
                            
                            // Recargar logs después de 2 segundos
                            setTimeout(() => {
                                window.location.reload();
                            }, 3000);
                            
                        } else {
                            // Error
                            statusEl.textContent = '❌ Error al enviar';
                            statusEl.style.color = '#dc3545';
                            
                            resultEl.innerHTML = `
                                <div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 5px;">
                                    <h4>❌ Error al procesar formulario</h4>
                                    <p><strong>Error:</strong> ${result.message || 'Error desconocido'}</p>
                                    <p><em>Revisa los logs arriba para más detalles</em></p>
                                </div>
                            `;
                            resultEl.style.display = 'block';
                        }
                    })
                    .catch(error => {
                        console.error('❌ Error de red:', error);
                        
                        statusEl.textContent = '❌ Error de conexión';
                        statusEl.style.color = '#dc3545';
                        
                        resultEl.innerHTML = `
                            <div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 5px;">
                                <h4>❌ Error de conexión</h4>
                                <p>No se pudo conectar con el servidor. Verifica:</p>
                                <ul>
                                    <li>Que el plugin esté activado</li>
                                    <li>Que los endpoints REST estén funcionando</li>
                                    <li>La configuración de Bitrix24</li>
                                </ul>
                            </div>
                        `;
                        resultEl.style.display = 'block';
                    });
                });
                </script>
                
                <!-- Información adicional para debugging -->
                <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;">
                    <h4>📋 Información de Testing</h4>
                    <ul style="margin: 0;">
                        <li><strong>Endpoint REST:</strong> <code><?php echo rest_url('yeison-btx/v1/form'); ?></code></li>
                        <li><strong>Detección automática:</strong> El formulario será detectado por el sistema universal</li>
                        <li><strong>Mapeo esperado:</strong> name → NAME, email → EMAIL, phone → PHONE, message → COMMENTS</li>
                        <li><strong>Resultado esperado:</strong> Lead creado en Bitrix24 con los datos ingresados</li>
                        <li><strong>Sistema anti-spam:</strong> Campo honeypot "website" (oculto) incluido</li>
                    </ul>
                </div>
            </div>




            
        </div>
        
        <style>
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .badge-success { background: #28a745; color: white; }
        .badge-error { background: #dc3545; color: white; }
        .badge-warning { background: #ffc107; color: #212529; }
        .badge-info { background: #17a2b8; color: white; }
        .card { 
            background: white; 
            border: 1px solid #ccd0d4; 
            border-radius: 4px; 
            padding: 20px; 
            margin: 20px 0; 
        }
        </style>
        <?php
    }
    
    /**
     * Obtener salud del sistema
     */
    private function get_system_health() {
        $components = array(
            'API Bitrix24' => false,
            'WooCommerce' => false,
            'Formularios' => false,
            'Webhooks' => false,
            'Anti-Loop' => false,
            'Mapeo' => false
        );
        
        // Verificar API
        if (class_exists('YeisonBTX_Bitrix_API')) {
            $api = yeison_btx_api();
            $components['API Bitrix24'] = $api->is_authorized();
        }
        
        // Verificar WooCommerce
        if (class_exists('YeisonBTX_WooCommerce_Sync')) {
            $components['WooCommerce'] = class_exists('WooCommerce');
        }
        
        // Verificar otros componentes
        $components['Formularios'] = class_exists('YeisonBTX_Forms_Handler');
        $components['Webhooks'] = class_exists('YeisonBTX_Webhook_Handler');
        $components['Anti-Loop'] = class_exists('YeisonBTX_Anti_Loop');
        $components['Mapeo'] = class_exists('YeisonBTX_Data_Mapping');

        $active_count = count(array_filter($components));
        $total_count = count($components);
        $score = round(($active_count / $total_count) * 100);
        
        return array(
            'score' => $score,
            'components' => $components,
            'active_count' => $active_count,
            'total_count' => $total_count
        );
    }
    
    /**
     * Cargar scripts de admin
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'yeison-btx') !== false) {
            wp_enqueue_style(
                'yeison-btx-admin',
                YEISON_BTX_PLUGIN_URL . 'assets/admin.css',
                array(),
                YEISON_BTX_VERSION
            );
        }
    }
    
    /**
     * Verificar requisitos
     */
    public function check_requirements() {
        $errors = array();
        
        // PHP Version
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            $errors[] = sprintf(
                'Yeison BTX requiere PHP 7.4 o superior. Tu versión: %s',
                PHP_VERSION
            );
        }
        
        // cURL
        if (!extension_loaded('curl')) {
            $errors[] = 'Yeison BTX requiere la extensión cURL de PHP.';
        }
        
        // JSON
        if (!extension_loaded('json')) {
            $errors[] = 'Yeison BTX requiere la extensión JSON de PHP.';
        }
        
        // Mostrar errores
        foreach ($errors as $error) {
            add_action('admin_notices', function() use ($error) {
                ?>
                <div class="notice notice-error">
                    <p><?php echo esc_html($error); ?></p>
                </div>
                <?php
            });
        }
    }
    
    /**
     * Cargar textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'yeison-btx',
            false,
            dirname(YEISON_BTX_PLUGIN_BASENAME) . '/languages'
        );
    }
    
    /**
     * Activar plugin
     */
    public function activate() {
        // Crear tablas
        $this->create_tables();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Marcar versión
        update_option('yeison_btx_version', YEISON_BTX_VERSION);
        
        // Log si la función existe
        if (function_exists('yeison_btx_log')) {
            yeison_btx_log('Plugin activado', 'info', array(
                'version' => YEISON_BTX_VERSION
            ));
        }
        
        // Programar eventos cron
        if (!wp_next_scheduled('yeison_btx_process_queue')) {
            wp_schedule_event(time(), 'hourly', 'yeison_btx_process_queue');
        }
        
        if (!wp_next_scheduled('yeison_btx_cleanup_patterns')) {
            wp_schedule_event(time(), 'daily', 'yeison_btx_cleanup_patterns');
        }
    }
    
    /**
     * Desactivar plugin
     */
    public function deactivate() {
        // Limpiar tareas programadas
        wp_clear_scheduled_hook('yeison_btx_process_queue');
        wp_clear_scheduled_hook('yeison_btx_cleanup_patterns');
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log de desactivación
        if (function_exists('yeison_btx_log')) {
            yeison_btx_log('Plugin desactivado', 'info');
        }
    }
    
    /**
     * Crear tablas de la base de datos
     */
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Tabla de logs
        $table_logs = $wpdb->prefix . 'yeison_btx_logs';
        $sql_logs = "CREATE TABLE IF NOT EXISTS $table_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            type varchar(50) NOT NULL DEFAULT 'info',
            action varchar(100) NOT NULL,
            message text,
            data longtext,
            user_id bigint(20) DEFAULT NULL,
            ip_address varchar(100) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY type_index (type),
            KEY created_at_index (created_at)
        ) $charset_collate;";
        
        // Tabla de sincronización
        $table_sync = $wpdb->prefix . 'yeison_btx_sync';
        $sql_sync = "CREATE TABLE IF NOT EXISTS $table_sync (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            entity_type varchar(50) NOT NULL,
            local_id varchar(100) NOT NULL,
            remote_id varchar(100) NOT NULL,
            sync_status varchar(20) DEFAULT 'synced',
            last_sync datetime DEFAULT CURRENT_TIMESTAMP,
            sync_data longtext,
            PRIMARY KEY (id),
            UNIQUE KEY entity_mapping (entity_type, local_id),
            KEY remote_lookup (entity_type, remote_id)
        ) $charset_collate;";
        
        // Tabla de queue
        $table_queue = $wpdb->prefix . 'yeison_btx_queue';
        $sql_queue = "CREATE TABLE IF NOT EXISTS $table_queue (
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
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_logs);
        dbDelta($sql_sync);
        dbDelta($sql_queue);
    }
}

// Función global para obtener la instancia
function yeison_btx() {
    return YeisonBTX::get_instance();
}

// Inicializar solo si no estamos en proceso de activación
if (!defined('WP_INSTALLING') || !WP_INSTALLING) {
    add_action('plugins_loaded', 'yeison_btx', 1);
}