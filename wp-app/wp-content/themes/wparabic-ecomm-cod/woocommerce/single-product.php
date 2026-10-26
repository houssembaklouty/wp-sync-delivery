<?php
/**
 * Fiche produit.
 *
 * Mise en page propre au theme (galerie a gauche, formulaire COD a droite),
 * mais TOUS les points d'extension standards de WooCommerce sont declenches :
 * c'est ce qui permet aux extensions de fonctionner et ce qui ramene le
 * fil d'Ariane, les donnees structurees, les onglets et les produits liés.
 *
 * Ce que le theme rend lui-meme (titre, note, prix, extrait, ajout au panier)
 * est retire du hook dans functions.php — pas de doublon.
 *
 * @package wparabic-ecomm-cod
 * @version 1.6.4
 */

defined('ABSPATH') || exit;

get_header('shop');

/**
 * Hook : woocommerce_before_main_content.
 * woocommerce_output_content_wrapper est retire (functions.php) — on garde le
 * fil d'Ariane et les donnees structurees du site.
 */
do_action('woocommerce_before_main_content');

while (have_posts()) :
    the_post();

    global $product;
    if (!$product instanceof WC_Product) {
        continue;
    }

    // Fonctionne aussi pour les produits variables, dont le parent ne porte
    // pas de get_regular_price(). Voir functions.php.
    $savings_pct = wparabic_savings_percent($product);

    $is_new = (time() - get_the_time('U')) < 30 * DAY_IN_SECONDS;

    /**
     * Hook : woocommerce_before_single_product.
     * Affiche les notices — le conteneur vide precedent ne servait a rien.
     */
    do_action('woocommerce_before_single_product');
    ?>
<main class="main">
    <div class="<?php echo esc_attr(wparabic_container_class()); ?>">
        <div class="row justify-content-center">
            <div class="col-12">
                <div id="product-<?php the_ID(); ?>" <?php wc_product_class('', $product); ?>>
                    <div class="row">
                        <div class="col-lg-5 col-12 product-img-gallery">
                            <?php
                            // Le badge « promo » est rendu dans la galerie
                            // (woocommerce/single-product/product-image.php).
                            do_action('woocommerce_before_single_product_summary');
                            ?>
                        </div>
                        <div class="col-lg-7 col-12 product-desc">
                            <div class="summary entry-summary entry-content">
                                <?php
                                // SKU / categories / etiquettes, a leur place
                                // d'origine ; retire du hook pour eviter le
                                // doublon plus bas.
                                woocommerce_template_single_meta();
                                ?>
                                <h1 class="product_title entry-title product_subheadline" itemprop="name"><?php the_title(); ?><?php if ($is_new) : ?><span><?php esc_html_e('جديد', 'wparabic-ecomm-cod'); ?></span><?php endif; ?></h1>
                                <?php woocommerce_template_single_rating(); ?>
                                <?php woocommerce_template_single_price(); ?>
                                <?php if ($savings_pct > 0) : ?>
                                    <p class="price-savings"><?php echo esc_html($savings_pct); ?>%</p>
                                <?php endif; ?>
                                <div class="woocommerce-product-details__short-description">
                                    <?php echo wp_kses_post(wpautop($product->get_short_description())); ?>
                                </div>
                                <?php wparabic_cod_form($product); ?>
                                <?php
                                /**
                                 * Hook : woocommerce_single_product_summary.
                                 * Titre, note, prix, extrait et ajout au panier
                                 * en ont ete retires (rendus ci-dessus ou
                                 * remplaces par le formulaire COD). Restent le
                                 * partage, les donnees structurees du produit
                                 * et tout ce qu'ajoutent les extensions.
                                 */
                                do_action('woocommerce_single_product_summary');
                                ?>
                            </div>
                            <div id="product-buy-form"></div>
                            <?php
                            // La description longue n'est plus repetee ici : elle
                            // s'affiche dans l'onglet natif « Description »
                            // (woocommerce_after_single_product_summary).
                            ?>
                        </div>
                    </div>
                </div>

                <div class="woocommerce-tabs-container">
                    <?php
                    /**
                     * Hook : woocommerce_after_single_product_summary.
                     * Onglets (10), ventes incitatives (15), produits lies (20).
                     * Remplace l'onglet « avis » qui etait ecrit a la main : les
                     * onglets natifs l'incluent ET acceptent ceux des extensions.
                     */
                    do_action('woocommerce_after_single_product_summary');
                    ?>
                </div>
            </div>
        </div>
    </div>
    <?php
    // Barre d'achat flottante, reglable dans Personnaliser -> (panneau du theme) ->
    // صفحة المنتج. Elle n'apparait que sur mobile et tablette, et seulement
    // quand le bouton de confirmation sort de l'ecran (assets/js/main.js).
    if (get_theme_mod('sticky_atc', true)) :
        ?>
        <div class="sticky-atc-btn"><a href="#codplugin-checkout"><?php esc_html_e('اشتري الآن', 'wparabic-ecomm-cod'); ?></a></div>
    <?php endif; ?>
</main>
    <?php
    do_action('woocommerce_after_single_product');

endwhile;

do_action('woocommerce_after_main_content');
do_action('woocommerce_sidebar');

get_footer('shop');
