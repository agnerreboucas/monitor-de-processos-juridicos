<?php
/** Página inicial: página de venda do Radar Processual. */
get_header();
?>


<section class="hero"><div class="c">
  <div class="topo-hero">
    <div>
      <span class="selo-hero">Para escritórios de advocacia</span>
      <h1>Seus processos vigiados todos os dias, com o que fazer e até quando.</h1>
      <p class="l">Acompanhe o andamento completo de cada processo e as publicações em nome dos seus advogados. O Radar avisa a equipe, mostra o prazo estimado e deixa claro o que exige ação.</p>
      <div class="cta"><a class="btn p" href="<?= radar_link('entrar#/cadastro'); ?>">Testar grátis por 14 dias</a><a class="btn s" href="#planos">Ver o preço</a></div>
      <small>Sem cartão de crédito no teste · Dados de fontes públicas oficiais · Falha de consulta nunca vira “sem novidade”</small>
    </div>
    <ul class="lista">
      <li>Andamento completo do processo (Datajud) e publicações em tempo real (Diário)</li>
      <li>Ações e prazos por urgência, com quem deve agir e o impacto</li>
      <li>Monitoramento por número CNJ e por OAB, com aviso por e-mail</li>
      <li>Painel de ações por UF e alerta de inscrição suplementar</li>
      <li>API por chave para integrar ao seu sistema</li>
    </ul>
  </div>
</div></section>

<div class="prova"><div class="c">
  <span class="selo-prova">Construído sobre fontes oficiais</span>
  <p><strong>Diário de Justiça Eletrônico Nacional (DJEN/Comunica)</strong> e <strong>Datajud (CNJ)</strong>. O leitor das publicações foi conferido com retorno real do DJEN: partes, advogados, órgão, datas e o texto inteiro de dezenas de tribunais.</p>
</div></div>

<section class="sec" id="como"><div class="c">
  <p class="rot">Como funciona</p>
  <h2 class="t">Do cadastro ao primeiro aviso em poucos minutos</h2>
  <p class="sub">Nada para instalar: o sistema roda no navegador e a consulta é feita por nós, todos os dias.</p>
  <div class="grade g4">
    <div class="card passo"><span class="n">01</span><h3>Crie a conta do escritório</h3><p>Teste grátis de 14 dias, com contagem regressiva na tela. Convide advogados e assistentes e defina o que cada papel pode fazer.</p></div>
    <div class="card passo"><span class="n">02</span><h3>Cadastre processos e OABs</h3><p>Digite o número, importe uma planilha ou informe a OAB de cada advogado para ver as publicações em nome dele.</p></div>
    <div class="card passo"><span class="n">03</span><h3>Atualização no horário que você escolher</h3><p>Escolha: uma vez por dia no horário que quiser (por exemplo, 5h30 da manhã, para o dia já começar em dia) ou de 6 em 6 horas. E, se precisar, clique em “Consultar agora”. A primeira consulta traz o histórico em silêncio; depois, só o que for novo gera aviso.</p></div>
    <div class="card passo"><span class="n">04</span><h3>Veja o que fazer com cada processo</h3><p>Em cada processo, o botão “Ver o que fazer” mostra um resumo com as ações, quem age, o prazo estimado e o impacto. Depois você lê o inteiro teor.</p></div>
  </div>
  <p class="nota">Você só precisa de um navegador. A conferência na fonte oficial e o controle de prazos continuam sendo do escritório.</p>
</div></section>

<section class="sec alt" id="compromissos"><div class="c">
  <div class="dois">
    <div>
      <p class="rot">Sem surpresa</p>
      <h2 class="t">Teste sem cartão. Os dados são do seu escritório.</h2>
      <p class="sub" style="margin-bottom:20px">Você experimenta com os seus processos antes de decidir, e continua dono do que cadastrou.</p>
      <ul>
        <li>14 dias grátis, sem cartão. A cobrança começa no 15º dia e, no primeiro mês, você paga só 15 dias</li>
        <li>Todo dia, depois da atualização, um resumo: quantos processos foram atualizados e quais não deu para acessar, com o motivo (tribunal fora do ar, oscilação, sigilo)</li>
        <li>Cada escritório enxerga somente os próprios dados</li>
        <li>Baixe uma cópia completa dos dados (JSON) e exporte processos e publicações em planilha (CSV)</li>
        <li>O administrador pode excluir todos os dados do escritório, direto na tela</li>
        <li>Quando uma consulta falha, a tela mostra a falha e a última consulta que deu certo</li>
      </ul>
    </div>
    <div class="card">
      <h3>O que o Radar não faz</h3>
      <ul class="nao" style="margin:12px 0 0">
        <li>Não é intimação nem publicação oficial: confira sempre na fonte.</li>
        <li>Não substitui o controle de prazos: a data mostrada é uma estimativa em dias úteis, sem feriados locais.</li>
        <li>Não acessa processo em segredo de justiça.</li>
        <li>Não garante captar tudo: depende do que cada tribunal publica.</li>
      </ul>
    </div>
  </div>
