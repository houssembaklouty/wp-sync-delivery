<?php
/**
 * Formulaire de recherche par categorie.
 *
 * Rendu deux fois par page (en-tete + menu mobile). Le CSS ne cible que des
 * classes, jamais ces id — on les suffixe donc pour garder un document valide
 * la ou le theme de reference duplique ses id.
 *
 * @package wparabic-ecomm-cod
 */

defined('ABSPATH') || exit;

$uid = isset($args['uid']) ? $args['uid'] : 'header';
?>
<div class="head-search-form-wrap">
	<div class="head-search-container">
		<form class="category-search-form form-ajax-enabled" action="<?php echo esc_url(home_url('/')); ?>" method="get">
			<label for="category-search-name-<?php echo esc_attr($uid); ?>" class="sr-only"><?php esc_html_e('اسم التصنيف', 'wparabic-ecomm-cod'); ?></label>
			<select name="product_cat" id="category-search-name-<?php echo esc_attr($uid); ?>" class="category-search-select">
				<option value=""><?php esc_html_e('جميع التصنيفات', 'wparabic-ecomm-cod'); ?></option>
				<?php echo wparabic_product_cat_options(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</select>
			<div class="category-search-input-wrap">
				<label for="category-search-input-<?php echo esc_attr($uid); ?>" class="sr-only"><?php esc_html_e('نص البحث', 'wparabic-ecomm-cod'); ?></label>
				<input
					type="text"
					class="category-search-input"
					id="category-search-input-<?php echo esc_attr($uid); ?>"
					placeholder="<?php esc_attr_e('ما الذي تبحث عنه؟', 'wparabic-ecomm-cod'); ?>"
					name="s"
					autocomplete="off"
				/>
				<ul class="category-search-results">
					<li class="category-search-results-item">
						<a href="">
							<span class="category-search-results-item-title"></span>
						</a>
					</li>
				</ul>
				<span class="category-search-spinner"></span>
				<input type="hidden" name="post_type" value="product" />
			</div>
			<button type="submit" class="category-search-btn">
				<i class="fas fa-search"></i><span class="sr-only"><?php esc_html_e('بحث', 'wparabic-ecomm-cod'); ?></span>
			</button>
		</form>
	</div>
</div>
