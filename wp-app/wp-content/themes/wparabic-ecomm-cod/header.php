<?php
/**
 * En-tete du site.
 *
 * @package wparabic-ecomm-cod
 */

defined('ABSPATH') || exit;

$topbar_msgs = wparabic_mod_i18n('topbar_msg');

$cart_count = (function_exists('WC') && WC() && WC()->cart) ? WC()->cart->get_cart_contents_count() : 0;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div id="page">
<header class="header ">

	<?php if ($topbar_msgs) : ?>
	<div class="top-head-wrap clear">
		<?php // Pleine largeur en dur : l'en-tete ne suit pas le reglage du contenu. ?>
		<div class="container-fluid">
			<?php
			// Defilement continu (marquee) : assets/js/main.js duplique le
			// groupe pour une boucle sans saut et regle vitesse/sens (RTL/LTR).
			// Sans JS ou avec « reduire les animations » : texte fixe.
			?>
			<div class="top-head-center top-marquee" data-speed="<?php echo esc_attr(absint(get_theme_mod('topbar_speed', 50))); ?>">
				<div class="top-marquee-track">
					<div class="top-marquee-group">
						<?php foreach (array_filter(array_map('trim', explode('|', $topbar_msgs))) as $msg) : ?>
							<span class="top-marquee-item"><?php echo wparabic_ltr_phones($msg); // phpcs:ignore WordPress.Security.EscapeOutput -- echappe dans la fonction ?></span>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<div class="head-mast head-sticky ">
		<div class="head-mast-container">
			<div class="head-mast-row  ">

				<div class="header-branding-wrap">
					<div class="mobile-nav-trigger">
						<i class="fas fa-bars"></i> <span class="sr-only"><?php esc_html_e('القائمة', 'wparabic-ecomm-cod'); ?></span>
					</div>
					<div class="site-branding">
						<div class="site-logo">
							<?php if (has_custom_logo()) : ?>
								<?php the_custom_logo(); ?>
							<?php else : ?>
								<a href="<?php echo esc_url(home_url('/')); ?>" rel="home"><?php bloginfo('name'); ?></a>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<div class="head-nav ">
					<nav class="nav">
						<?php
						wp_nav_menu(array(
							'theme_location' => wparabic_menu_location('primary'),
							'container'      => false,
							'menu_id'        => 'header-menu-1',
							'menu_class'     => 'navigation-main',
							'fallback_cb'    => false,
						));
						?>
					</nav>
				</div>

				<div class="head-icons">
					<?php wparabic_language_switcher('header'); ?>
					<div class="head-mini-cart-wrap">
						<div class="header-mini-cart">
							<a href="#" class="header-mini-cart-trigger">
								<i class="fas fa-shopping-cart"></i><span class="cart-count"><?php echo esc_html($cart_count); ?></span>
							</a>
							<div class="header-mini-cart-contents">
								<aside class="widget woocommerce widget_shopping_cart">
									<h3 class="widget-title"><?php esc_html_e('سلة التسوق', 'wparabic-ecomm-cod'); ?></h3>
									<div class="widget_shopping_cart_content">
										<?php if (function_exists('woocommerce_mini_cart')) { woocommerce_mini_cart(); } ?>
									</div>
								</aside>
							</div>
						</div>
					</div>
					<div class="header-search-icon">
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" role="img" class="sellzy-open-search fill-current"><path fill-rule="evenodd" clip-rule="evenodd" d="M10.6002 12.0498C9.49758 12.8568 8.13777 13.3333 6.66667 13.3333C2.98477 13.3333 0 10.3486 0 6.66667C0 2.98477 2.98477 0 6.66667 0C10.3486 0 13.3333 2.98477 13.3333 6.66667C13.3333 8.15637 12.8447 9.53194 12.019 10.6419C12.0265 10.6489 12.0338 10.656 12.0411 10.6633L15.2935 13.9157C15.6841 14.3063 15.6841 14.9394 15.2935 15.33C14.903 15.7205 14.2699 15.7205 13.8793 15.33L10.6269 12.0775C10.6178 12.0684 10.6089 12.0592 10.6002 12.0498ZM11.3333 6.66667C11.3333 9.244 9.244 11.3333 6.66667 11.3333C4.08934 11.3333 2 9.244 2 6.66667C2 4.08934 4.08934 2 6.66667 2C9.244 2 11.3333 4.08934 11.3333 6.66667Z"></path></svg>
					</div>
				</div>

				<?php
				// Nom et prix du produit : masques par defaut, reveles au defilement
				// sur la fiche produit (voir assets/js/main.js).
				if (function_exists('is_product') && is_product()) :
					$header_product = wc_get_product(get_the_ID());
					if ($header_product instanceof WC_Product) :
						?>
						<div class="product-name">
							<?php echo esc_html($header_product->get_name()); ?>
							<?php echo wc_get_rating_html($header_product->get_average_rating()); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</div>
						<div class="product-price"><?php echo wp_kses_post($header_product->get_price_html()); ?></div>
						<?php
					endif;
				endif;
				?>

			</div>
		</div>

		<?php get_template_part('template-parts/search-form', null, array('uid' => 'header')); ?>

	</div>
</header>

<div id="mobilemenu">
	<div class="close-btn clear">
		<div class="bar"></div>
		<div class="bar"></div>
	</div>
	<?php get_template_part('template-parts/search-form', null, array('uid' => 'mobile')); ?>
	<?php wparabic_language_switcher('mobile'); ?>
	<?php
	wp_nav_menu(array(
		'theme_location' => wparabic_menu_location('primary'),
		'container'      => false,
		'menu_id'        => 'menu-main',
		'menu_class'     => 'mobile-menu-list',
		'fallback_cb'    => false,
	));
	?>
</div>
<div class="fly-menu-fade"></div>
