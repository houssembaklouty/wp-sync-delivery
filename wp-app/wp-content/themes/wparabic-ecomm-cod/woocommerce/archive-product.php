<?php
/**
 * Boutique et archives produit.
 *
 * Mise en page propre au theme, mais les points d'extension standards de
 * WooCommerce sont declenches pour que les extensions fonctionnent.
 *
 * @package wparabic-ecomm-cod
 * @version 8.6.0
 */

defined('ABSPATH') || exit;

get_header('shop');

/**
 * Hook : woocommerce_before_main_content.
 * woocommerce_output_content_wrapper est retire (functions.php) — on garde le
 * fil d'Ariane et les donnees structurees.
 */
do_action('woocommerce_before_main_content');

$shop_url = get_pagenum_link(1, false);
$views    = array(
	'25'  => '25',
	'50'  => '50',
	'all' => __('الكل', 'wparabic-ecomm-cod'),
);
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$current_view = isset($_GET['view']) ? sanitize_text_field(wp_unslash($_GET['view'])) : '';
?>
<main class="main">
	<div class="<?php echo esc_attr(wparabic_container_class()); ?>">
		<div class="row justify-content-center">
			<div class="col-12">

				<?php
				/**
				 * Hook : woocommerce_shop_loop_header.
				 * Rend woocommerce/loop/header.php — surcharge par le theme pour
				 * porter les classes .section-heading / .section-title. Il
				 * declenche lui-meme woocommerce_archive_description.
				 */
				do_action('woocommerce_shop_loop_header');
				?>

				<?php
				/**
				 * Hook : woocommerce_before_shop_loop.
				 * Notices + wc_setup_loop. result_count et catalog_ordering en
				 * sont retires (functions.php) : ils sont places a la main dans
				 * .shop-actions ci-dessous.
				 */
				do_action('woocommerce_before_shop_loop');
				?>

				<?php if (woocommerce_product_loop()) : ?>
					<div class="shop-actions shop-actions-no-filter">
						<div class="shop-action-results">
							<?php woocommerce_result_count(); ?>
							<div class="product-number">
								<span><?php esc_html_e('عرض:', 'wparabic-ecomm-cod'); ?></span>
								<?php foreach ($views as $value => $label) : ?>
									<a href="<?php echo esc_url(add_query_arg('view', $value, $shop_url)); ?>" class="<?php echo $current_view === $value ? 'active' : ''; ?>"><?php echo esc_html($label); ?></a>
								<?php endforeach; ?>
							</div>
						</div>
						<?php
						// woocommerce_catalog_ordering() emet deja son propre
						// <form class="woocommerce-ordering"> : en ouvrir un
						// second ici produisait des formulaires imbriques.
						woocommerce_catalog_ordering();
						?>
					</div>

					<div class="row row-items columns-4">
						<?php
						while (have_posts()) {
							the_post();

							/**
							 * Hook : woocommerce_shop_loop.
							 */
							do_action('woocommerce_shop_loop');

							wc_get_template_part('content', 'product');
						}
						?>
					</div>

					<?php
					/**
					 * Hook : woocommerce_after_shop_loop.
					 * Porte woocommerce_pagination a la priorite 10.
					 */
					do_action('woocommerce_after_shop_loop');
					?>
				<?php else : ?>
					<?php do_action('woocommerce_no_products_found'); ?>
				<?php endif; ?>

			</div>
		</div>
	</div>
</main>
<?php
do_action('woocommerce_after_main_content');
do_action('woocommerce_sidebar');

get_footer('shop');
