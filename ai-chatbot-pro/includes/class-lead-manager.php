<?php
/**
 * Clase para gestionar leads y detección de datos de contacto
 *
 * @package AI_Chatbot_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class AICP_Lead_Manager {
    
    /**
     * Patrones para detectar emails, teléfonos y URLs
     */
    private static $email_pattern = '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/';
    private static $phone_patterns = [
        '/\b(?:\+34|0034|34)?[ -]?[6789]\d{2}[ -]?\d{2}[ -]?\d{2}[ -]?\d{2}\b/', // España
        '/\b(?:\+\d{1,3}[ -]?)?\(?\d{3}\)?[ -]?\d{3}[ -]?\d{4}\b/', // Internacional
        '/\b\d{3}[ -]?\d{3}[ -]?\d{3}\b/', // Formato simple
    ];
    private static $url_pattern = '/(https?:\/\/)?([\w\-]+\.)+[\w\-]+(\/[\w\-._~:\/?#[\]@!$&\'()*+,;=]*)?/';
    
    /**
     * Inicializar la clase
     */
    public static function init() {
        add_action('wp_ajax_aicp_mark_calendar_lead', [__CLASS__, 'handle_calendar_lead']);
        add_action('wp_ajax_nopriv_aicp_mark_calendar_lead', [__CLASS__, 'handle_calendar_lead']);
        add_action('wp_ajax_aicp_check_lead_status', [__CLASS__, 'handle_check_lead_status']);
        add_action('wp_ajax_nopriv_aicp_check_lead_status', [__CLASS__, 'handle_check_lead_status']);
        
        // Hook para procesar leads después de guardar conversación
        add_action('aicp_conversation_saved', [__CLASS__, 'process_lead_data'], 10, 3);

        // Enviar lead a webhook si se configura
        add_action('aicp_lead_detected', [__CLASS__, 'send_lead_to_webhook'], 10, 4);
    }
    
    /**
     * Detectar datos de contacto en una conversación
     */
    public static function detect_contact_data($conversation) {
        $lead_data = [];
        $has_contact = false;
        
        if (!is_array($conversation)) {
            return ['has_lead' => false, 'data' => [], 'missing_fields' => ['name', 'email', 'phone', 'website']];
        }
        
        foreach ($conversation as $message) {
            if (!isset($message['role']) || $message['role'] !== 'user') {
                continue;
            }
            
            $content = $message['content'] ?? '';
            
            // Detectar emails
            if (!isset($lead_data['email']) && preg_match(self::$email_pattern, $content, $matches)) {
                $lead_data['email'] = sanitize_email($matches[0]);
                $has_contact = true;
            }
            
            // Detectar teléfonos
            if (!isset($lead_data['phone'])) {
                foreach (self::$phone_patterns as $pattern) {
                    if (preg_match($pattern, $content, $matches)) {
                        $phone = preg_replace('/[^\d+]/', '', $matches[0]);
                        if (strlen($phone) >= 9) {
                            $lead_data['phone'] = sanitize_text_field($matches[0]);
                            $has_contact = true;
                            break;
                        }
                    }
                }
            }
            
            // Detectar URLs/websites
            if (!isset($lead_data['website']) && preg_match(self::$url_pattern, $content, $matches)) {
                $url = $matches[0];
                // Añadir http:// si no tiene protocolo
                if (!preg_match('/^https?:\/\//', $url)) {
                    $url = 'http://' . $url;
                }
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    $lead_data['website'] = esc_url_raw($url);
                }
            }
            
            // Detectar nombre (heurística mejorada)
            if (!isset($lead_data['name'])) {
                $name = self::extract_name($content);
                if ($name) {
                    $lead_data['name'] = sanitize_text_field($name);
                }
            }
        }
        
        // Determinar campos faltantes
        $required_fields = ['name', 'email', 'phone', 'website'];
        $missing_fields = array_diff($required_fields, array_keys($lead_data));

        // Un lead se considera completo si tiene email o teléfono
        $is_complete_lead = isset($lead_data['email']) || isset($lead_data['phone']);
        
        return [
            'has_lead' => $has_contact,
            'is_complete' => $is_complete_lead,
            'data' => $lead_data,
            'missing_fields' => $missing_fields
        ];
    }
    
    /**
     * Extraer nombre de un mensaje (heurística mejorada)
     */
    private static function extract_name($content) {
        // Patrones comunes para detectar nombres
        $patterns = [
            '/(?:me llamo|soy|mi nombre es)\s+([A-ZÁÉÍÓÚÑ][a-záéíóúñ]+(?:\s+[A-ZÁÉÍÓÚÑ][a-záéíóúñ]+)?)/i',
            '/(?:my name is|i am|i\'m)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)/i',
            '/^([A-ZÁÉÍÓÚÑ][a-záéíóúñ]+(?:\s+[A-ZÁÉÍÓÚÑ][a-záéíóúñ]+)?)$/i', // Nombre solo
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                return trim($matches[1]);
            }
        }
        
        return null;
    }
    
    /**
     * Procesar datos de lead después de guardar conversación
     */
    public static function process_lead_data($log_id, $assistant_id, $conversation) {
        $lead_info = self::detect_contact_data($conversation);
        
        if ($lead_info['has_lead']) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'aicp_chat_logs';
            
            $lead_status = $lead_info['is_complete'] ? 'complete' : 'partial';
            
            $wpdb->update(
                $table_name,
                [
                    'has_lead' => 1,
                    'lead_data' => wp_json_encode($lead_info['data'], JSON_UNESCAPED_UNICODE),
                    'lead_status' => $lead_status
                ],
                ['id' => $log_id],
                ['%d', '%s', '%s'],
                ['%d']
            );

            $leads_table = $wpdb->prefix . 'aicp_leads';
            $status      = $lead_status;

            $wpdb->insert(
                $leads_table,
                [
                    'log_id'       => $log_id,
                    'assistant_id' => $assistant_id,
                    'email'        => $lead_info['data']['email'] ?? '',
                    'name'         => $lead_info['data']['name'] ?? '',
                    'phone'        => $lead_info['data']['phone'] ?? '',
                    'website'      => $lead_info['data']['website'] ?? '',
                    'lead_data'    => wp_json_encode($lead_info['data'], JSON_UNESCAPED_UNICODE),
                    'status'       => $status,
                    'created_at'   => current_time('mysql'),
                ],
                ['%d','%d','%s','%s','%s','%s','%s','%s']
            );

            // Hook para integraciones externas
            do_action('aicp_lead_detected', $lead_info['data'], $assistant_id, $log_id, $lead_status);
        }
    }

    /**
     * Enviar los datos del lead a la URL configurada.
     */
    public static function send_lead_to_webhook($lead_data, $assistant_id, $log_id, $lead_status) {
        $settings = get_post_meta($assistant_id, '_aicp_assistant_settings', true);
        $url = isset($settings['webhook_url']) ? esc_url_raw($settings['webhook_url']) : '';

        if (!$url) {
            $options = get_option('aicp_settings');
            $url = isset($options['lead_webhook_url']) ? esc_url_raw($options['lead_webhook_url']) : '';
        }

        if (!$url) {
            return;
        }

        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'lead_data'    => $lead_data,
                'assistant_id' => $assistant_id,
                'log_id'       => $log_id,
                'lead_status'  => $lead_status,
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            error_log('AICP Lead Webhook error: ' . $response->get_error_message());
        }
    }
    
    /**
     * Verificar estado del lead en tiempo real
     */
    public static function handle_check_lead_status() {
        if (!check_ajax_referer('aicp_chat_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Token de seguridad inválido.', 'ai-chatbot-pro')]);
        }
        
        $conversation = isset($_POST['conversation']) && is_array($_POST['conversation']) ? $_POST['conversation'] : [];
        $assistant_id = isset($_POST['assistant_id']) ? absint($_POST['assistant_id']) : 0;
        
        if (empty($conversation) || !$assistant_id) {
            wp_send_json_error(['message' => __('Datos inválidos.', 'ai-chatbot-pro')]);
        }
        
        // Verificar si el asistente tiene detección mejorada activada
        $settings = get_post_meta($assistant_id, '_aicp_assistant_settings', true);
        if (!($settings['enhanced_lead_detection'] ?? 0)) {
            wp_send_json_success(['enhanced_detection' => false]);
            return;
        }
        
        $lead_info = self::detect_contact_data($conversation);
        
        wp_send_json_success([
            'enhanced_detection' => true,
            'has_lead' => $lead_info['has_lead'],
            'is_complete' => $lead_info['is_complete'],
            'data' => $lead_info['data'],
            'missing_fields' => $lead_info['missing_fields'],
            'calendar_url' => $settings['calendar_url'] ?? ''
        ]);
    }
    
    /**
     * Manejar clic en enlace de calendario
     */
    public static function handle_calendar_lead() {
        // Verificar nonce
        if (!check_ajax_referer('aicp_calendar_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Token de seguridad inválido.', 'ai-chatbot-pro')]);
        }
        
        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        $assistant_id = isset($_POST['assistant_id']) ? absint($_POST['assistant_id']) : 0;
        
        if (!$log_id || !$assistant_id) {
            wp_send_json_error(['message' => __('Datos inválidos.', 'ai-chatbot-pro')]);
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'aicp_chat_logs';
        
        // Obtener datos actuales del lead
        $current_lead = $wpdb->get_row(
            $wpdb->prepare("SELECT lead_data, lead_status FROM $table_name WHERE id = %d", $log_id)
        );
        
        $lead_data = [];
        if ($current_lead && $current_lead->lead_data) {
            $lead_data = json_decode($current_lead->lead_data, true) ?: [];
        }
        
        // Marcar como lead de calendario
        $lead_data['calendar_clicked'] = true;
        $lead_data['calendar_timestamp'] = current_time('mysql');
        
        // Actualizar en base de datos
        $updated = $wpdb->update(
            $table_name,
            [
                'has_lead' => 1,
                'lead_data' => wp_json_encode($lead_data, JSON_UNESCAPED_UNICODE),
                'lead_status' => 'calendar_lead'
            ],
            ['id' => $log_id],
            ['%d', '%s', '%s'],
            ['%d']
        );
        
        if ($updated !== false) {
            // Hook para integraciones externas
            do_action('aicp_calendar_lead_detected', $lead_data, $assistant_id, $log_id);
            
            wp_send_json_success(['message' => __('Lead de calendario registrado.', 'ai-chatbot-pro')]);
        } else {
            wp_send_json_error(['message' => __('Error al registrar el lead.', 'ai-chatbot-pro')]);
        }
    }
    
    /**
     * Obtener estadísticas de leads para un asistente
     */
    public static function get_lead_stats($assistant_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aicp_chat_logs';
        
        $total_conversations = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM $table_name WHERE assistant_id = %d",
            $assistant_id
        ));
        
        $total_leads = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE assistant_id = %d AND has_lead = 1",
            $assistant_id
        ));
        
        $complete_leads = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE assistant_id = %d AND lead_status = 'complete'",
            $assistant_id
        ));
        
        $calendar_leads = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE assistant_id = %d AND lead_status = 'calendar_lead'",
            $assistant_id
        ));
        
        return [
            'total_conversations' => (int) $total_conversations,
            'total_leads' => (int) $total_leads,
            'complete_leads' => (int) $complete_leads,
            'calendar_leads' => (int) $calendar_leads,
            'conversion_rate' => $total_conversations > 0 ? round(($total_leads / $total_conversations) * 100, 2) : 0
        ];
    }
    
    /**
     * Generar mensaje para pedir datos faltantes
     */
    public static function get_missing_data_message($missing_fields) {
        $messages = [
            'name' => '¿Cómo te llamas?',
            'email' => '¿Cuál es tu correo electrónico?',
            'phone' => '¿Cuál es tu número de teléfono?',
            'website' => '¿Cuál es la web que quieres posicionar o promocionar?'
        ];
        
        if (empty($missing_fields)) {
            return '¡Perfecto! Ya tengo todos tus datos. ¿Te gustaría que te contactemos?';
        }
        
        $field = $missing_fields[0]; // Pedir el primer campo faltante
        return $messages[$field] ?? '¿Podrías proporcionarme más información?';
    }
}