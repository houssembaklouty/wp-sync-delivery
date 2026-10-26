<?php
/**
 * Carte produit dans les listes.
 *
 * Le wrapper de colonne fait partie de la carte : c'est lui qui pilote la
 * grille (4 par ligne en xl, 3 en lg, 2 en mobile).
 *
 * Les cinq points d'extension de la boucle sont declenches : sans eux, toute
 * extension decorant la grille (liste d'envies, comparateur, apercu rapide,
 * badges, nuanciers...) restait sans effet. Ce que le theme rend lui-meme est
 * retire du hook dans functions.php.
 *
 * @package wparabic-ecomm-cod
 * @version 9.4.0
 */

defined('ABSPATH') || exit;

global $product;

if (empty($product)) {
	$product = wc_get_product(get_the_ID());
}

if (!$product instanceof WC_Product || !$product->is_visible()) {
	return;
}
?>
<div class="col-xl-3 col-lg-4 col-sm-6 col-6">
	<div <?php wc_product_class('item item-product', $product); ?>>
		<?php
		/**
		 * Hook : woocommerce_before_shop_loop_item.
		 */
		do_action('woocommerce_before_shop_loop_item');
		?>

		<?php if ($product->is_on_sale()) : ?>
			<span class="onsale"><?php esc_html_e('تخفيض!', 'wparabic-ecomm-cod'); ?></span>
		<?php endif; ?>

		<div class="item-thumb">
			<a href="<?php the_permalink(); ?>"><?php echo woocommerce_get_product_thumbnail(); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
			<?php
			/**
			 * Hook : woocommerce_before_shop_loop_item_title.
			 * Le badge promo et la vignette en sont retires (rendus ci-dessus).
			 */
			do_action('woocommerce_before_shop_loop_item_title');
			?>
		</div>

		<div class="item-content">
			<?php $categories = wc_get_product_category_list($product->get_id(), ', '); ?>
			<?php if ($categories) : ?>
				<div class="item-meta">
					<span class="item-categories"><?php echo wp_kses_post($categories); ?></span>
				</div>
			<?php endif; ?>

			<p class="item-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></p>
			<?php
			/**
			 * Hook : woocommerce_shop_loop_item_title.
			 * Le titre par defaut en est retire (rendu ci-dessus).
			 */
			do_action('woocommerce_shop_loop_item_title');
			?>

			<?php if ($product->get_average_rating() > 0) : ?>
				<div class="item-star-rating">
					<?php echo wc_get_rating_html($product->get_average_rating()); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
			<?php endif; ?>

			<span class="price"><?php echo wp_kses_post($product->get_price_html()); ?></span>
			<?php
			/**
			 * Hook : woocommerce_after_shop_loop_item_title.
			 * Note et prix par defaut en sont retires (rendus ci-dessus).
			 */
			do_action('woocommerce_after_shop_loop_item_title');
			?>

			<?php
			// Comme la reference : un lien vers la fiche produit, jamais un
			// ajout au panier AJAX (qui afficherait « اختيار الخيارات » sur les
			// produits variables). woocommerce_template_loop_add_to_cart est
			// donc retire du hook ci-dessous.
			?>
			<a href="<?php the_permalink(); ?>" class="button product_type_simple add_to_cart_button"><?php esc_html_e('اشتري الآن', 'wparabic-ecomm-cod'); ?></a>
			<?php
			/**
			 * Hook : woocommerce_after_shop_loop_item.
			 */
			do_action('woocommerce_after_shop_loop_item');
			?>
		</div>
	</div>
</div>
