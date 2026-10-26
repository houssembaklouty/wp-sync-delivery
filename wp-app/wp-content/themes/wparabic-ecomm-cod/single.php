<?php
/**
 * Gabarit d'un article.
 *
 * @package wparabic-ecomm-cod
 */

defined('ABSPATH') || exit;

get_header();

$has_sidebar = is_active_sidebar('sidebar-1');
?>
<main class="main">
	<div class="<?php echo esc_attr(wparabic_container_class()); ?>">
		<div class="row ">

			<div class="col-12">
				<?php if (function_exists('woocommerce_breadcrumb')) { woocommerce_breadcrumb(); } ?>
			</div>

			<div class="<?php echo $has_sidebar ? 'col-lg-9 col-12' : 'col-12'; ?>">
				<?php while (have_posts()) : the_post(); ?>
					<article id="entry-<?php the_ID(); ?>" <?php post_class('entry'); ?>>
						<?php if (has_post_thumbnail()) : ?>
							<div class="entry-thumb"><?php the_post_thumbnail('large'); ?></div>
						<?php endif; ?>
						<header class="entry-header">
							<h1 class="entry-title"><?php the_title(); ?></h1>
							<div class="entry-meta"><?php echo esc_html(get_the_date()); ?></div>
						</header>
						<div class="entry-content">
							<?php the_content(); ?>
							<?php
							wp_link_pages(array(
								'before' => '<div class="page-links">',
								'after'  => '</div>',
							));
							?>
						</div>
					</article>
					<?php
					if (comments_open() || get_comments_number()) {
						comments_template();
					}
					?>
				<?php endwhile; ?>
			</div>

			<?php if ($has_sidebar) : ?>
				<div class="col-lg-3 col-12">
					<div class="sidebar"><?php dynamic_sidebar('sidebar-1'); ?></div>
				</div>
			<?php endif; ?>

		</div>
	</div>
</main>
<?php
get_footer();
