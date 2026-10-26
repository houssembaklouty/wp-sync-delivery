<?php
/**
 * Pied de page.
 *
 * Note : ce fichier ne ferme PAS <main>. Chaque gabarit ouvre et ferme le sien.
 *
 * @package wparabic-ecomm-cod
 */

defined('ABSPATH') || exit;

$phone     = wparabic_mod('contact_phone');
$phone_tel = preg_replace('/[^0-9+]/', '', $phone);
?>
<footer class="footer">
	<div class="footer-widgets">
		<?php // Pleine largeur en dur : le pied ne suit pas le reglage du contenu. ?>
		<div class="container-fluid">
			<div class="row">

				<div class="col-lg-4 col-12">
					<?php if (is_active_sidebar('footer-1')) : ?>
						<?php dynamic_sidebar('footer-1'); ?>
					<?php else : ?>
						<aside class="widget widget_nav_menu">
							<h3 class="widget-title"><?php esc_html_e('عن المتجر', 'wparabic-ecomm-cod'); ?></h3>
							<ul class="menu">
								<li class="menu-item"><a href="<?php echo esc_url(home_url('/من-نحن/')); ?>"><?php esc_html_e('من نحن', 'wparabic-ecomm-cod'); ?></a></li>
								<li class="menu-item"><a href="<?php echo esc_url(home_url('/طرق-الدفع/')); ?>"><?php esc_html_e('طرق الدفع', 'wparabic-ecomm-cod'); ?></a></li>
								<li class="menu-item"><a href="<?php echo esc_url(home_url('/الشحن-والتسليم/')); ?>"><?php esc_html_e('الشحن والتسليم', 'wparabic-ecomm-cod'); ?></a></li>
							</ul>
						</aside>
						<aside class="widget widget_nav_menu">
							<h3 class="widget-title"><?php esc_html_e('الشروط والسياسات', 'wparabic-ecomm-cod'); ?></h3>
							<ul class="menu">
								<li class="menu-item"><a href="<?php echo esc_url(home_url('/شروط-الاستخدام/')); ?>"><?php esc_html_e('شروط الاستخدام', 'wparabic-ecomm-cod'); ?></a></li>
								<li class="menu-item"><a href="<?php echo esc_url(home_url('/سياسة-الخصوصية/')); ?>"><?php esc_html_e('سياسة الخصوصية', 'wparabic-ecomm-cod'); ?></a></li>
								<li class="menu-item"><a href="<?php echo esc_url(home_url('/refund_returns-2/')); ?>"><?php esc_html_e('سياسة الإستبدال والإسترجاع', 'wparabic-ecomm-cod'); ?></a></li>
							</ul>
						</aside>
					<?php endif; ?>
				</div>

				<div class="col-lg-4 col-12">
					<?php if (is_active_sidebar('footer-2')) : ?>
						<?php dynamic_sidebar('footer-2'); ?>
					<?php else : ?>
						<aside class="widget widget_nav_menu">
							<h3 class="widget-title"><?php esc_html_e('تواصل معنا', 'wparabic-ecomm-cod'); ?></h3>
							<ul class="menu">
								<li class="menu-item"><a href="<?php echo esc_url(home_url('/أسئلة-شائعة/')); ?>"><?php esc_html_e('أسئلة شائعة', 'wparabic-ecomm-cod'); ?></a></li>
								<li class="menu-item"><a href="<?php echo esc_url(home_url('/اتصل-بنا/')); ?>"><?php esc_html_e('اتصل بنا', 'wparabic-ecomm-cod'); ?></a></li>
								<?php if ($phone_tel) : ?>
									<li class="menu-item"><a href="tel:<?php echo esc_attr($phone_tel); ?>"><?php esc_html_e('هاتف', 'wparabic-ecomm-cod'); ?> : <?php echo wparabic_ltr_phone($phone); // phpcs:ignore WordPress.Security.EscapeOutput -- echappe dans la fonction ?></a></li>
								<?php endif; ?>
							</ul>
						</aside>
						<aside class="widget widget_at-socials">
							<h3 class="widget-title"><?php esc_html_e('تابعنا على', 'wparabic-ecomm-cod'); ?></h3>
							<ul class="list-social-icons">
								<?php
								$socials = array(
									'facebook'  => array(wparabic_mod('social_facebook'), 'fa-facebook-f'),
									'instagram' => array(wparabic_mod('social_instagram'), 'fa-instagram'),
									'tiktok'    => array(wparabic_mod('social_tiktok'), 'fa-tiktok'),
								);
								foreach ($socials as $network => $data) :
									list($url, $icon) = $data;
									?>
									<li>
										<a href="<?php echo esc_url($url); ?>" class="social-icon" aria-label="<?php echo esc_attr(sprintf(__('رابط لـ %s', 'wparabic-ecomm-cod'), $network)); ?>" target="_blank" rel="noopener">
											<i class="fab <?php echo esc_attr($icon); ?>"></i>
										</a>
									</li>
								<?php endforeach; ?>
							</ul>
						</aside>
					<?php endif; ?>
				</div>

				<div class="col-lg-4 col-12">
					<?php if (is_active_sidebar('footer-3')) : ?>
						<?php dynamic_sidebar('footer-3'); ?>
					<?php else : ?>
						<aside class="widget_text widget widget_custom_html">
							<div class="textwidget custom-html-widget">
								<div class="site-logo"><a href="<?php echo esc_url(home_url('/')); ?>" rel="home"><?php bloginfo('name'); ?></a></div>
								<?php $footer_text = wparabic_mod_i18n('footer_text'); ?>
								<?php if ($footer_text) : ?>
									<p style="margin:10px 0;"><?php echo esc_html($footer_text); ?></p>
								<?php endif; ?>
							</div>
						</aside>
					<?php endif; ?>
				</div>

			</div>
		</div>
	</div>

	<div class="footer-info">
		<div class="container-fluid">
			<div class="row align-items-center">
				<div class="col-sm-6">
					<div class="footer-copyrights">&copy; <?php echo esc_html(wp_date('Y')); ?> <?php bloginfo('name'); ?></div>
				</div>
				<div class="col-sm-6">
					<?php $credit = wparabic_mod_i18n('footer_credit'); ?>
					<?php if ($credit) : ?>
						<p class="footer-credit"><?php echo wp_kses_post($credit); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</footer>

<?php if ($phone_tel) : ?>
<a href="tel:<?php echo esc_attr($phone_tel); ?>" class="callus-icon"><i class="fas fa-phone-volume"></i></a>
<?php endif; ?>

</div><!-- #page -->
<?php wp_footer(); ?>
</body>
</html>
