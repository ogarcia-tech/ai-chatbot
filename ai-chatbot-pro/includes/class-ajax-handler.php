<?php
/**
 * Clase que maneja todas las peticiones AJAX del plugin.
 *
 * @package AI_Chatbot_Pro
 */
if (!defined('ABSPATH')) exit;

class AICP_Ajax_Handler {

    public static function init() {
        add_action('wp_ajax_aicp_chat_request', [__CLASS__, 'handle_chat_request']);
        add_action('wp_ajax_nopriv_aicp_chat_request', [__CLASS__, 'handle_chat_request']);
        add_action('wp_ajax_aicp_delete_log', [__CLASS__, 'handle_delete_log']);
        add_action('wp_ajax_aicp_get_log_details', [__CLASS__, 'handle_get_log_details']);
        add_action('wp_ajax_nopriv_aicp_submit_feedback', [__CLASS__, 'handle_submit_feedback']);
        add_action('wp_ajax_aicp_submit_feedback', [__CLASS__, 'handle_submit_feedback']);
        add_action('wp_ajax_aicp_manual_capture_lead', [__CLASS__, 'handle_manual_capture_lead']);
        add_action('wp_ajax_aicp_submit_lead_form', [__CLASS__, 'handle_submit_lead_form']);
        add_action('wp_ajax_nopriv_aicp_submit_lead_form', [__CLASS__, 'handle_submit_lead_form']);
    }
    
    private static function save_conversation($log_id, $assistant_id, $session_id, $conversation, $lead_data = []) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aicp_chat_logs';
        
        $first_user_message = '';
        if ($log_id === 0) {
            foreach($conversation as $message) {
                if($message['role'] === 'user') {
                    $first_user_message = $message['content'];
                    break;
                }
            }
        }
        
        $data = [
            'assistant_id'     => $assistant_id,
            'session_id'       => $session_id,
            'timestamp'        => current_time('mysql'),
            'conversation_log' => json_encode($conversation, JSON_UNESCAPED_UNICODE)
        ];
        $format = ['%d', '%s', '%s', '%s'];
        
        if ($first_user_message) {
            $data['first_user_message'] = $first_user_message;
            $format[] = '%s';
        }

        if (!empty($lead_data)) {
            $data['has_lead'] = 1;
            $data['lead_data'] = json_encode($lead_data, JSON_UNESCAPED_UNICODE);
            $format[] = '%d';
            $format[] = '%s';
        }


        if ( $log_id > 0 ) {
            $wpdb->update( $table_name, $data, [ 'id' => $log_id ], $format, ['%d'] );
            do_action( 'aicp_conversation_saved', $log_id, $assistant_id, $conversation );
        } else {
            $wpdb->insert( $table_name, $data, $format );
            $log_id = $wpdb->insert_id;
            do_action( 'aicp_conversation_saved', $log_id, $assistant_id, $conversation );

        }