</div></section>

<section class="sec" id="fontes"><div class="c">
  <div class="dois">
    <div>
      <p class="rot">De onde vêm os dados</p>
      <h2 class="t">Fontes públicas oficiais, sem raspagem de sites</h2>
      <p class="sub" style="margin-bottom:20px">Duas fontes oficiais se completam: uma é rápida e traz o texto, a outra é completa e demora alguns dias.</p>
      <ul>
        <li><strong>Diário (DJEN/Comunica):</strong> publicações oficiais em tempo real, com texto, partes, advogados e órgão</li>
        <li><strong>Datajud (CNJ):</strong> andamento interno completo e dados da capa; atualiza com alguns dias de atraso</li>
        <li>Consulta por número do processo e por OAB</li>
        <li>Texto original preservado, com datas de publicação e de captura separadas</li>
        <li>Sem raspagem de sites de tribunal, sem quebrar captcha, sem acesso restrito</li>
      </ul>
    </div>
    <div class="card aviso-termos">
      <h3>Uma conta honesta</h3>
      <p>Cada fonte pública tem termos de uso próprios. As publicações podem trazer dados pessoais, e processos em segredo de justiça não ficam disponíveis por nenhuma fonte pública. Quando uma fonte não responde, o sistema avisa que o quadro está incompleto.</p>
    </div>
  </div>
</div></section>

<section class="sec alt" id="recursos"><div class="c">
  <p class="rot">O que está no sistema</p>
  <h2 class="t">Da publicação à ação, sem planilha</h2>
  <p class="sub">Seis blocos que cobrem a rotina de quem atende a Justiça, em qualquer área do Direito.</p>
  <div class="grade g3">
    <div class="card rec"><span class="n">01</span><h3>Monitoramento por processo</h3><p>Valida o número CNJ (inclusive o dígito verificador) e mostra a situação: monitorado, aguardando, pausado ou com falha.</p></div>
    <div class="card rec"><span class="n">02</span><h3>Andamento completo</h3><p>Consulte qualquer número e veja a capa e todo o andamento, juntando Datajud e Diário, com partes, advogados e o link do inteiro teor.</p></div>
    <div class="card rec"><span class="n">03</span><h3>Ações e prazos</h3><p>Triagem por urgência: o que fazer, quem age, como, prazo estimado, impacto favorável ou não, riscos e atores. Marque o que já foi tratado.</p></div>
    <div class="card rec"><span class="n">04</span><h3>Monitoramento por OAB</h3><p>Tudo o que o diário publicou em nome de cada advogado e, com um clique, passe a monitorar o processo.</p></div>
    <div class="card rec"><span class="n">05</span><h3>Ações por UF e inscrição suplementar</h3><p>Quantos processos cada advogado tem em cada estado, por ramo da Justiça, e alerta quando passa de cinco no ano onde não tem inscrição.</p></div>
    <div class="card rec"><span class="n">06</span><h3>Equipe, avisos e LGPD</h3><p>Responsável por processo, aviso no painel e por e-mail, importação por planilha, e cópia ou exclusão dos dados pelo administrador.</p></div>
  </div>
</div></section>

<section class="faixa-cta"><div class="c">
  <p>Veja o Radar trabalhando com os seus processos.</p>
  <a class="btn p" href="<?= radar_link('entrar#/cadastro'); ?>">Testar grátis por 14 dias</a>
</div></section>

