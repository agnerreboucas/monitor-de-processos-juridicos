<footer class="rodape"><div class="c">
  <span><?= wp_kses_post(radar_rodape_texto()); ?></span>
  <nav aria-label="Rodapé"><?php wp_nav_menu(['theme_location' => 'rodape', 'container' => false, 'menu_class' => 'menu-rodape', 'fallback_cb' => 'radar_menu_padrao_rodape', 'depth' => 1]); ?></nav>
</div></footer>
<a class="zap-fixo" href="<?= esc_url(radar_whatsapp()); ?>" target="_blank" rel="noopener"><?= radar_icone_zap(); ?><span><strong>Falar com um consultor</strong><small>Atendimento direto no WhatsApp</small></span></a>
<div class="barra-fixa"><a class="btn zap" href="<?= esc_url(radar_whatsapp()); ?>" target="_blank" rel="noopener"><?= radar_icone_zap(); ?> Consultor</a><a class="btn p" href="<?= radar_link('entrar#/cadastro'); ?>">Testar grátis</a></div>

<?php wp_footer(); ?>
</body>
</html>
