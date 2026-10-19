<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Endereço de uma tela do Radar (plugin): radar_link('entrar'), radar_link('entrar#/cadastro?plano=profissional'). */
function radar_link(string $path = ''): string
{
    return esc_url(home_url('/radar/' . ltrim($path, '/')));
}

// WhatsApp do atendimento comercial (consultor). Para trocar o número, mude as duas linhas abaixo.
const RADAR_WHATSAPP = '5511958649509';
const RADAR_WHATSAPP_EXIBIDO = '(11) 95864-9509';

function radar_whatsapp(string $mensagem = 'Olá! Quero falar com um consultor sobre o Radar Processual.'): string
{
    return 'https://wa.me/' . RADAR_WHATSAPP . '?text=' . rawurlencode($mensagem);
}

function radar_icone_zap(): string
{
    return '<svg class="ic-zap" viewBox="0 0 24 24" aria-hidden="true" width="20" height="20"><path fill="currentColor" d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1-.8-.4-1.6-.9-2.3-1.7-.5-.6-.9-1.2-1-1.4-.1-.2 0-.3.1-.5l.4-.5.2-.4v-.4l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.4.1-.7.3-.2.3-.9.9-.9 2.2s.9 2.5 1 2.7c.1.2 1.8 2.8 4.4 3.9 1.6.7 2.2.7 3 .6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3z"/></svg>';
}

add_action('after_setup_theme', static function (): void {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);
});

add_action('wp_enqueue_scripts', static function (): void {
    $dir = get_template_directory();
    wp_enqueue_style('radar-inicio', get_theme_file_uri('css/inicio.css'), [], (string) @filemtime($dir . '/css/inicio.css'));
    wp_enqueue_style('radar-tema', get_stylesheet_uri(), ['radar-inicio'], (string) @filemtime($dir . '/style.css'));
});

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

// Cria a página "Demonstração" (escritório modelo, dados fictícios) ao ativar o tema, se ainda não existir.
function radar_criar_pagina_demo(): void
{
    if (get_page_by_path('demonstracao')) {
        return;
    }
    wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Demonstração', 'post_name' => 'demonstracao', 'post_content' => '']);
}
add_action('after_switch_theme', 'radar_criar_pagina_demo');
add_action('init', static function (): void {
    if (get_option('radar_tema_demo_criada') !== '1') {          // tema já ativo antes desta versão: cria uma vez
        radar_criar_pagina_demo();
        update_option('radar_tema_demo_criada', '1', false);
    }
});