        return $log_id;
    }

    public static function handle_chat_request() {
        check_ajax_referer('aicp_chat_nonce', 'nonce');
        $assistant_id = isset($_POST['assistant_id']) ? absint($_POST['assistant_id']) : 0;
        $history = isset($_POST['history']) && is_array($_POST['history']) ? wp_unslash($_POST['history']) : [];
        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;

        if (empty($assistant_id) || empty($history)) { wp_send_json_error(['message' => __('Datos inválidos.', 'ai-chatbot-pro')]); }

        $global_settings = get_option('aicp_settings');
        $s = get_post_meta($assistant_id, '_aicp_assistant_settings', true);
        if (!is_array($s)) { $s = []; }

        $api_key = $global_settings['api_key'] ?? '';
        if (empty($api_key)) { wp_send_json_error(['message' => __('La API Key de OpenAI no está configurada.', 'ai-chatbot-pro')]); }
        
        // ¡LÓGICA SIMPLIFICADA! Solo usa las instrucciones básicas.
        $system_prompt_parts = [];
        if (!empty($s['persona'])) $system_prompt_parts[] = "PERSONALIDAD: " . $s['persona'];
        if (!empty($s['objective'])) $system_prompt_parts[] = "OBJETIVO PRINCIPAL: " . $s['objective'];
        if (!empty($s['length_tone'])) $system_prompt_parts[] = "TONO Y LONGITUD: " . $s['length_tone'];
        if (!empty($s['example'])) $system_prompt_parts[] = "EJEMPLO DE RESPUESTA: " . $s['example'];
        
        $system_prompt = implode("\n\n", $system_prompt_parts);
        if(empty($system_prompt)) $system_prompt = 'Eres un asistente de IA.';
        
        $short_term_memory = array_slice($history, -4);
        $conversation = [['role' => 'system', 'content' => $system_prompt]];
        foreach ($short_term_memory as $item) { if (isset($item['role'], $item['content'])) { $conversation[] = ['role' => sanitize_key($item['role']), 'content' => sanitize_textarea_field($item['content'])]; } }
        
        $api_url = 'https://api.openai.com/v1/chat/completions';
        $api_args = [ 'method'  => 'POST', 'headers' => ['Content-Type'  => 'application/json', 'Authorization' => 'Bearer ' . $api_key], 'body'    => json_encode(['model' => $s['model'] ?? 'gpt-4o', 'messages' => $conversation]), 'timeout' => 60, ];
        $response = wp_remote_post($api_url, $api_args);

        if (is_wp_error($response)) { wp_send_json_error(['message' => $response->get_error_message()]); }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['choices'][0]['message']['content'])) {
            $reply = $data['choices'][0]['message']['content'];
            
            $full_history = $history;
            $full_history[] = ['role' => 'assistant', 'content' => $reply];
            $session_id = session_id() ?: uniqid('aicp_');
            $new_log_id = self::save_conversation($log_id, $assistant_id, $session_id, $full_history);

            wp_send_json_success(['reply' => trim($reply), 'log_id' => $new_log_id]);
        } else {
            wp_send_json_error(['message' => $data['error']['message'] ?? __('Respuesta inesperada.', 'ai-chatbot-pro')]);
        }
    }
    
    public static function handle_delete_log() {
        check_ajax_referer('aicp_delete_log_nonce', 'nonce');
        if (!current_user_can('edit_posts')) { wp_send_json_error(['message' => __('No tienes permisos.', 'ai-chatbot-pro')]); }
        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        if (!$log_id) { wp_send_json_error(['message' => __('ID de log inválido.', 'ai-chatbot-pro')]); }
        global $wpdb;
        $deleted = $wpdb->delete($wpdb->prefix . 'aicp_chat_logs', ['id' => $log_id], ['%d']);
        if ($deleted) { wp_send_json_success(); } else { wp_send_json_error(['message' => __('No se pudo borrar el registro.', 'ai-chatbot-pro')]); }
    }

    public static function handle_get_log_details() {
        check_ajax_referer('aicp_get_log_nonce', 'nonce');
        if (!current_user_can('edit_posts')) { wp_send_json_error(['message' => __('No tienes permisos.', 'ai-chatbot-pro')]); }

        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        if (!$log_id) { wp_send_json_error(['message' => __('ID de log inválido.', 'ai-chatbot-pro')]); }

        global $wpdb;
        $log = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}aicp_chat_logs WHERE id = %d", $log_id));

        if (!$log) {
            wp_send_json_error(['message' => __('Log no encontrado.', 'ai-chatbot-pro')]);
        }

        wp_send_json_success([
            'conversation' => json_decode($log->conversation_log, true),
            'lead_data' => json_decode($log->lead_data, true),
            'has_lead' => (bool)$log->has_lead
        ]);
    }

    public static function handle_manual_capture_lead() {
        check_ajax_referer('aicp_capture_lead_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('No tienes permisos.', 'ai-chatbot-pro')]);
        }

        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        if (!$log_id) {
            wp_send_json_error(['message' => __('ID de log inválido.', 'ai-chatbot-pro')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'aicp_chat_logs';
        $log = $wpdb->get_row($wpdb->prepare("SELECT conversation_log, assistant_id FROM $table WHERE id = %d", $log_id));

        if (!$log) {
            wp_send_json_error(['message' => __('Log no encontrado.', 'ai-chatbot-pro')]);
        }

        $conversation = json_decode($log->conversation_log, true);
        if (!class_exists('AICP_Lead_Manager')) {
            wp_send_json_error(['message' => __('Función no disponible.', 'ai-chatbot-pro')]);
        }

        $lead_info = AICP_Lead_Manager::detect_contact_data($conversation);

        if (!$lead_info['has_lead']) {
            wp_send_json_error(['message' => __('No se detectó información de contacto.', 'ai-chatbot-pro')]);
        }

        $lead_status = $lead_info['is_complete'] ? 'complete' : 'partial';

        $wpdb->update(
            $table,
            [
                'has_lead'   => 1,
                'lead_data'  => wp_json_encode($lead_info['data'], JSON_UNESCAPED_UNICODE),
                'lead_status'=> $lead_status
            ],
            ['id' => $log_id],
            ['%d','%s','%s'],
            ['%d']
        );

        $leads_table = $wpdb->prefix . 'aicp_leads';
        $wpdb->insert(
            $leads_table,
            [
                'log_id'       => $log_id,
                'assistant_id' => $log->assistant_id,
                'email'        => $lead_info['data']['email'] ?? '',
                'name'         => $lead_info['data']['name'] ?? '',
                'phone'        => $lead_info['data']['phone'] ?? '',
                'website'      => $lead_info['data']['website'] ?? '',
                'lead_data'    => wp_json_encode($lead_info['data'], JSON_UNESCAPED_UNICODE),
                'status'       => $lead_status,
                'created_at'   => current_time('mysql')
            ],
            ['%d','%d','%s','%s','%s','%s','%s','%s']
        );

        do_action('aicp_lead_detected', $lead_info['data'], $log->assistant_id, $log_id, $lead_status);

        wp_send_json_success(['lead' => $lead_info['data']]);
    }

    public static function handle_submit_feedback() {
        check_ajax_referer('aicp_feedback_nonce', 'nonce');
        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        $feedback = isset($_POST['feedback']) ? intval($_POST['feedback']) : 0;

        if (!$log_id || !in_array($feedback, [1, -1])) {
            wp_send_json_error(['message' => 'Datos inválidos.']);
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'aicp_chat_logs';
        $updated = $wpdb->update($table_name, ['feedback' => $feedback], ['id' => $log_id], ['%d'], ['%d']);

        if ($updated !== false) {
            wp_send_json_success();
        } else {
            wp_send_json_error(['message' => 'No se pudo guardar el feedback.']);
        }
    }

    public static function handle_submit_lead_form() {
        check_ajax_referer('aicp_chat_nonce', 'nonce');
        $assistant_id = isset($_POST['assistant_id']) ? absint($_POST['assistant_id']) : 0;
        $answers = isset($_POST['answers']) && is_array($_POST['answers']) ? array_map('sanitize_text_field', $_POST['answers']) : [];

        if (!$assistant_id || empty($answers)) {
            wp_send_json_error(['message' => __('Datos incompletos.', 'ai-chatbot-pro')]);
        }

        global $wpdb;
        $leads_table = $wpdb->prefix . 'aicp_leads';
        $wpdb->insert(
            $leads_table,
            [
                'log_id'       => 0,
                'assistant_id' => $assistant_id,
                'lead_data'    => wp_json_encode($answers, JSON_UNESCAPED_UNICODE),
                'status'       => 'form',
                'created_at'   => current_time('mysql'),
            ],
            ['%d','%d','%s','%s','%s']
        );

        do_action('aicp_lead_detected', $answers, $assistant_id, 0, 'form');

        wp_send_json_success(['message' => __('Formulario enviado', 'ai-chatbot-pro')]);
    }
}
