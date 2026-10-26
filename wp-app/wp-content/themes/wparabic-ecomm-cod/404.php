<?php
/**
 * Gabarit 404.
 *
 * @package wparabic-ecomm-cod
 */

defined('ABSPATH') || exit;

get_header();
?>
<main class="main">
	<div class="<?php echo esc_attr(wparabic_container_class()); ?>">
		<div class="row justify-content-center">
			<div class="col-12">
				<div class="section-heading">
					<div class="section-heading-content">
						<h1 class="section-title"><?php esc_html_e('الصفحة غير موجودة', 'wparabic-ecomm-cod'); ?></h1>
						<p class="section-subtitle"><?php esc_html_e('يبدو أن الرابط الذي تبحث عنه غير متوفر.', 'wparabic-ecomm-cod'); ?></p>
					</div>
				</div>
				<p class="return-to-shop">
					<a class="button wc-backward" href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/')); ?>">
						<?php esc_html_e('العودة إلى المتجر', 'wparabic-ecomm-cod'); ?>
					</a>
				</p>
			</div>
		</div>
	</div>
</main>
<?php
get_footer();
