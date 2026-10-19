<?php
/**
 * Plugin Name:       Radar Processual
 * Description:       Monitoramento processual para escritórios de advocacia dentro do WordPress: painel, buscas automáticas, avisos por e-mail, API por chave e cobrança por plano. Usa o tema "Radar Processual" para o site de venda.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            Radar Processual
 * License:           Proprietary
 * Text Domain:       radar-processual
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RADAR_WP_VERSION', '1.0.0');
define('RADAR_WP_FILE', __FILE__);
define('RADAR_WP_DIR', plugin_dir_path(__FILE__));

// O núcleo do Radar (src, migrations e telas) fica em radar/ e é carregado sem Composer.
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Radar\\', 6) !== 0) {
        return;
    }
    $file = RADAR_WP_DIR . 'radar/src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once RADAR_WP_DIR . 'includes/class-radar-wp-mailer.php';
require_once RADAR_WP_DIR . 'includes/class-radar-wp-plugin.php';
require_once RADAR_WP_DIR . 'includes/class-radar-wp-admin.php';

register_activation_hook(__FILE__, ['Radar_WP_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['Radar_WP_Plugin', 'deactivate']);

Radar_WP_Plugin::boot();
Radar_WP_Admin::boot();