<section class="sec" id="pratica"><div class="c">
  <div class="dois">
    <div>
      <p class="rot">Na prática</p>
      <h2 class="t">Um painel feito para decidir o que fazer hoje</h2>
      <p class="sub" style="margin-bottom:20px">Indicadores no topo e detalhe embaixo, para o advogado ver o que importa antes de abrir qualquer processo.</p>
      <ul>
        <li>Processos ativos, com falha de consulta e com movimentação nova</li>
        <li>Ações com prazo até 7 dias e publicações novas</li>
        <li>Ações por UF, Justiça Estadual × Federal × do Trabalho, por advogado</li>
        <li>Alertas de possível inscrição suplementar</li>
        <li>Visão por escritório e por advogado (OAB)</li>
      </ul>
    </div>
    <div class="vitrine"><img src="<?= esc_url(get_theme_file_uri('img/painel.png')); ?>" width="1400" height="920" alt="Painel do Radar Processual com a contagem do teste grátis, o resumo de hoje e os indicadores"></div>
  </div>
  <p class="vitrine-legenda">Imagem do painel com dados fictícios de exemplo.</p>
</div></section>


<section class="sec" id="celular"><div class="c">
  <div class="dois">
    <div class="celular"><img src="<?= esc_url(get_theme_file_uri('img/celular.png')); ?>" width="390" height="844" alt="Painel do Radar Processual no celular"></div>
    <div>
      <p class="rot">No celular</p>
      <h2 class="t">Abra pelo celular e veja o dia já atualizado</h2>
      <p class="sub" style="margin-bottom:20px">Sem aplicativo para instalar: o painel se adapta à tela do celular.</p>
      <ul>
        <li>Atualização automática: uma vez por dia no horário escolhido (ex.: 5h30) ou de 6 em 6 horas</li>
        <li>Botão “Consultar tudo agora” para atualizar na hora</li>
        <li>Resumo do dia: quantos processos foram atualizados e quais não deu para acessar</li>
        <li>Botão “Ver o que fazer” em cada processo, com ações, prazo e impacto</li>
        <li>Aviso por e-mail quando sai movimentação nova</li>
      </ul>
    </div>
  </div>
</div></section>

<section class="sec alt" id="publico"><div class="c">
  <p class="rot">Para quem é</p>
  <h2 class="t">Feito para quem vive de processo, em qualquer área do Direito</h2>
  <div class="grade g3" style="margin-top:32px">
    <div class="card"><h3>Advogado autônomo</h3><p>Acompanhe suas OABs e seus processos sem depender de planilha ou de memória.</p></div>
    <div class="card"><h3>Escritório com equipe</h3><p>Defina o responsável por cada processo, convide a equipe e veja quem tem o quê, por UF e por advogado.</p></div>
    <div class="card"><h3>Atuação em vários estados</h3><p>Descubra cedo quando alguém passa do limite de causas numa Seccional e organize as inscrições suplementares.</p></div>
  </div>
  <div class="card api-faixa"><div><h3>Software house ou integrador?</h3><p>Leve o andamento e as publicações para o seu sistema com a API por chave: consulta por processo e por OAB, cota mensal e documentação com exemplos.</p></div><a class="btn o" href="<?= radar_link('app/api-docs.html'); ?>">Ver a documentação da API</a></div>
</div></section>

<section class="sec" id="objecoes"><div class="c">
  <p class="rot">Antes que você pergunte</p>
  <h2 class="t">As dúvidas que mais aparecem</h2>
  <div class="grade g3" style="margin-top:32px">
    <div class="card obj"><h3>O aviso vale como intimação?</h3><p>Não. Ele mostra que o sistema capturou uma publicação. Cada aviso traz o texto original e o link da fonte oficial para conferência.</p></div>
    <div class="card obj"><h3>O prazo mostrado é garantido?</h3><p>Não. É uma estimativa em dias úteis, e a tela diz como foi contada. Feriados locais e suspensões do tribunal não entram: confira o ato.</p></div>
    <div class="card obj"><h3>E se a consulta falhar?</h3><p>A tela mostra a falha, o motivo e a última consulta que deu certo. O sistema tenta de novo. Falha nunca aparece como “sem novidades”.</p></div>
  </div>
</div></section>

