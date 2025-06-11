<?php
/**
 * Clase para manejo de API de Bitrix24
 * 
 * @package YeisonBTX
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class YeisonBTX_Bitrix_API {
    
    /**
     * Instancia única (Singleton)
     */
    private static $instance = null;
    
    /**
     * Configuración de la API
     */
    private $config = array();
    
    /**
     * Constructor privado
     */
    private function __construct() {
        $this->load_config();
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
     * Cargar configuración desde opciones
     */
    private function load_config() {
        $this->config = array(
            'domain' => yeison_btx_get_option('bitrix_domain'),
            'client_id' => yeison_btx_get_option('client_id'),
            'client_secret' => yeison_btx_get_option('client_secret'),
            'access_token' => yeison_btx_get_option('access_token'),
            'refresh_token' => yeison_btx_get_option('refresh_token'),
            'redirect_uri' => admin_url('admin.php?page=yeison-btx&action=oauth')
        );
    }
    
    /**
     * Obtener URL de autorización OAuth2
     */
    public function get_auth_url() {
        if (empty($this->config['domain']) || empty($this->config['client_id'])) {
            return false;
        }
        
        $params = array(
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'scope' => 'crm',
            'state' => wp_create_nonce('yeison_btx_oauth')
        );
        
        return 'https://' . $this->config['domain'] . '/oauth/authorize/?' . http_build_query($params);
    }
    
    /**
     * Intercambiar código por tokens
     */
    public function exchange_code_for_tokens($code, $state) {
        // Verificar nonce
        if (!wp_verify_nonce($state, 'yeison_btx_oauth')) {
            yeison_btx_log('OAuth: Estado inválido', 'error', array('state' => $state));
            return false;
        }
        
        $url = 'https://' . $this->config['domain'] . '/oauth/token/';
        
        $data = array(
            'grant_type' => 'authorization_code',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $this->config['redirect_uri'],
            'code' => $code
        );
        
        $response = $this->make_request($url, $data, 'POST', false);
        
        if ($response && isset($response['access_token'])) {
            // Guardar tokens
            yeison_btx_update_option('access_token', $response['access_token']);
            yeison_btx_update_option('refresh_token', $response['refresh_token']);
            
            // Actualizar config local
            $this->config['access_token'] = $response['access_token'];
            $this->config['refresh_token'] = $response['refresh_token'];
            
            yeison_btx_log('OAuth: Tokens obtenidos correctamente', 'success', array(
                'domain' => $this->config['domain'],
                'expires_in' => $response['expires_in'] ?? 'no especificado'
            ));
            
            return true;
        }
        
        yeison_btx_log('OAuth: Error obteniendo tokens', 'error', array('response' => $response));
        return false;
    }
    
    /**
     * Renovar access token usando refresh token
     */
    public function refresh_access_token() {
        if (empty($this->config['refresh_token'])) {
            return false;
        }
        
        $url = 'https://' . $this->config['domain'] . '/oauth/token/';
        
        $data = array(
            'grant_type' => 'refresh_token',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'refresh_token' => $this->config['refresh_token']
        );
        
        $response = $this->make_request($url, $data, 'POST', false);
        
        if ($response && isset($response['access_token'])) {
            // Actualizar tokens
            yeison_btx_update_option('access_token', $response['access_token']);
            yeison_btx_update_option('refresh_token', $response['refresh_token']);
            
            $this->config['access_token'] = $response['access_token'];
            $this->config['refresh_token'] = $response['refresh_token'];
            
            yeison_btx_log('Token renovado correctamente', 'success');
            return true;
        }
        
        yeison_btx_log('Error renovando token', 'error', array('response' => $response));
        return false;
    }
    
    /**
     * Hacer petición a la API de Bitrix24
     */
    public function api_call($method, $params = array()) {
        if (empty($this->config['access_token'])) {
            yeison_btx_log('API Call: No hay access token', 'error');
            return false;
        }
        
        $url = 'https://' . $this->config['domain'] . '/rest/' . $method . '.json';
        
        $data = array_merge($params, array(
            'auth' => $this->config['access_token']
        ));
        
        $response = $this->make_request($url, $data);
        
        // Si el token expiró, intentar renovar
        if (isset($response['error']) && $response['error'] === 'expired_token') {
            yeison_btx_log('Token expirado, renovando...', 'warning');
            
            if ($this->refresh_access_token()) {
                // Reintentar con nuevo token
                $data['auth'] = $this->config['access_token'];
                $response = $this->make_request($url, $data);
            }
        }
        
        return $response;
    }
    
    /**
     * Test de conectividad
     */
    public function test_connection() {
        $result = array(
            'success' => false,
            'message' => '',
            'data' => array()
        );
        
        // Verificar configuración básica
        if (empty($this->config['domain'])) {
            $result['message'] = 'Dominio de Bitrix24 no configurado';
            return $result;
        }
        
        if (empty($this->config['access_token'])) {
            $result['message'] = 'No hay access token. Debes autorizar primero.';
            return $result;
        }
        
        // Probar llamada simple
        $response = $this->api_call('app.info');
        
        if ($response && isset($response['result'])) {
            $result['success'] = true;
            $result['message'] = 'Conexión exitosa con Bitrix24';
            $result['data'] = array(
                'app_name' => $response['result']['ID'] ?? 'N/A',
                'domain' => $this->config['domain'],
                'status' => $response['result']['STATUS'] ?? 'ACTIVE'
            );
            
            yeison_btx_log('Test de conexión exitoso', 'success', $result['data']);
        } else {
            $result['message'] = 'Error en la conexión: ' . ($response['error_description'] ?? 'Respuesta inválida');
            yeison_btx_log('Test de conexión fallido', 'error', array('response' => $response));
        }
        
        return $result;
    }
    
    /**
     * Crear lead en Bitrix24
     */
    public function create_lead($lead_data) {
        $response = $this->api_call('crm.lead.add', array(
            'fields' => $lead_data
        ));
        
        if ($response && isset($response['result'])) {
            yeison_btx_log('Lead creado en Bitrix24', 'success', array(
                'lead_id' => $response['result'],
                'title' => $lead_data['TITLE'] ?? 'Sin título'
            ));
            
            return $response['result']; // ID del lead
        }
        
        yeison_btx_log('Error creando lead', 'error', array(
            'response' => $response,
            'lead_data' => $lead_data
        ));
        
        return false;
    }
    
    /**
     * Hacer petición HTTP
     */
    private function make_request($url, $data = array(), $method = 'POST', $use_wp_remote = true) {
        if ($use_wp_remote) {
            // Usar WordPress HTTP API
            $args = array(
                'timeout' => 30,
                'body' => $data,
                'method' => $method
            );
            
            $response = wp_remote_request($url, $args);
            
            if (is_wp_error($response)) {
                yeison_btx_log('HTTP Error (WP)', 'error', array(
                    'url' => $url,
                    'error' => $response->get_error_message()
                ));
                return false;
            }
            
            $body = wp_remote_retrieve_body($response);
            $decoded = json_decode($body, true);
            
            return $decoded;
        } else {
            // Usar cURL directamente para OAuth
            if (!extension_loaded('curl')) {
                yeison_btx_log('cURL no disponible', 'error');
                return false;
            }
            
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => ($method === 'POST'),
                CURLOPT_POSTFIELDS => http_build_query($data),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'YeisonBTX/1.0'
            ));
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                yeison_btx_log('cURL Error', 'error', array(
                    'url' => $url,
                    'error' => $error
                ));
                return false;
            }
            
            $decoded = json_decode($response, true);
            return $decoded;
        }
    }
    
    /**
     * Verificar si está configurado
     */
    public function is_configured() {
        return !empty($this->config['domain']) && 
               !empty($this->config['client_id']) && 
               !empty($this->config['client_secret']);
    }
    

    
    /**
     * Verificar si está autorizado
     */
    public function is_authorized() {
        return $this->is_configured() && !empty($this->config['access_token']);
    }
}

/**
 * Función global para obtener instancia de API
 */
function yeison_btx_api() {
    return YeisonBTX_Bitrix_API::get_instance();
}