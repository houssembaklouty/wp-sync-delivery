<?php
/**
 * Colonne laterale.
 *
 * Appelee par get_sidebar(), donc par le hook woocommerce_sidebar sur les
 * pages boutique. Sans ce fichier, WordPress chargeait son gabarit de
 * compatibilite herite (wp-includes/theme-compat/sidebar.php) : un bloc
 * « Search / Pages / Archives / Categories » etranger au theme, precede d'une
 * notice de depreciation.
 *
 * La zone « Colonne laterale » est vide par defaut : rien ne s'affiche.
 * Pour activer ces sections, deposer des widgets dans
 * Apparence -> Widgets -> Colonne laterale.
 *
 * @package wparabic-ecomm-cod
 */

defined('ABSPATH') || exit;

if (!is_active_sidebar('sidebar-1')) {
    return;
}
?>
<div class="<?php echo esc_attr(wparabic_container_class()); ?>">
	<div class="row">
		<div class="col-12">
			<aside class="sidebar widget-area" role="complementary">
				<?php dynamic_sidebar('sidebar-1'); ?>
			</aside>
		</div>
	</div>
</div>
