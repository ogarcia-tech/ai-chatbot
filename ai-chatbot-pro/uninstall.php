<?php
/**
 * Fichero que se ejecuta al desinstalar el plugin.
 *
 * @package AI_Chatbot_Pro
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

// Eliminar opciones
delete_option('aicp_settings');
delete_option('aicp_db_version');

// Eliminar tabla de logs
global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}aicp_chat_logs");
