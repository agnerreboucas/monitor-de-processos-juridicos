<?php
/**
 * Template Name: Demonstração (escritório modelo)
 * Demonstração do Radar com dados FICTÍCIOS, para mostrar o sistema em apresentações.
 */
require_once get_template_directory() . '/inc/demo-data.php';
$demo = radar_demo_data();
$k = $demo['kpis'];
$estados = ['novidade' => ['azul', 'Novidade'], 'ok' => ['verde', 'Atualizado'], 'falha' => ['verm', 'Sem acesso']];
$impactos = ['atencao' => ['amar', 'Atenção'], 'desfavoravel' => ['verm', 'Desfavorável'], 'favoravel' => ['verde', 'Favorável'], 'neutro' => ['cinza', 'Neutro']];
$urgencia = static function (?int $dias): array {
    if ($dias === null) {
        return ['cinza', 'Sem data certa'];
    }
    return $dias <= 3 ? ['verm', 'Urgente · ' . $dias . ' dias úteis'] : ($dias <= 7 ? ['amar', 'Esta semana · ' . $dias . ' dias'] : ['azul', 'Em ' . $dias . ' dias']);
};
get_header();
?>
<main class="demo">
  <div class="c">
    <div class="demo-aviso"><strong>Demonstração com dados fictícios.</strong> O escritório, os clientes, os advogados e os números de processo abaixo são inventados e servem só para mostrar como o Radar Processual organiza o dia.</div>

    <header class="demo-topo">
      <div>
        <p class="rot">Escritório modelo</p>
        <h1><?= esc_html($demo['escritorio']); ?></h1>
        <p class="sub">Plano <?= esc_html($demo['plano']); ?> · <?= (int) $k['monitorados']; ?> de <?= (int) $demo['limite']; ?> processos monitorados · atualização todos os dias às <?= esc_html($demo['horario']); ?> · hoje, <?= esc_html($demo['hoje']); ?></p>
      </div>
      <a class="btn p" href="<?= radar_link('entrar#/cadastro'); ?>">Testar grátis por 14 dias</a>
    </header>

    <div class="demo-kpis">
      <div class="kpi"><span class="n"><?= (int) $k['monitorados']; ?></span><span>processos monitorados</span></div>
      <div class="kpi"><span class="n"><?= (int) $k['atualizados']; ?></span><span>atualizados hoje</span></div>
      <div class="kpi azul"><span class="n"><?= (int) $k['novidades']; ?></span><span>com novidade</span></div>
      <div class="kpi amar"><span class="n"><?= (int) $k['urgentes']; ?></span><span>ações com prazo em até 7 dias</span></div>
      <div class="kpi verm"><span class="n"><?= (int) $k['falhas']; ?></span><span>sem acesso hoje (sistema do tribunal)</span></div>
    </div>
    <nav class="demo-nav" aria-label="Tabelas da demonstração"><a href="#carteira">1. Carteira de processos</a><a href="#acoes">2. Ações e prazos</a><a href="#publicacoes">3. Publicações do dia</a></nav>

    <section id="carteira" class="demo-bloco">
      <h2>1. Carteira de processos</h2>
      <p class="sub">Todos os processos do escritório, de várias áreas do Direito, atualizados no horário escolhido. O que não foi acessado aparece com o motivo, nunca como “sem novidade”.</p>
      <div class="tabela"><table>
        <thead><tr><th>Processo</th><th>Cliente</th><th>Área</th><th>Tribunal</th><th>Responsável</th><th>Última atualização</th><th>Situação</th></tr></thead>
        <tbody>
<?php foreach ($demo['carteira'] as $r) : [$cor, $rot] = $estados[$r['estado']]; ?>
          <tr><td class="mono"><?= esc_html($r['cnj']); ?></td><td><?= esc_html($r['cliente']); ?></td><td><?= esc_html($r['area']); ?></td><td><?= esc_html($r['orgao']); ?></td><td><?= esc_html($r['resp']); ?></td><td><?= esc_html($r['quando']); ?></td><td><span class="pilula <?= esc_attr($cor); ?>"><?= esc_html($rot); ?></span> <span class="nota-linha"><?= esc_html($r['nota']); ?></span></td></tr>
<?php endforeach; ?>
        </tbody>
      </table></div>
    </section>

    <section id="acoes" class="demo-bloco">
      <h2>2. Ações e prazos</h2>
      <p class="sub">O sistema lê as publicações e diz o que fazer, quem age, até quando (prazo estimado em dias úteis) e o impacto. Tudo ordenado por urgência.</p>
      <div class="tabela"><table>
        <thead><tr><th>Urgência</th><th>O que fazer</th><th>Processo</th><th>Quem age</th><th>Prazo (estimado)</th><th>Impacto</th></tr></thead>
        <tbody>
<?php foreach ($demo['acoes'] as $a) : [$cu, $ru] = $urgencia($a['dias']); [$ci, $ri] = $impactos[$a['impacto']]; ?>
          <tr><td><span class="pilula <?= esc_attr($cu); ?>"><?= esc_html($ru); ?></span></td><td><strong><?= esc_html($a['rotulo']); ?></strong></td><td class="mono"><?= esc_html($a['proc']); ?></td><td><?= esc_html($a['quem']); ?></td><td><?= esc_html($a['prazo']); ?></td><td><span class="pilula <?= esc_attr($ci); ?>"><?= esc_html($ri); ?></span></td></tr>
<?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="demo-rodape-tab">Prazos estimados pela leitura automática. Confira sempre o ato e a legislação: o aviso do Radar não é intimação.</p>
    </section>

    <section id="publicacoes" class="demo-bloco">
      <h2>3. Publicações e movimentações do dia</h2>
      <p class="sub">O que chegou do Diário de Justiça Eletrônico Nacional e do Datajud (CNJ) nas últimas 24 horas, já ligado ao processo certo.</p>
      <div class="tabela"><table>
        <thead><tr><th>Quando</th><th>Processo</th><th>Órgão</th><th>Resumo</th><th>Fonte</th></tr></thead>
        <tbody>
<?php foreach ($demo['pubs'] as $u) : ?>
          <tr><td><?= esc_html($u['quando']); ?></td><td class="mono"><?= esc_html($u['proc']); ?></td><td><?= esc_html($u['orgao']); ?></td><td><?= esc_html($u['resumo']); ?></td><td><span class="pilula cinza"><?= esc_html($u['fonte']); ?></span></td></tr>
<?php endforeach; ?>
        </tbody>
      </table></div>
    </section>

    <div class="demo-fim">
      <h2>Quer ver com os seus processos?</h2>
      <p>Crie a conta do escritório, cadastre os primeiros processos e veja o Radar trabalhando. 14 dias grátis, sem cartão.</p>
      <a class="btn p" href="<?= radar_link('entrar#/cadastro'); ?>">Testar grátis por 14 dias</a>
      <a class="btn s" href="<?= esc_url(home_url('/#planos')); ?>">Ver os preços</a>
    </div>
  </div>
</main>
<?php get_footer(); ?>
