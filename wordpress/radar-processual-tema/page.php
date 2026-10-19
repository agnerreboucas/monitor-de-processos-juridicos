<?php
// Páginas feitas no Elementor ocupam a largura toda (sem título nem caixa); as demais usam a caixa de texto do tema.
$elementor = class_exists('\Elementor\Plugin') && is_singular() && \Elementor\Plugin::$instance->documents->get(get_the_ID()) && \Elementor\Plugin::$instance->documents->get(get_the_ID())->is_built_with_elementor();
get_header();
if ($elementor) : ?>
<main class="pagina-elementor">
<?php while (have_posts()) : the_post(); the_content(); endwhile; ?>
</main>
<?php else : ?>
<main class="conteudo-pagina">
<?php while (have_posts()) : the_post(); ?>
  <article <?php post_class(); ?>>
    <h1><?php the_title(); ?></h1>
    <?php the_content(); ?>
  </article>
<?php endwhile; ?>
</main>
<?php endif;
get_footer();
