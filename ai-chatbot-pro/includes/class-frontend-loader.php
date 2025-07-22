<?php
/**
 * Clase que gestiona la carga del asistente de chat en el frontend.
 * Incluye funcionalidades de detección de leads y calendario.
 *
 * @package AI_Chatbot_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class AICP_Frontend_Loader {
    
    public static function init() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_footer', [__CLASS__, 'add_chatbot_container']);
        
        // AJAX handlers para funcionalidades de lead
        add_action('wp_ajax_aicp_save_lead', [__CLASS__, 'handle_save_lead']);
        add_action('wp_ajax_nopriv_aicp_save_lead', [__CLASS__, 'handle_save_lead']);
    }

    private static function get_active_assistant() {
        // Primero, busca un asistente con reglas específicas para la página actual.
        $args = [
            'post_type' => 'aicp_assistant', 
            'posts_per_page' => -1, 
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => '_aicp_assistant_settings', 
                'value' => '"is_active";s:1:"1"', 
                'compare' => 'LIKE'
            ]]
        ];
        $active_assistants = get_posts($args);

        if (empty($active_assistants)) {
            return null; // No hay asistentes activos.
        }
        
        // Prioridad 1: Reglas específicas
        foreach ($active_assistants as $assistant) {
            $settings = get_post_meta($assistant->ID, '_aicp_assistant_settings', true);
            $rule_type = $settings['rule_type'] ?? 'everywhere';
            
            if ($rule_type !== 'everywhere') {
                $rule_content = $settings['rule_content'] ?? '';
                $ids = array_map('trim', explode(',', $rule_content));
                if (!empty($ids)) {
                    if (($rule_type === 'specific_pages' && is_page($ids)) || 
                        ($rule_type === 'specific_posts' && is_single($ids))) {
                        return $assistant; // Encontrado un asistente específico, lo devolvemos.
                    }
                }
            }
        }

        // Prioridad 2: Regla global "En todo el sitio"
        foreach ($active_assistants as $assistant) {
            $settings = get_post_meta($assistant->ID, '_aicp_assistant_settings', true);
            if (($settings['rule_type'] ?? 'everywhere') === 'everywhere') {
                return $assistant; // Devolvemos el primer asistente global encontrado.
            }
        }
        
        return null; // No se encontró ningún asistente aplicable.
    }

    public static function enqueue_assets() {
        $assistant = self::get_active_assistant();
        if (!$assistant) return;

        $s = get_post_meta($assistant->ID, '_aicp_assistant_settings', true);
        if (empty($s) || !is_array($s)) return;

        wp_enqueue_style('aicp-chatbot-style', AICP_PLUGIN_URL . 'assets/css/chatbot.css', [], AICP_VERSION);
        wp_enqueue_script('aicp-chatbot-script', AICP_PLUGIN_URL . 'assets/js/chatbot.js', ['jquery'], AICP_VERSION, true);

        // Lógica de avatares
        $default_bot_avatar = AICP_PLUGIN_URL . 'assets/bot-default-avatar.png';
        $default_user_avatar = AICP_PLUGIN_URL . 'assets/user-default-avatar.png';

        $bot_avatar = !empty($s['bot_avatar_url']) ? esc_url($s['bot_avatar_url']) : $default_bot_avatar;
        $user_avatar = !empty($s['user_avatar_url']) ? esc_url($s['user_avatar_url']) : $default_user_avatar;

        if (is_user_logged_in()) {
            $user_avatar = get_avatar_url(get_current_user_id(), ['size' => 96]);
        }

        // Obtener mensajes sugeridos
        $suggested_messages = [];
        if (!empty($s['suggested_messages'])) {
            $suggested_messages = array_filter(array_map('trim', explode("\n", $s['suggested_messages'])));
        }

        // Obtener configuración de detección de leads
        $lead_auto_collect  = !empty($s['lead_auto_collect']) ? true : false;
        $lead_prompt_messages = $s['lead_prompts'] ?? [];

        wp_localize_script('aicp-chatbot-script', 'aicp_chatbot_params', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aicp_chat_nonce'),
            'feedback_nonce' => wp_create_nonce('aicp_feedback_nonce'),
            'calendar_nonce' => wp_create_nonce('aicp_calendar_nonce'),
            'assistant_id' => $assistant->ID,
            'header_title' => esc_html(get_the_title($assistant->ID)),
            'bot_avatar' => $bot_avatar,
            'user_avatar' => $user_avatar,
            'position' => $s['position'] ?? 'br',
            'open_icon' => !empty($s['open_icon_url']) ? esc_url($s['open_icon_url']) : $default_bot_avatar,
            'suggested_messages' => $suggested_messages,
            'lead_auto_collect'  => $lead_auto_collect,
            'lead_prompt_messages' => $lead_prompt_messages,
        ]);
    }

    public static function add_chatbot_container() {
        if (wp_script_is('aicp-chatbot-script', 'enqueued')) {
            echo '<div id="aicp-chatbot-container"></div>';
        }
    }

    /**
     * Maneja el guardado de leads detectados
     */
    public static function handle_save_lead() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'aicp_chat_nonce')) {
            wp_die('Nonce verification failed');
        }

        $log_id = intval($_POST['log_id']);
        $assistant_id = intval($_POST['assistant_id']);
        $lead_data = $_POST['lead_data'];

        if (!$log_id || !$assistant_id || !$lead_data) {
            wp_send_json_error(['message' => 'Datos incompletos']);
        }

        // Sanitizar datos del lead
        $sanitized_lead_data = [
            'email' => sanitize_email($lead_data['email'] ?? ''),
            'name' => sanitize_text_field($lead_data['name'] ?? ''),
            'phone' => sanitize_text_field($lead_data['phone'] ?? ''),
            'website' => esc_url_raw($lead_data['website'] ?? ''),
            'is_complete' => !empty($lead_data['isComplete']),
            'collected_at' => current_time('mysql'),
            'source' => 'chatbot_detection'
        ];

        // Guardar datos en la tabla de logs
        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->prefix . 'aicp_chat_logs',
            [
                'has_lead'    => 1,
                'lead_data'   => wp_json_encode($sanitized_lead_data, JSON_UNESCAPED_UNICODE),
                'lead_status' => $sanitized_lead_data['is_complete'] ? 'complete' : 'partial'
            ],
            ['id' => $log_id],
            ['%d', '%s', '%s'],
            ['%d']
        );
        if ($updated !== false) {
            $leads_table = $wpdb->prefix . 'aicp_leads';
            $status      = $sanitized_lead_data['is_complete'] ? 'complete' : 'partial';

            $wpdb->insert(
                $leads_table,
                [
                    'log_id'       => $log_id,
                    'assistant_id' => $assistant_id,
                    'email'        => $sanitized_lead_data['email'] ?? '',
                    'name'         => $sanitized_lead_data['name'] ?? '',
                    'phone'        => $sanitized_lead_data['phone'] ?? '',
                    'website'      => $sanitized_lead_data['website'] ?? '',
                    'lead_data'    => wp_json_encode($sanitized_lead_data, JSON_UNESCAPED_UNICODE),
                    'status'       => $status,
                    'created_at'   => current_time('mysql'),
                ],
                ['%d','%d','%s','%s','%s','%s','%s','%s']
            );

            do_action('aicp_lead_detected', $sanitized_lead_data, $assistant_id, $log_id, $status);

            wp_send_json_success(['message' => 'Lead guardado correctamente']);
        } else {
            wp_send_json_error(['message' => 'Error al guardar el lead']);
        }
    }

}
