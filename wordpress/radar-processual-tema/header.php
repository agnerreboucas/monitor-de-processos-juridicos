<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<?php if (radar_topo_texto() !== '') : ?><div class="barra-topo"><?= esc_html(radar_topo_texto()); ?></div><?php endif; ?>
<header class="topo"><div class="c">
  <a class="marca" href="<?= esc_url(home_url('/')); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10.2"/><circle cx="12" cy="12" r="4.8"/></svg><span>Radar <b>Processual</b></span></a>
  <nav aria-label="Seções"><?php wp_nav_menu(['theme_location' => 'principal', 'container' => false, 'menu_class' => 'menu-topo', 'fallback_cb' => 'radar_menu_padrao_topo', 'depth' => 1]); ?></nav>
  <div class="acoes"><a class="entrar" href="<?= radar_link('entrar'); ?>">Entrar</a><a class="btn p" href="<?= radar_link('entrar#/cadastro'); ?>">Testar grátis</a></div>
</div></header>
