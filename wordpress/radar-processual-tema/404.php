<?php get_header(); ?>
<main class="conteudo-pagina">
  <h1>Página não encontrada</h1>
  <p>Esse endereço não existe. <a href="<?= esc_url(home_url('/')); ?>">Voltar para o início</a> ou <a href="<?= radar_link('entrar'); ?>">entrar no sistema</a>.</p>
</main>
<?php get_footer(); ?>
