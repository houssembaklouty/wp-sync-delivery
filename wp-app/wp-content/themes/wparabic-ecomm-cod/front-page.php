<?php
/**
 * Page d'accueil.
 *
 * Chaque section est un widget depose dans la zone « أقسام الصفحة الرئيسية »
 * (Apparence -> Widgets). L'ordre a l'ecran suit l'ordre des widgets.
 * Voir inc/home-widgets.php.
 *
 * @package wparabic-ecomm-cod
 */

defined('ABSPATH') || exit;

get_header();
?>
<main class="main widget-sections">
	<?php
	/*
	 * Contenu de la page choisie comme accueil (Pages -> الرئيسية).
	 * Sans cela, l'editer dans wp-admin ne produirait rien du tout.
	 * Meme enveloppe que before_widget de la zone (functions.php), donc
	 * alignement identique aux autres sections sans une ligne de CSS.
	 */
	if (get_option('show_on_front') === 'page' && have_posts()) :
		the_post();

		if (trim(get_the_content()) !== '') :
			?>
			<section id="front-page-content" class="widget-section widget_front-page-content">
				<div class="<?php echo esc_attr(wparabic_container_class()); ?>">
					<div class="row">
						<div class="col-12">
							<?php the_content(); ?>
						</div>
					</div>
				</div>
			</section>
			<?php
		endif;

		// Les widgets qui suivent lancent leurs propres WP_Query et appellent
		// wp_reset_postdata() : on leur rend une boucle principale intacte.
		rewind_posts();
	endif;

	// <main> reste ici plutot que dans before_sidebar : ce dernier n'est
	// imprime que si la zone contient des widgets, le repli perdrait donc son
	// enveloppe.
	if (is_active_sidebar('widget-sections')) {
		dynamic_sidebar('widget-sections');
	} elseif (function_exists('wparabic_render_home_default_sections')) {
		// Filet de securite : zone vide ou pas encore peuplee.
		wparabic_render_home_default_sections();
	}
	?>
</main>
<?php
get_footer();
