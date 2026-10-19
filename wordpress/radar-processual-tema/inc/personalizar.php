<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cabeçalho e rodapé não são editáveis no Elementor grátis (não há Theme Builder). Aqui eles ficam em
 * Aparência > Personalizar: menus (Menus), textos e WhatsApp.
 */
function radar_topo_texto(): string
{
    return (string) get_theme_mod('radar_barra_topo', 'Teste grátis por 14 dias · sem cartão de crédito · cadastro em um minuto');
}

function radar_rodape_texto(): string
{
    return (string) get_theme_mod('radar_rodape_texto', '© ' . gmdate('Y') . ' Radar Processual · Apoio operacional de acompanhamento processual.');
}

/** Menu padrão do topo (usado enquanto você não monta um em Aparência > Menus). */
function radar_menu_padrao_topo(): void
{
    $itens = ['#como' => 'Como funciona', '#fontes' => 'Fontes', '#recursos' => 'Recursos', '#tribunais' => 'Cobertura', '#planos' => 'Preço', '#perguntas' => 'Perguntas'];
    echo '<ul class="menu-topo">';
    foreach ($itens as $ancora => $rotulo) {
        echo '<li><a href="' . esc_url(home_url('/' . $ancora)) . '">' . esc_html($rotulo) . '</a></li>';
    }
    echo '<li><a href="' . esc_url(home_url('/demonstracao/')) . '">Demonstração</a></li></ul>';
}

function radar_menu_padrao_rodape(): void
{
    echo '<ul class="menu-rodape">'
        . '<li><a href="' . radar_link('app/termos.html') . '">Termos de Uso</a></li>'
        . '<li><a href="' . radar_link('app/privacidade.html') . '">Privacidade</a></li>'
        . '<li><a href="' . esc_url(home_url('/demonstracao/')) . '">Demonstração</a></li>'
        . '<li><a href="' . radar_link('app/api-docs.html') . '">API</a></li>'
        . '<li><a href="' . esc_url(radar_whatsapp()) . '" target="_blank" rel="noopener">WhatsApp</a></li>'
        . '<li><a href="' . radar_link('entrar') . '">Entrar</a></li></ul>';
}

add_action('customize_register', static function (WP_Customize_Manager $wp): void {
    $wp->add_section('radar_whatsapp_sec', ['title' => 'Radar: WhatsApp, faixa do topo e rodapé', 'priority' => 30]);
    $campos = [
        'radar_whatsapp' => ['Número do WhatsApp do botão flutuante e do rodapé (com 55 e DDD)', RADAR_WHATSAPP, 'sanitize_text_field'],
        'radar_whatsapp_exibido' => ['Número como aparece na tela', RADAR_WHATSAPP_EXIBIDO, 'sanitize_text_field'],
        'radar_whatsapp_mensagem' => ['Mensagem que já vem escrita no WhatsApp', RADAR_WHATSAPP_MENSAGEM, 'sanitize_text_field'],
        'radar_barra_topo' => ['Faixa amarela do topo (deixe vazio para esconder)', radar_topo_texto(), 'sanitize_text_field'],
        'radar_rodape_texto' => ['Texto do rodapé', radar_rodape_texto(), 'wp_kses_post'],
    ];
    foreach ($campos as $id => [$rotulo, $padrao, $limpa]) {
        $wp->add_setting($id, ['default' => $padrao, 'sanitize_callback' => $limpa]);
        $wp->add_control($id, ['label' => $rotulo, 'section' => 'radar_whatsapp_sec', 'type' => 'text']);
    }
});