<section class="sec alt" id="tribunais"><div class="c">
  <p class="rot">Cobertura</p>
  <h2 class="t">Todos os ramos da Justiça que publicam no diário nacional</h2>
  <p class="sub">A disponibilidade de cada processo depende do que o tribunal publica. Trabalhista, cível, previdenciário, tributário, família, criminal: o que o tribunal publica, o Radar acompanha.</p>
  <div class="tribunais">
    <div class="card"><h3>Justiça do Trabalho <small>TRT1 a TRT24 e TST</small></h3><div class="chips"><span>TRT1</span><span>TRT2</span><span>TRT3</span><span>TRT4</span><span>TRT5</span><span>TRT8</span><span>TRT9</span><span>TRT15</span><span>TRT24</span><span>TST</span></div></div>
    <div class="card"><h3>Justiça Estadual <small>TJ de cada estado</small></h3><div class="chips"><span>TJSP</span><span>TJMG</span><span>TJRJ</span><span>TJRS</span><span>TJPR</span><span>TJBA</span><span>TJDFT</span><span>TJGO</span></div></div>
    <div class="card"><h3>Justiça Federal <small>TRF1 a TRF6</small></h3><div class="chips"><span>TRF1</span><span>TRF2</span><span>TRF3</span><span>TRF4</span><span>TRF5</span><span>TRF6</span></div></div>
    <div class="card"><h3>Outros ramos <small>Eleitoral, Militar, superiores</small></h3><div class="chips"><span>TRE</span><span>TJM</span><span>STJ</span><span>TSE</span><span>STM</span></div></div>
  </div>
</div></section>

<section class="sec" id="planos"><div class="c">
  <p class="rot">Preços</p>
  <h2 class="t">Assinatura mensal por escritório, com limite de processos</h2>
  <p class="sub">14 dias grátis, sem cartão. A cobrança começa no 15º dia e, no primeiro mês, você paga só 15 dias (meio mês).</p>
  <div class="planos quatro">
    <div class="card plano"><h3>Essencial</h3><p>Autônomo ou escritório enxuto.</p><div class="preco">R$ 299<small> /mês</small></div><ul><li>Até 50 processos monitorados</li><li>Atualização diária no horário que você escolher</li><li>Alertas, histórico e painel</li><li>1º mês: R$ 149,50 (15 dias)</li></ul><a class="btn o" href="<?= radar_link('entrar#/cadastro?plano=essencial'); ?>">Começar 14 dias grátis</a></div>
    <div class="card plano dest"><span class=etq>Mais escolhido</span><h3>Profissional</h3><p>Escritório pequeno, com equipe.</p><div class="preco">R$ 599<small> /mês</small></div><ul><li>Até 100 processos monitorados</li><li>Tudo do Essencial</li><li>Responsáveis por processo e alertas configuráveis</li><li>1º mês: R$ 299,50 (15 dias)</li></ul><a class="btn p" href="<?= radar_link('entrar#/cadastro?plano=profissional'); ?>">Começar 14 dias grátis</a></div>
    <div class="card plano"><h3>Escritório</h3><p>Vários advogados.</p><div class="preco">R$ 1.499<small> /mês</small></div><ul><li>Até 250 processos monitorados</li><li>Tudo do Profissional</li><li>Distribuição por advogado e visão da carteira</li><li>1º mês: R$ 749,50 (15 dias)</li></ul><a class="btn o" href="<?= radar_link('entrar#/cadastro?plano=equipe'); ?>">Começar 14 dias grátis</a></div>
    <div class="card plano"><h3>Pague por busca</h3><p>Uso irregular ou integração por API.</p><div class="preco">R$ 0,30<small> /busca</small></div><ul><li>Só busca realizada com sucesso é cobrada</li><li>Falha do tribunal não entra na conta</li><li>Teto de gasto mensal definido por você</li><li>Chave de API com extrato de uso</li></ul><a class="btn o" href="<?= radar_link('entrar#/cadastro?plano=por-busca'); ?>">Começar 14 dias grátis</a></div>
  </div>
  <p class="nota">Como pagar: no 15º dia, abra a tela Plano e clique em “Quero contratar”; você recebe as instruções de pagamento (Pix) e, confirmado o pagamento, a assinatura segue sem interrupção. Valores iniciais, sujeitos a ajuste antes do lançamento.</p>
  </div></section>

