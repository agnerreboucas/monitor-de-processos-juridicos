<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Endereço de uma tela do Radar (plugin): radar_link('entrar'), radar_link('entrar#/cadastro?plano=profissional'). */
function radar_link(string $path = ''): string
{
    return esc_url(home_url('/radar/' . ltrim($path, '/')));
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
