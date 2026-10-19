<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Páginas do tema feitas no Elementor GRÁTIS: "Início" e "Demonstração".
 * São criadas uma vez, ao ativar o tema, a partir de inc/elementor/*.json (seções, colunas, títulos, textos, botões,
 * imagens e acordeão do Elementor free). Depois disso o conteúdo é SEU: o tema nunca sobrescreve uma página que já tem
 * conteúdo do Elementor. O cabeçalho e o rodapé ficam em Aparência > Personalizar (Elementor free não edita cabeçalho/rodapé).
 */

function radar_tema_resolver(string $json): string
{
    $troca = [
        '{{whatsapp_exibido}}' => radar_whatsapp_exibido(),
        '{{whatsapp}}' => radar_whatsapp(),
    ];
    $json = str_replace(array_map('json_encode_str', array_keys($troca)), array_map('json_encode_str', array_values($troca)), $json);
    $json = preg_replace_callback('/\{\{radar:([^}]*)\}\}/', static fn ($m) => trim(json_encode(home_url('/radar/' . $m[1]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '"'), $json) ?? $json;
    $json = preg_replace_callback('/\{\{home:([^}]*)\}\}/', static fn ($m) => trim(json_encode(home_url($m[1]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '"'), $json) ?? $json;
    $json = preg_replace_callback('/\{\{theme:([^}]*)\}\}/', static fn ($m) => trim(json_encode(get_theme_file_uri($m[1]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '"'), $json) ?? $json;
    return $json;
}

/** Texto já escapado como string JSON (sem as aspas), para trocar dentro do JSON. */
function json_encode_str(string $s): string
{
    return trim((string) json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '"');
}

/** @param list<array<string,mixed>> $elementos */
function radar_tema_fallback(array $elementos): string
{
    $html = '';
    foreach ($elementos as $e) {
        $s = $e['settings'] ?? [];
        if (($e['elType'] ?? '') === 'widget') {
            $w = $e['widgetType'];
            if ($w === 'heading') {
                $tag = preg_match('/^(h[1-6]|p)$/', (string) ($s['header_size'] ?? 'h2')) ? $s['header_size'] : 'h2';
                $html .= "<$tag>" . wp_kses_post($s['title'] ?? '') . "</$tag>\n";
            } elseif ($w === 'text-editor') {
                $html .= wp_kses_post($s['editor'] ?? '') . "\n";
            } elseif ($w === 'button') {
                $html .= '<p><a href="' . esc_url($s['link']['url'] ?? '#') . '">' . esc_html($s['text'] ?? '') . "</a></p>\n";
            } elseif ($w === 'image') {
                $html .= '<p><img src="' . esc_url($s['image']['url'] ?? '') . '" alt="' . esc_attr($s['image']['alt'] ?? '') . '"></p>' . "\n";
            } elseif ($w === 'shortcode') {
                $html .= ($s['shortcode'] ?? '') . "\n";
            } elseif ($w === 'accordion') {
                foreach ($s['tabs'] ?? [] as $t) {
                    $html .= '<details><summary>' . esc_html($t['tab_title']) . '</summary>' . wp_kses_post($t['tab_content']) . "</details>\n";
                }
            }
        } else {
            $html .= radar_tema_fallback($e['elements'] ?? []);
        }
    }
    return $html;
}

/** Grava o conteúdo Elementor (arquivo JSON) em uma página. Retorna false se a página já tem conteúdo do Elementor e $forcar é falso. */
function radar_tema_importar(string $arquivo, int $post_id, bool $forcar = false): bool
{
    if (!$forcar && get_post_meta($post_id, '_elementor_data', true) !== '') {
        return false;
    }
    $caminho = get_template_directory() . '/inc/elementor/' . $arquivo;
    if (!is_file($caminho)) {
        return false;
    }
    $json = radar_tema_resolver((string) file_get_contents($caminho));
    $dados = json_decode($json, true);
    if (!is_array($dados)) {
        return false;
    }
    update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    update_post_meta($post_id, '_elementor_edit_mode', 'builder');
    update_post_meta($post_id, '_elementor_template_type', 'wp-page');
    update_post_meta($post_id, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.0.0');
    wp_update_post(['ID' => $post_id, 'post_content' => wp_slash(radar_tema_fallback($dados))]);       // sem Elementor, a página ainda aparece
    delete_post_meta($post_id, '_elementor_css');
    return true;
}

function radar_tema_pagina(string $slug, string $titulo): int
{
    $p = get_page_by_path($slug);
    if ($p) {
        return (int) $p->ID;
    }
    return (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $titulo, 'post_name' => $slug, 'post_content' => '']);
}

/** Cria (ou completa, se estiver vazia) as páginas do tema. Seguro para repetir. */
function radar_tema_garantir_paginas(): void
{
    $demo = radar_tema_pagina('demonstracao', 'Demonstração');
    radar_tema_importar('demo.json', $demo);

    $inicio = 0;
    if (get_option('show_on_front') === 'page' && (int) get_option('page_on_front') > 0) {
        $inicio = (int) get_option('page_on_front');          // o site já tem página inicial estática: usa ela se estiver vazia
        if (get_post_meta($inicio, '_elementor_data', true) === '' && trim((string) get_post_field('post_content', $inicio)) !== '') {
            $inicio = 0;                                       // tem conteúdo próprio: não mexe
        }
    }
    if ($inicio === 0 && get_option('show_on_front') !== 'page') {
        $inicio = radar_tema_pagina('inicio', 'Início');
        update_option('show_on_front', 'page');
        update_option('page_on_front', $inicio);
    }
    if ($inicio > 0) {
        radar_tema_importar('home.json', $inicio);
    }
    update_option('radar_tema_elementor_v', RADAR_TEMA_VERSAO, false);
}

add_action('after_switch_theme', 'radar_tema_garantir_paginas');
add_action('init', static function (): void {
    if (get_option('radar_tema_elementor_v') !== RADAR_TEMA_VERSAO) {
        radar_tema_garantir_paginas();
    }
}, 20);

// Aparência > Páginas do Radar: recria uma página do tema com o conteúdo original (apaga as edições dela).
add_action('admin_menu', static function (): void {
    add_theme_page('Páginas do Radar', 'Páginas do Radar', 'edit_theme_options', 'radar-paginas', static function (): void {
        $paginas = ['inicio' => ['Início', 'home.json'], 'demonstracao' => ['Demonstração', 'demo.json']];
        if (isset($_POST['radar_restaurar'], $paginas[$_POST['radar_restaurar']]) && check_admin_referer('radar_restaurar_pagina')) {
            [$titulo, $arq] = $paginas[$_POST['radar_restaurar']];
            $id = $_POST['radar_restaurar'] === 'inicio' ? (int) get_option('page_on_front') : radar_tema_pagina('demonstracao', $titulo);
            $id = $id > 0 ? $id : radar_tema_pagina('inicio', $titulo);
            radar_tema_importar($arq, $id, true);
            echo '<div class="notice notice-success"><p>Página "' . esc_html($titulo) . '" restaurada ao conteúdo original.</p></div>';
        }
        echo '<div class="wrap"><h1>Páginas do Radar</h1><p>As páginas do tema são editadas no <strong>Elementor</strong> (Páginas > Editar com o Elementor). Aqui você pode voltar uma página ao conteúdo original; <strong>isso apaga as suas edições nela</strong>.</p>';
        foreach ($paginas as $slug => [$titulo]) {
            echo '<form method="post" style="margin:12px 0" onsubmit="return confirm(\'Apagar as edições da página ' . esc_js($titulo) . ' e voltar ao original?\')">';
            wp_nonce_field('radar_restaurar_pagina');
            echo '<input type="hidden" name="radar_restaurar" value="' . esc_attr($slug) . '"><button class="button">Restaurar "' . esc_html($titulo) . '"</button></form>';
        }
        echo '<p>Cabeçalho, menu e rodapé: <a href="' . esc_url(admin_url('customize.php')) . '">Aparência > Personalizar</a>.</p></div>';
    });
});
