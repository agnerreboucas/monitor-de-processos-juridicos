<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Endereço de uma tela do Radar (plugin): radar_link('entrar'), radar_link('entrar#/cadastro?plano=profissional'). */
function radar_link(string $path = ''): string
{
    return esc_url(home_url('/radar/' . ltrim($path, '/')));
}

const RADAR_TEMA_VERSAO = '2';

// WhatsApp do atendimento comercial (consultor). Valores padrão; mude em Aparência > Personalizar > WhatsApp.
const RADAR_WHATSAPP = '5511958649509';
const RADAR_WHATSAPP_EXIBIDO = '(11) 95864-9509';
const RADAR_WHATSAPP_MENSAGEM = 'Olá! Quero falar com um consultor sobre o Radar Processual.';

function radar_whatsapp_numero(): string
{
    $n = preg_replace('/\D+/', '', (string) get_theme_mod('radar_whatsapp', RADAR_WHATSAPP)) ?? '';
    return $n !== '' ? $n : RADAR_WHATSAPP;
}

function radar_whatsapp_exibido(): string
{
    $t = trim((string) get_theme_mod('radar_whatsapp_exibido', RADAR_WHATSAPP_EXIBIDO));
    return $t !== '' ? $t : RADAR_WHATSAPP_EXIBIDO;
}

function radar_whatsapp(string $mensagem = ''): string
{
    if ($mensagem === '') {
        $mensagem = trim((string) get_theme_mod('radar_whatsapp_mensagem', RADAR_WHATSAPP_MENSAGEM)) ?: RADAR_WHATSAPP_MENSAGEM;
    }
    return 'https://wa.me/' . radar_whatsapp_numero() . '?text=' . rawurlencode($mensagem);
}

function radar_icone_zap(): string
{
    return '<svg class="ic-zap" viewBox="0 0 24 24" aria-hidden="true" width="20" height="20"><path fill="currentColor" d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1-.8-.4-1.6-.9-2.3-1.7-.5-.6-.9-1.2-1-1.4-.1-.2 0-.3.1-.5l.4-.5.2-.4v-.4l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.4.1-.7.3-.2.3-.9.9-.9 2.2s.9 2.5 1 2.7c.1.2 1.8 2.8 4.4 3.9 1.6.7 2.2.7 3 .6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3z"/></svg>';
}

add_action('after_setup_theme', static function (): void {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);
    register_nav_menus(['principal' => 'Menu do topo', 'rodape' => 'Menu do rodapé']);
});

add_action('wp_enqueue_scripts', static function (): void {
    $dir = get_template_directory();
    wp_enqueue_style('radar-inicio', get_theme_file_uri('css/inicio.css'), [], (string) @filemtime($dir . '/css/inicio.css'));
    wp_enqueue_style('radar-elementor', get_theme_file_uri('css/elementor.css'), ['radar-inicio'], (string) @filemtime($dir . '/css/elementor.css'));
    wp_enqueue_style('radar-tema', get_stylesheet_uri(), ['radar-elementor'], (string) @filemtime($dir . '/style.css'));
});

require_once __DIR__ . '/inc/demo-shortcodes.php';
require_once __DIR__ . '/inc/elementor-paginas.php';
require_once __DIR__ . '/inc/personalizar.php';

// O kit padrão do Elementor pede a fonte Roboto ao Google; o tema usa a fonte do sistema (mais rápido e sem enviar o IP do visitante).
add_filter('elementor/frontend/print_google_fonts', '__return_false');

add_action('wp_head', static function (): void {
    if (is_front_page()) {
        echo '<meta name="description" content="Monitore processos de qualquer área do Direito e as publicações em nome dos seus advogados. Andamento completo, ações e prazos por urgência, avisos por painel e e-mail. 14 dias grátis.">' . "\n";
    }
});

// Sem o plugin, os botões Entrar e Testar grátis não levam a lugar nenhum: avisa quem administra.
add_action('admin_notices', static function (): void {
    if (current_user_can('activate_plugins') && !class_exists('Radar_WP_Plugin')) {
        echo '<div class="notice notice-warning"><p><strong>Radar Processual (tema):</strong> ative também o plugin <em>Radar Processual</em>. É ele que traz o painel, as buscas e a API; sem ele os botões Entrar e Testar grátis não funcionam.</p></div>';
    }
});

