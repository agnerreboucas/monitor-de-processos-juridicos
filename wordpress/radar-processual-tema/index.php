<?php get_header(); ?>
<main class="conteudo-pagina">
<?php if (have_posts()) : while (have_posts()) : the_post(); ?>
  <article <?php post_class('item-post'); ?>>
    <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
    <?php the_excerpt(); ?>
  </article>
<?php endwhile; the_posts_pagination(); else : ?>
  <h1>Nada por aqui</h1>
  <p>Não encontramos conteúdo para este endereço.</p>
<?php endif; ?>
</main>
<?php get_footer(); ?>
