<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dados FICTÍCIOS da página de demonstração (escritório modelo). Nada aqui é processo, parte ou advogado real.
 * As datas são relativas a hoje, então a demonstração sempre aparece atual. Para trocar nomes ou linhas, edite este arquivo.
 */

/** Número CNJ com dígito verificador correto (NNNNNNN-DD.AAAA.J.TR.OOOO). */
function radar_demo_cnj(int $seq, int $ano, int $j, int $tr, int $origem): string
{
    $base = sprintf('%07d%04d%d%02d%04d', $seq, $ano, $j, $tr, $origem);
    $resto = 0;
    foreach (str_split($base . '00') as $d) {
        $resto = ($resto * 10 + (int) $d) % 97;
    }
    return sprintf('%07d-%02d.%04d.%d.%02d.%04d', $seq, 98 - $resto, $ano, $j, $tr, $origem);
}

/** Soma dias úteis (sem sábado e domingo; a demonstração não considera feriados). */
function radar_demo_uteis(int $ts, int $n): int
{
    while ($n > 0) {
        $ts += DAY_IN_SECONDS;
        if ((int) wp_date('N', $ts) < 6) {
            $n--;
        }
    }
    return $ts;
}

/** @return array<string,mixed> */
function radar_demo_data(): array
{
    $hoje = (int) current_time('timestamp');
    $d = static fn (int $ts): string => wp_date('d/m/Y', $ts);
    $ano = (int) wp_date('Y', $hoje);
    $hora = '05:30';

    $carteira = [
        ['cnj' => radar_demo_cnj(1000211, $ano - 1, 5, 2, 31), 'cliente' => 'Transportes Alvorada Ltda.', 'area' => 'Trabalhista', 'orgao' => 'TRT2 · Vara do Trabalho', 'resp' => 'Dra. Helena', 'quando' => "hoje $hora", 'estado' => 'novidade', 'nota' => 'Audiência designada'],
        ['cnj' => radar_demo_cnj(2000344, $ano - 1, 5, 8, 106), 'cliente' => 'Rede Norte Serviços Ltda.', 'area' => 'Trabalhista', 'orgao' => 'TRT8 · Vara do Trabalho', 'resp' => 'Dr. Caio', 'quando' => 'ontem ' . $hora, 'estado' => 'falha', 'nota' => 'Sistema do tribunal fora do ar'],
        ['cnj' => radar_demo_cnj(5001872, $ano, 8, 26, 100), 'cliente' => 'Maria das Dores Lima', 'area' => 'Cível', 'orgao' => 'TJSP · 3ª Vara Cível', 'resp' => 'Dra. Helena', 'quando' => "hoje $hora", 'estado' => 'novidade', 'nota' => 'Laudo pericial juntado'],
        ['cnj' => radar_demo_cnj(4003390, $ano - 1, 8, 13, 24), 'cliente' => 'Cláudio Mendes', 'area' => 'Família', 'orgao' => 'TJMG · Vara de Família', 'resp' => 'Dra. Marta', 'quando' => "hoje $hora", 'estado' => 'ok', 'nota' => 'Sem novidade'],
        ['cnj' => radar_demo_cnj(5004410, $ano, 4, 3, 6183), 'cliente' => 'Antônio Ferreira Souza', 'area' => 'Previdenciário', 'orgao' => 'TRF3 · Juizado Especial Federal', 'resp' => 'Dr. Caio', 'quando' => "hoje $hora", 'estado' => 'novidade', 'nota' => 'Sentença publicada'],
        ['cnj' => radar_demo_cnj(7002055, $ano, 8, 21, 1), 'cliente' => 'Clínica Vida Plena', 'area' => 'Consumidor', 'orgao' => 'TJRS · Juizado Especial Cível', 'resp' => 'Dra. Marta', 'quando' => "hoje $hora", 'estado' => 'novidade', 'nota' => 'Guia de preparo emitida'],
        ['cnj' => radar_demo_cnj(1000987, $ano, 5, 2, 8), 'cliente' => 'Padaria Estrela do Sul ME', 'area' => 'Trabalhista', 'orgao' => 'TRT2 · Vara do Trabalho', 'resp' => 'Dra. Helena', 'quando' => "hoje $hora", 'estado' => 'novidade', 'nota' => 'Audiência marcada'],
        ['cnj' => radar_demo_cnj(1500631, $ano - 2, 8, 26, 53), 'cliente' => 'Horizonte Construções S.A.', 'area' => 'Tributário', 'orgao' => 'TJSP · Vara de Execuções Fiscais', 'resp' => 'Dr. Caio', 'quando' => 'ontem ' . $hora, 'estado' => 'falha', 'nota' => 'Sistema do tribunal oscilando'],
    ];
    // processo => índice na carteira
    $p = static fn (int $i): string => $carteira[$i]['cnj'];

    $acoes = [
        ['dias' => 2, 'rotulo' => 'Manifestar-se sobre o laudo pericial', 'proc' => $p(2), 'quem' => 'Advogado da autora', 'impacto' => 'atencao', 'prazo' => $d(radar_demo_uteis($hoje, 2))],
        ['dias' => 4, 'rotulo' => 'Apresentar defesa até a audiência', 'proc' => $p(0), 'quem' => 'Parte ré (reclamada)', 'impacto' => 'atencao', 'prazo' => $d(radar_demo_uteis($hoje, 4))],
        ['dias' => 8, 'rotulo' => 'Avaliar recurso contra a sentença', 'proc' => $p(4), 'quem' => 'Ambas as partes', 'impacto' => 'desfavoravel', 'prazo' => $d(radar_demo_uteis($hoje, 8))],
        ['dias' => 13, 'rotulo' => 'Comparecer à audiência (10h35)', 'proc' => $p(6), 'quem' => 'Partes e advogados', 'impacto' => 'neutro', 'prazo' => $d($hoje + 13 * DAY_IN_SECONDS)],
        ['dias' => 12, 'rotulo' => 'Recolher o preparo do recurso', 'proc' => $p(5), 'quem' => 'Parte recorrente', 'impacto' => 'atencao', 'prazo' => $d(radar_demo_uteis($hoje, 12))],
        ['dias' => null, 'rotulo' => 'Conferir intimação para cumprir obrigação', 'proc' => $p(3), 'quem' => 'Conforme o ato', 'impacto' => 'neutro', 'prazo' => 'sem data certa'],
    ];

    $pubs = [
        ['quando' => 'hoje', 'proc' => $p(4), 'orgao' => 'TRF3 · JEF', 'resumo' => 'Sentença: julgado o pedido; prazo para recurso em curso.', 'fonte' => 'Diário (DJEN)'],
        ['quando' => 'hoje', 'proc' => $p(2), 'orgao' => 'TJSP · 3ª Vara Cível', 'resumo' => 'Despacho: manifestação das partes sobre o laudo.', 'fonte' => 'Diário (DJEN)'],
        ['quando' => 'hoje', 'proc' => $p(0), 'orgao' => 'TRT2 · Vara do Trabalho', 'resumo' => 'Intimação: audiência designada; defesa até a audiência.', 'fonte' => 'Diário (DJEN)'],
        ['quando' => 'hoje', 'proc' => $p(6), 'orgao' => 'TRT2 · Vara do Trabalho', 'resumo' => 'Intimação: audiência de instrução marcada.', 'fonte' => 'Diário (DJEN)'],
        ['quando' => 'hoje', 'proc' => $p(5), 'orgao' => 'TJRS · JEC', 'resumo' => 'Movimento interno: guia de preparo emitida.', 'fonte' => 'Datajud (CNJ)'],
        ['quando' => 'ontem', 'proc' => $p(3), 'orgao' => 'TJMG · Vara de Família', 'resumo' => 'Movimento interno: conclusos para despacho.', 'fonte' => 'Datajud (CNJ)'],
    ];

    $falhas = count(array_filter($carteira, static fn ($r) => $r['estado'] === 'falha'));
    $urgentes = count(array_filter($acoes, static fn ($a) => $a['dias'] !== null && $a['dias'] <= 7));
    return [
        'escritorio' => 'Prado & Associados Advocacia', 'plano' => 'Profissional', 'limite' => 100, 'horario' => $hora,
        'hoje' => $d($hoje), 'carteira' => $carteira, 'acoes' => $acoes, 'pubs' => $pubs,
        'kpis' => ['monitorados' => count($carteira), 'atualizados' => count($carteira) - $falhas, 'falhas' => $falhas, 'urgentes' => $urgentes,
            'novidades' => count(array_filter($carteira, static fn ($r) => $r['estado'] === 'novidade'))],
    ];
}