<section class="sec alt" id="perguntas"><div class="c" style="max-width:820px">
  <p class="rot">Perguntas frequentes</p>
  <h2 class="t" style="margin-bottom:28px">Tire suas dúvidas</h2>
  <details><summary>O que o sistema mostra de cada processo?</summary><p>A capa (classe, assunto, órgão, distribuição), o andamento completo do Datajud e as publicações do Diário, com o texto inteiro, as partes, os advogados e o link da fonte oficial.</p></details>
  <details><summary>O aviso do Radar vale como intimação?</summary><p>Não. O aviso mostra que o sistema capturou uma publicação ou movimentação. A conferência na fonte oficial e o controle de prazos continuam sendo do escritório.</p></details>
  <details><summary>Como funciona a tela “Ações e prazos”?</summary><p>O sistema lê o texto de cada publicação e sugere: que ato é, o que fazer, quem deve agir, o prazo estimado em dias úteis, o impacto (favorável ou não, conforme o lado que o escritório representa), os riscos e os atores. É uma triagem automática, feita por regras simples: pode errar ou deixar passar algo, e cada leitura mostra o grau de confiança.</p></details>
  <details><summary>Vocês fazem raspagem de sites de tribunais?</summary><p>Não. As consultas usam fontes públicas oficiais por API, sem raspagem, sem quebrar captcha e sem acessar nada restrito. Processos em segredo de justiça não ficam disponíveis por nenhuma fonte pública.</p></details>
  <details><summary>E se a consulta falhar?</summary><p>A tela mostra a falha, o motivo e a data da última consulta que deu certo. O sistema tenta de novo com espera crescente. Falha nunca é exibida como “sem novidades”.</p></details>
  <details><summary>O que é o alerta de inscrição suplementar?</summary><p>O Estatuto da Advocacia (art. 10, §2º) considera habitual a atuação em mais de cinco causas por ano numa Seccional. O Radar conta os processos diferentes com publicação em nome do advogado, por UF, e avisa quando passa desse número onde ele não tem inscrição. É um indicativo: a conferência com a Seccional é do advogado.</p></details>
  <details><summary>Meus dados e os dos meus clientes ficam seguros?</summary><p>Cada escritório só acessa os próprios dados, com a separação garantida no banco e conferida por testes automáticos. Senhas são guardadas de forma irreversível, e o administrador pode baixar uma cópia ou excluir todos os dados do escritório. Veja a Política de Privacidade.</p></details>
  <details><summary>Preciso instalar alguma coisa?</summary><p>Não. Você acessa pelo navegador, no computador ou no celular.</p></details>
  <details><summary>Posso trazer meus processos de uma planilha?</summary><p>Sim. Importe um CSV com o número do processo e, se quiser, o cliente. O sistema mostra linha a linha o que foi importado e o que foi recusado, e respeita o limite do plano.</p></details>
  <details><summary>Existe API para integrar ao meu sistema?</summary><p>Sim. A API por chave consulta processos e publicações por OAB, com cota mensal e limite por minuto. A documentação, com exemplos em curl, JavaScript, Python e PHP, está em <a href="<?= radar_link('app/api-docs.html'); ?>">/app/api-docs.html</a>.</p></details>
  <details><summary>Como funciona o teste grátis e o pagamento?</summary><p>Você tem 14 dias grátis, sem cartão, com contagem regressiva na tela e avisos por e-mail. A cobrança começa no 15º dia e, no primeiro mês, você paga só 15 dias (meio mês). Para contratar, use o botão “Quero contratar” na tela Plano.</p></details>
  <details><summary>Posso atualizar na hora?</summary><p>Sim. Além da atualização programada (uma vez por dia no horário que você escolher, ou de 6 em 6 horas), há o botão “Consultar tudo agora” e o “Consultar agora” de cada processo. O pedido entra na fila e sai em poucos minutos.</p></details>
  <details><summary>E se o tribunal estiver fora do ar?</summary><p>O resumo do dia mostra quantos processos não foram acessados e o motivo (por exemplo, o sistema do tribunal fora do ar ou oscilando). O sistema tenta de novo sozinho, e esse processo nunca aparece como “sem novidade”.</p></details>
</div></section>

<section class="final"><div class="c">
  <h2>O escritório vai acompanhar processos de qualquer jeito. Que seja sem planilha.</h2>
  <p>Crie a conta do escritório, cadastre os primeiros processos e veja o Radar trabalhando.</p>
  <a class="btn p" href="<?= radar_link('entrar#/cadastro'); ?>">Testar grátis por 14 dias</a>
</div></section>

<?php get_footer(); ?>
