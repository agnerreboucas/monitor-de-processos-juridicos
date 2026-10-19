<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/demo-data.php';

/**
 * Shortcodes da demonstração (dados FICTÍCIOS, sempre com a data de hoje). Use no Elementor (widget "Shortcode"):
 *   [radar_demo_kpis]                                  os 5 indicadores do escritório modelo
 *   [radar_demo_tabela tipo="carteira|acoes|publicacoes" limite="4"]
 */
function radar_demo_pilula(string $cor, string $rotulo): string
{
    return '<span class="pilula ' . esc_attr($cor) . '">' . esc_html($rotulo) . '</span>';
}

add_shortcode('radar_demo_kpis', static function (): string {
    $k = radar_demo_data()['kpis'];
    $itens = [['', $k['monitorados'], 'processos monitorados'], ['', $k['atualizados'], 'atualizados hoje'], ['azul', $k['novidades'], 'com novidade'],
        ['amar', $k['urgentes'], 'ações com prazo em até 7 dias'], ['verm', $k['falhas'], 'sem acesso hoje (sistema do tribunal)']];
    $html = '<div class="demo"><div class="demo-kpis">';
    foreach ($itens as [$cor, $n, $rotulo]) {
        $html .= '<div class="kpi ' . esc_attr($cor) . '"><span class="n">' . (int) $n . '</span><span>' . esc_html($rotulo) . '</span></div>';
    }
    return $html . '</div></div>';
});

add_shortcode('radar_demo_tabela', static function ($atts): string {
    $a = shortcode_atts(['tipo' => 'carteira', 'limite' => 0], (array) $atts, 'radar_demo_tabela');
    $demo = radar_demo_data();
    $limite = (int) $a['limite'];
    $estados = ['novidade' => ['azul', 'Novidade'], 'ok' => ['verde', 'Atualizado'], 'falha' => ['verm', 'Sem acesso']];
    $impactos = ['atencao' => ['amar', 'Atenção'], 'desfavoravel' => ['verm', 'Desfavorável'], 'favoravel' => ['verde', 'Favorável'], 'neutro' => ['cinza', 'Neutro']];
    $urg = static function (?int $d): array {
        return $d === null ? ['cinza', 'Sem data certa'] : ($d <= 3 ? ['verm', 'Urgente · ' . $d . ' dias úteis'] : ($d <= 7 ? ['amar', 'Esta semana · ' . $d . ' dias'] : ['azul', 'Em ' . $d . ' dias']));
    };
    $linhas = '';
    switch ($a['tipo']) {
        case 'acoes':
            $cab = ['Urgência', 'O que fazer', 'Processo', 'Quem age', 'Prazo (estimado)', 'Impacto'];
            foreach (array_slice($demo['acoes'], 0, $limite > 0 ? $limite : null) as $x) {
                [$cu, $ru] = $urg($x['dias']);
                [$ci, $ri] = $impactos[$x['impacto']];
                $linhas .= '<tr><td>' . radar_demo_pilula($cu, $ru) . '</td><td><strong>' . esc_html($x['rotulo']) . '</strong></td><td class="mono">' . esc_html($x['proc']) . '</td><td>' . esc_html($x['quem']) . '</td><td>' . esc_html($x['prazo']) . '</td><td>' . radar_demo_pilula($ci, $ri) . '</td></tr>';
            }
            $rodape = '<p class="demo-rodape-tab">Prazos estimados pela leitura automática. Confira sempre o ato e a legislação: o aviso do Radar não é intimação.</p>';
            break;
        case 'publicacoes':
            $cab = ['Quando', 'Processo', 'Órgão', 'Resumo', 'Fonte'];
            foreach (array_slice($demo['pubs'], 0, $limite > 0 ? $limite : null) as $u) {
                $linhas .= '<tr><td>' . esc_html($u['quando']) . '</td><td class="mono">' . esc_html($u['proc']) . '</td><td>' . esc_html($u['orgao']) . '</td><td>' . esc_html($u['resumo']) . '</td><td>' . radar_demo_pilula('cinza', $u['fonte']) . '</td></tr>';
            }
            $rodape = '';
            break;
        default:
            $cab = ['Processo', 'Cliente', 'Área', 'Tribunal', 'Responsável', 'Última atualização', 'Situação'];
            foreach (array_slice($demo['carteira'], 0, $limite > 0 ? $limite : null) as $r) {
                [$cor, $rot] = $estados[$r['estado']];
                $linhas .= '<tr><td class="mono">' . esc_html($r['cnj']) . '</td><td>' . esc_html($r['cliente']) . '</td><td>' . esc_html($r['area']) . '</td><td>' . esc_html($r['orgao']) . '</td><td>' . esc_html($r['resp']) . '</td><td>' . esc_html($r['quando']) . '</td><td>' . radar_demo_pilula($cor, $rot) . '<span class="nota-linha">' . esc_html($r['nota']) . '</span></td></tr>';
            }
            $rodape = '';
    }
    $th = implode('', array_map(static fn ($c) => '<th>' . esc_html($c) . '</th>', $cab));
    return '<div class="demo"><div class="tabela"><table><thead><tr>' . $th . '</tr></thead><tbody>' . $linhas . '</tbody></table></div>' . $rodape . '</div>';
});
