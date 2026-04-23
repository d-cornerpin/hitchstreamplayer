<?php
/*
Template Name: No Header
*/

get_header('custom');
?>

<style>
.btImage {
    width: 200px;
    margin: 0 auto !important;
}
</style>
<!-- Start The Loop -->
<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
    <?php the_content(); ?>
<?php endwhile; else : ?>
    <p><?php esc_html_e( 'Sorry, no posts matched your criteria.' ); ?></p>
<?php endif; ?>
<!-- End The Loop -->

<?php
get_footer('custom'); 
?>
