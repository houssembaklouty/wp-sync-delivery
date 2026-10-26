<?php
/**
 * En-tete des archives produit.
 *
 * Surcharge du gabarit du coeur : meme structure et memes hooks, habilles des
 * classes de titre de section du theme (.section-heading / .section-title).
 * On garde ainsi le chemin standard — c'est ce gabarit qui est appele par
 * woocommerce_shop_loop_header — sans dupliquer l'en-tete.
 *
 * @package wparabic-ecomm-cod
 * @version 8.6.0
 */

defined('ABSPATH') || exit;
?>
<header class="woocommerce-products-header section-heading">
	<div class="section-heading-content">
		<?php if (apply_filters('woocommerce_show_page_title', true)) : ?>
			<h1 class="woocommerce-products-header__title page-title section-title"><?php woocommerce_page_title(); ?></h1>
		<?php endif; ?>

		<?php
		/**
		 * Hook : woocommerce_archive_description.
		 */
		do_action('woocommerce_archive_description');
		?>
	</div>
</header>
