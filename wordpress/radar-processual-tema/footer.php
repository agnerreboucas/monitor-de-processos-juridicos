<footer class="rodape"><div class="c">
  <span>© 2026 Radar Processual · Apoio operacional de acompanhamento processual.</span>
  <span><a href="<?= radar_link('app/termos.html'); ?>">Termos de Uso</a> · <a href="<?= radar_link('app/privacidade.html'); ?>">Privacidade</a> · <a href="<?= esc_url(home_url('/demonstracao/')); ?>">Demonstração</a> · <a href="<?= radar_link('app/api-docs.html'); ?>">API</a> · <a href="<?= esc_url(radar_whatsapp()); ?>" target="_blank" rel="noopener">WhatsApp</a> · <a href="<?= radar_link('entrar'); ?>">Entrar</a></span>
</div></footer>
<a class="zap-fixo" href="<?= esc_url(radar_whatsapp()); ?>" target="_blank" rel="noopener"><?= radar_icone_zap(); ?><span><strong>Falar com um consultor</strong><small>Atendimento direto no WhatsApp</small></span></a>
<div class="barra-fixa"><a class="btn zap" href="<?= esc_url(radar_whatsapp()); ?>" target="_blank" rel="noopener"><?= radar_icone_zap(); ?> Consultor</a><a class="btn p" href="<?= radar_link('entrar#/cadastro'); ?>">Testar grátis</a></div>

<?php wp_footer(); ?>
</body>
</html>
