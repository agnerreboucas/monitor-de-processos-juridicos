<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
$settings = (array) get_option('radar_wp_settings', []);
wp_clear_scheduled_hook('radar_wp_tick');
if (!empty($settings['delete_on_uninstall'])) {         // por padrão os dados dos clientes ficam guardados
    global $wpdb;
    $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach ((array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'radar_') . '%')) as $table) {
        $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($table) . '`');
    }
    $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
    delete_option('radar_wp_settings');
    delete_option('radar_wp_db_version');
    delete_option('radar_wp_last_tick');
}
