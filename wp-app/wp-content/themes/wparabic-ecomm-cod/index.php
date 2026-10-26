<?php
/**
 * Gabarit de repli : archives, blog, resultats de recherche.
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
				<div class="section-heading">
					<div class="section-heading-content">
						<h1 class="section-title">
							<?php
							if (is_search()) {
								printf(esc_html__('نتائج البحث عن : %s', 'wparabic-ecomm-cod'), '<span>' . esc_html(get_search_query()) . '</span>');
							} elseif (is_archive()) {
								the_archive_title();
							} else {
								esc_html_e('آخر المقالات', 'wparabic-ecomm-cod');
							}
							?>
						</h1>
						<?php if (is_archive() && get_the_archive_description()) : ?>
							<p class="section-subtitle"><?php echo wp_kses_post(get_the_archive_description()); ?></p>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<div class="<?php echo $has_sidebar ? 'col-lg-9 col-12' : 'col-12'; ?>">
				<?php if (have_posts()) : ?>
					<?php while (have_posts()) : the_post(); ?>
						<article id="entry-<?php the_ID(); ?>" <?php post_class('entry'); ?>>
							<?php if (has_post_thumbnail()) : ?>
								<div class="entry-thumb">
									<a href="<?php the_permalink(); ?>"><?php the_post_thumbnail('large'); ?></a>
								</div>
							<?php endif; ?>
							<header class="entry-header">
								<h2 class="entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
								<div class="entry-meta"><?php echo esc_html(get_the_date()); ?></div>
							</header>
							<div class="entry-content"><?php the_excerpt(); ?></div>
						</article>
					<?php endwhile; ?>

					<?php
					the_posts_pagination(array(
						'mid_size'  => 2,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					));
					?>
				<?php else : ?>
					<p class="no-results"><?php esc_html_e('لا توجد نتائج.', 'wparabic-ecomm-cod'); ?></p>
					<?php get_search_form(); ?>
				<?php endif; ?>
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
