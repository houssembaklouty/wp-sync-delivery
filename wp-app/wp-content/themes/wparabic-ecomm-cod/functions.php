<?php
/**
 * WPArabicEcommCOD — fonctions du theme.
 *
 * @package wparabic-ecomm-cod
 */

defined('ABSPATH') || exit;

define('WPARABIC_VERSION', '3.14.0');
define('WPARABIC_PRIMARY_DEFAULT', '#259bea');
define('WPARABIC_ACCENT_DEFAULT', '#ea593c');

// Langue de la boutique : charge en premier, la langue doit etre fixee avant
// le chargement des traductions du theme et des extensions.
require get_template_directory() . '/inc/languages.php';
define('WPARABIC_ASSETS', get_template_directory_uri() . '/assets');

/* -------------------------------------------------------------------------
 * Mise en place du theme
 * ------------------------------------------------------------------------- */

add_action('after_setup_theme', 'wparabic_setup_theme');
function wparabic_setup_theme() {
    load_theme_textdomain('wparabic-ecomm-cod', get_template_directory() . '/languages');

    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('automatic-feed-links');
    add_theme_support('html5', array('search-form', 'gallery', 'caption', 'style', 'script'));
    add_theme_support('woocommerce');
    add_theme_support('wc-product-gallery-zoom');
    add_theme_support('wc-product-gallery-lightbox');
    add_theme_support('wc-product-gallery-slider');
    add_theme_support('custom-logo', array(
        'height'      => 131,
        'width'       => 394,
        'flex-height' => true,
        'flex-width'  => true,
    ));

    // Les vignettes produit suivent WooCommerce > Images du produit
    // (taille woocommerce_thumbnail) : aucune taille propre au theme.

    register_nav_menus(array(
        'primary' => __('Menu principal', 'wparabic-ecomm-cod'),
        'mobile'  => __('Menu mobile', 'wparabic-ecomm-cod'),
    ));

    // Un menu par langue de la boutique (facultatif) : vide, « primary » sert.
    foreach (wparabic_languages() as $code => $lang) {
        register_nav_menus(array(
            /* translators: %s: language name */
            'primary_' . $code => sprintf(__('Menu principal — %s', 'wparabic-ecomm-cod'), $lang['label']),
        ));
    }
}

/* -------------------------------------------------------------------------
 * Identite et valeurs par defaut
 *
 * Le theme est generique : aucune marque n'y est ecrite en dur. Le nom affiche
 * dans l'admin vient du titre du site (Reglages -> General), et les reglages
 * partent tous vides ou neutres — c'est au marchand de les remplir depuis le
 * personnalisateur.
 * ------------------------------------------------------------------------- */

/**
 * Nom a afficher dans l'admin (panneau du personnalisateur, noms de widgets).
 *
 * Libelle generique et traduisible, independant de la boutique : le theme
 * reste neutre quand il est installe sur un autre site. Le nom de la boutique
 * (Reglages > General > Titre du site) reste affiche cote visiteurs (logo
 * texte, copyright).
 *
 * @return string
 */
function wparabic_brand() {
    return __('خيارات القالب', 'wparabic-ecomm-cod');
}

/**
 * Nom d'un widget du theme, prefixe par le libelle generique du theme.
 *
 * Les regroupe dans la liste d'Apparence -> Widgets, ou ils voisinent avec ceux
 * de WordPress et des extensions.
 *
 * @param string $label Libelle de la section, deja traduit.
 * @return string
 */
function wparabic_widget_name($label) {
    return sprintf(
        /* translators: 1: libelle du theme (« خيارات القالب »), 2: nom de la section. */
        _x('%1$s — %2$s', 'nom de widget', 'wparabic-ecomm-cod'),
        wparabic_brand(),
        $label
    );
}

/**
 * Valeur par defaut d'un reglage du theme.
 *
 * Ces defauts etaient auparavant ecrits DEUX fois — dans inc/customizer.php et
 * au point de lecture (header.php, footer.php, wp_localize_script). Toute
 * retouche faisait diverger les deux copies. Source unique desormais.
 *
 * @param string $key
 * @return string
 */
function wparabic_default($key) {
    $defaults = array(
        // Vides : renseignes par le marchand. Les gabarits masquent ce qui
        // s'y rattache tant qu'ils le sont (bouton d'appel, bouton WhatsApp).
        'contact_phone' => '',
        'whatsapp'      => '',
        'footer_text'   => '',

        'topbar_msg'    => __('أطلب الآن والدفع فقط عند استلام المنتج|توصيل سريع لجميع الولايات', 'wparabic-ecomm-cod'),

        // Attribution de licence, pas une donnee de marchand : le design vient
        // du theme GPL « hanout ». A garder tant que ce design est utilise.
        'footer_credit' => __('يستخدم قالب WPArabicEcommCOD', 'wparabic-ecomm-cod'),

        'social_facebook'  => '#',
        'social_instagram' => '#',
        'social_tiktok'    => '#',
    );

    return isset($defaults[$key]) ? $defaults[$key] : '';
}

/**
 * Raccourci : get_theme_mod() avec le defaut du theme.
 *
 * @param string $key
 * @return string
 */
function wparabic_mod($key) {
    return get_theme_mod($key, wparabic_default($key));
}

/**
 * Texte saisi dans le personnalisateur, dans la langue de la boutique.
 *
 * Chaque texte traduisible a une variante par langue (cle « {cle}__{langue} »,
 * ex. topbar_msg__fr). Vide : repli sur le texte de base.
 *
 * @param string $key
 * @return string
 */
function wparabic_mod_i18n($key) {
    $variant = get_theme_mod($key . '__' . wparabic_current_lang(), '');
    return ($variant !== '' && $variant !== null) ? $variant : wparabic_mod($key);
}

/** Textes du personnalisateur qui ont une variante par langue. */
function wparabic_i18n_mods() {
    return array('topbar_msg', 'footer_text', 'footer_credit');
}

/**
 * Emplacement de menu dans la langue de la boutique : « primary_fr » s'il a
 * un menu assigne, sinon « primary ».
 *
 * @param string $location
 * @return string
 */
function wparabic_menu_location($location) {
    $localized = $location . '_' . wparabic_current_lang();
    return has_nav_menu($localized) ? $localized : $location;
}

/**
 * Numero WhatsApp effectif, au format international sans « + » (wa.me).
 *
 * Le champ « رقم واتساب » du personnalisateur s'il est rempli, sinon le
 * numero de telephone du site (« رقم الهاتف »). Un numero tunisien a 8
 * chiffres recoit l'indicatif 216 ; un prefixe 00 est retire.
 *
 * @return string Chiffres seuls, ou '' si aucun numero n'est renseigne.
 */
function wparabic_whatsapp_number() {
    $number = preg_replace('/[^0-9]/', '', (string) wparabic_mod('whatsapp'));

    if ($number === '') {
        $number = preg_replace('/[^0-9]/', '', (string) wparabic_mod('contact_phone'));
    }

    if (strpos($number, '00') === 0) {
        $number = substr($number, 2);
    }

    if (strlen($number) === 8) {
        $number = '216' . $number;
    }

    return $number;
}

/* -------------------------------------------------------------------------
 * Largeur du contenu
 * ------------------------------------------------------------------------- */

/**
 * Classe de conteneur a emettre pour l'emplacement courant.
 *
 * Le reglage vit dans Personnaliser -> (panneau du theme) -> التخطيط, un par
 * emplacement.
 * « container-fluid » n'a que des marges dans assets/css/vendor.css, jamais de
 * largeur : c'est nativement la pleine largeur. « container » suit les paliers
 * vendores (540/720/940/1130).
 *
 * @param string|null $context « home », « shop », « product », « default ».
 *                             Null : deduit de la requete.
 * @return string
 */
function wparabic_container_class($context = null) {

    if ($context === null) {
        if (is_front_page()) {
            $context = 'home';
        } elseif (function_exists('is_shop') && is_shop()) {
            // Faux sur une archive de categorie : celles-ci suivent donc le
            // reglage « reste des pages », comme le panier ou les pages.
            $context = 'shop';
        } elseif (function_exists('is_product') && is_product()) {
            $context = 'product';
        } else {
            $context = 'default';
        }
    }

    return get_theme_mod('layout_' . $context, 'fluid') === 'boxed'
        ? 'container'
        : 'container-fluid';
}

/* -------------------------------------------------------------------------
 * Zones de widgets
 * ------------------------------------------------------------------------- */

/**
 * Ecran des widgets classique (glisser-deposer) plutot que l'editeur de blocs.
 *
 * L'editeur de blocs transforme chaque widget classique en bloc « Widget
 * herite » : formulaire charge en AJAX, reordonnancement par fleches, et
 * surtout formulaire remonte a chaque changement, ce qui peut perdre une image
 * en cours de selection. Les quatre autres zones (footer-1/2/3, sidebar-1)
 * sont vides, le cout est donc nul. Reversible : supprimer cette ligne.
 */
add_filter('use_widgets_block_editor', '__return_false');

add_action('widgets_init', 'wparabic_widgets_init');
function wparabic_widgets_init() {

    // Les sections de la page d'accueil.
    // before_widget porte le conteneur de grille : un widget tiers (Texte, HTML
    // personnalise...) depose ici est donc aligne comme les sections du theme,
    // sans une ligne de CSS.
    //
    // Le contexte est passe en dur : widgets_init s'execute avant que la
    // requete soit resolue, is_front_page() y renverrait toujours faux. Ce
    // n'est pas une approximation — cette zone n'est rendue que par
    // front-page.php. get_theme_mod(), lui, fonctionne bien a ce stade.
    register_sidebar(array(
        'id'            => 'widget-sections',
        'name'          => __('أقسام الصفحة الرئيسية', 'wparabic-ecomm-cod'),
        'description'   => __('اسحب الأقسام هنا ورتّبها من الأعلى إلى الأسفل. إذا أفرغت هذه المنطقة تُعرض الأقسام الافتراضية.', 'wparabic-ecomm-cod'),
        'before_widget' => '<section id="%1$s" class="widget-section %2$s"><div class="' . esc_attr(wparabic_container_class('home')) . '"><div class="row"><div class="col-12">',
        'after_widget'  => '</div></div></div></section>',
        'before_title'  => '<div class="section-heading"><div class="section-heading-content"><h2 class="section-title">',
        'after_title'   => '</h2></div></div>',
    ));

    $common = array(
        'before_widget' => '<aside id="%1$s" class="widget %2$s">',
        'after_widget'  => '</aside>',
        'before_title'  => '<h3 class="widget-title">',
        'after_title'   => '</h3>',
    );

    foreach (array(1, 2, 3) as $i) {
        register_sidebar($common + array(
            'id'          => 'footer-' . $i,
            'name'        => sprintf(__('Pied de page %d', 'wparabic-ecomm-cod'), $i),
            'description' => __('Colonne du pied de page.', 'wparabic-ecomm-cod'),
        ));
    }

    register_sidebar($common + array(
        'id'          => 'sidebar-1',
        'name'        => __('Colonne laterale', 'wparabic-ecomm-cod'),
        'description' => __('Affichee sur les pages et les articles.', 'wparabic-ecomm-cod'),
    ));
}

/* -------------------------------------------------------------------------
 * Feuilles de style et scripts
 *
 * Ordre volontaire :
 *   1. vendor.css  — normalize + grille, doit passer en premier
 *   2. style.css   — le theme vendore (URLs relatives a la racine du theme)
 *   3. blocks.css  — blocs Gutenberg
 *   4. cod-form.css — SITE-WIDE : contient des regles globales select{} et
 *      table{} qui stylent le menu deroulant de categories du header partout.
 *   5. overrides.css — nos surcharges
 * WordPress imprime ensuite rtl.css a wp_head:10 (get_locale_stylesheet_uri),
 * puis wparabic_inline_css() passe a la priorite 20 pour gagner sur rtl.css.
 * ------------------------------------------------------------------------- */

/**
 * Version d'un fichier du theme = version du theme + date de modification.
 * Sans cela l'URL restait « ?ver=3.4.0 » apres chaque modification et les
 * navigateurs gardaient l'ancienne feuille en cache.
 */
function wparabic_asset_ver($relative) {
    $path = get_template_directory() . '/' . $relative;
    return WPARABIC_VERSION . (file_exists($path) ? '.' . filemtime($path) : '');
}

add_action('wp_enqueue_scripts', 'wparabic_enqueue');
function wparabic_enqueue() {

    wp_enqueue_style('cairo', get_template_directory_uri() . '/fonts/cairo/cairo.css', array(), wparabic_asset_ver('fonts/cairo/cairo.css'));
    wp_enqueue_style('hanout-vendor', WPARABIC_ASSETS . '/css/vendor.css', array(), wparabic_asset_ver('assets/css/vendor.css'));
    wp_enqueue_style('wparabic-style', get_stylesheet_uri(), array('hanout-vendor'), wparabic_asset_ver('style.css'));
    wp_enqueue_style('hanout-blocks', WPARABIC_ASSETS . '/css/blocks.css', array('wparabic-style'), wparabic_asset_ver('assets/css/blocks.css'));
    // Font Awesome Free 6.5.1 auto-heberge (assets/vendor/fontawesome) : plus de
    // dependance a un CDN. Seuls les styles utilises sont charges : solid
    // (fas) et brands (fab). Licence : assets/vendor/fontawesome/LICENSE.txt.
    wp_enqueue_style('fontawesome', WPARABIC_ASSETS . '/vendor/fontawesome/css/fontawesome.min.css', array(), '6.5.1');
    wp_enqueue_style('fontawesome-solid', WPARABIC_ASSETS . '/vendor/fontawesome/css/solid.min.css', array('fontawesome'), '6.5.1');
    wp_enqueue_style('fontawesome-brands', WPARABIC_ASSETS . '/vendor/fontawesome/css/brands.min.css', array('fontawesome'), '6.5.1');
    wp_enqueue_style('wparabic-cod', WPARABIC_ASSETS . '/css/cod-form.css', array('wparabic-style'), wparabic_asset_ver('assets/css/cod-form.css'));
    wp_enqueue_style('wparabic-overrides', WPARABIC_ASSETS . '/css/overrides.css', array('wparabic-cod'), wparabic_asset_ver('assets/css/overrides.css'));

    // wc-add-to-cart-variation n'est jamais mis en file par WooCommerce ici :
    // le theme ne declenche pas woocommerce_single_product_summary. On le
    // charge explicitement et on s'en declare dependant, pour que main.js
    // s'execute toujours apres.
    $main_deps = array('jquery');

    if (function_exists('is_product') && is_product() && wp_script_is('wc-add-to-cart-variation', 'registered')) {
        wp_enqueue_script('wc-add-to-cart-variation');
        $main_deps[] = 'wc-add-to-cart-variation';
    }

    wp_enqueue_script('wparabic-main', WPARABIC_ASSETS . '/js/main.js', $main_deps, wparabic_asset_ver('assets/js/main.js'), true);

    if (is_singular() && comments_open() && get_option('thread_comments')) {
        wp_enqueue_script('comment-reply');
    }

    $data = array(
        'url'        => admin_url('admin-ajax.php'),
        'nonce'      => wp_create_nonce('codplugin_nonce'),
        'no_results' => __('لا توجد نتائج', 'wparabic-ecomm-cod'),
        'currency'   => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '',
        'decimals'   => function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2,
        'whatsapp'   => wparabic_whatsapp_number(),
        'i18n'       => array(
            'want_order'   => __('أريد طلب', 'wparabic-ecomm-cod'),
            'order_failed' => __('تعذر إتمام الطلب', 'wparabic-ecomm-cod'),
            'retry'        => __('يرجى المحاولة مرة أخرى أو الاتصال بنا.', 'wparabic-ecomm-cod'),
            'send_failed'  => __('تعذر إرسال الطلب. يرجى تحديث الصفحة والمحاولة مرة أخرى.', 'wparabic-ecomm-cod'),
        ),
    );

    // ~13 Ko : seule la fiche produit en a besoin, et seulement si le champ
    // « المعتمدية » est affiche (Personnaliser -> (panneau du theme) -> حقول نموذج الطلب).
    if (function_exists('is_product') && is_product() && wparabic_cod_field_enabled('city')) {
        $data['delegations'] = wparabic_get_delegations();
    }

    wp_localize_script('wparabic-main', 'wparabic_ajax', $data);
}

/* -------------------------------------------------------------------------
 * CSS injecte par le customizer.
 * Le theme de reference n'utilise pas de custom properties : il surcharge des
 * selecteurs concrets. On reproduit exactement ce motif.
 * ------------------------------------------------------------------------- */

add_action('wp_head', 'wparabic_inline_css', 20);
/**
 * Melange une couleur hex avec du blanc. $ratio = part de blanc (0..1).
 */
function wparabic_tint($hex, $ratio) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $out = '#';
    foreach (str_split($hex, 2) as $c) {
        $v    = hexdec($c);
        $out .= str_pad(dechex((int) round($v + (255 - $v) * $ratio)), 2, '0', STR_PAD_LEFT);
    }
    return $out;
}

/**
 * Couleur hex -> rgba() avec alpha (voile des tuiles).
 * Alpha 0 => transparent (voile desactive).
 */
function wparabic_hex_rgba($hex, $alpha) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $a = max(0, min(1, (float) $alpha));

    return $a <= 0 ? 'transparent' : sprintf('rgb(%d %d %d / %d%%)', $r, $g, $b, (int) round($a * 100));
}

/**
 * Couleur de texte lisible sur un fond donne : sombre sur fond clair, claire
 * sur fond sombre (luminance relative WCAG).
 */
function wparabic_contrast_text($bg, $dark = '#333333', $light = '#ffffff') {
    $hex = ltrim((string) $bg, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6) {
        return $dark;
    }
    $lum = 0;
    foreach (array(0.2126, 0.7152, 0.0722) as $i => $weight) {
        $c    = hexdec(substr($hex, $i * 2, 2)) / 255;
        $c    = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        $lum += $weight * $c;
    }
    return $lum > 0.179 ? $dark : $light;
}

/**
 * Couleur du texte du pied de page : celle choisie dans le personnalisateur,
 * sinon automatique selon le fond (wparabic_contrast_text).
 */
function wparabic_footer_text_color($footer_bg) {
    $chosen = sanitize_hex_color(get_theme_mod('footer_text_color', ''));
    return $chosen ? $chosen : wparabic_contrast_text($footer_bg);
}

/**
 * Ratio d'affichage des vignettes produit, d'apres Personnaliser >
 * WooCommerce > Images du produit > Recadrage des vignettes.
 * « 1 / 1 », « 3 / 4 »... ou « auto » si les vignettes ne sont pas recadrees.
 *
 * @return string
 */
function wparabic_thumb_ratio() {
    $cropping = get_option('woocommerce_thumbnail_cropping', '1:1');

    if ($cropping === 'uncropped') {
        return 'auto';
    }
    if ($cropping === 'custom') {
        $w = absint(get_option('woocommerce_thumbnail_cropping_custom_width', 4));
        $h = absint(get_option('woocommerce_thumbnail_cropping_custom_height', 3));
        return ($w && $h) ? $w . ' / ' . $h : '1 / 1';
    }
    return '1 / 1';
}

/** Classe de body « thumbs-uncropped » : vignettes entieres, non coupees. */
add_filter('body_class', function ($classes) {
    if (wparabic_thumb_ratio() === 'auto') {
        $classes[] = 'thumbs-uncropped';
    }
    return $classes;
});

function wparabic_inline_css() {
    $topbar_bg   = sanitize_hex_color(get_theme_mod('topbar_bg', '#000000')) ?: '#000000';
    $topbar_text = sanitize_hex_color(get_theme_mod('topbar_text', '#ffdf86')) ?: '#ffdf86';
    $footer_bg   = sanitize_hex_color(get_theme_mod('footer_bg', '#fafafa')) ?: '#fafafa';
    $primary     = sanitize_hex_color(get_theme_mod('primary_color', WPARABIC_PRIMARY_DEFAULT)) ?: WPARABIC_PRIMARY_DEFAULT;
    $accent      = sanitize_hex_color(get_theme_mod('accent_color', WPARABIC_ACCENT_DEFAULT)) ?: WPARABIC_ACCENT_DEFAULT;

    // Voile des tuiles (.block-item::before) : couleur + intensite, 0 % = transparent.
    $overlay_hex  = sanitize_hex_color(get_theme_mod('tile_overlay_color', '#2b2c2c')) ?: '#2b2c2c';
    $overlay_rgba = wparabic_hex_rgba($overlay_hex, max(0, min(100, (int) get_theme_mod('tile_overlay_opacity', 40))) / 100);

    // Les feuilles du theme lisent ces variables (repli : les couleurs
    // d'origine). Avec le bleu par defaut, les teintes calculees retombent
    // a quelques unites pres sur celles d'origine (#bce0f7, #f0f9ff, #a9cfe9).
    $vars = ':root{'
        . '--wparabic-primary:' . $primary . ';'
        . '--wparabic-primary-light:' . wparabic_tint($primary, 0.70) . ';'
        . '--wparabic-primary-lighter:' . wparabic_tint($primary, 0.93) . ';'
        . '--wparabic-primary-disabled:' . wparabic_tint($primary, 0.55) . ';'
        . '--wparabic-accent:' . $accent . ';'
        . '--wparabic-topbar-bg:' . $topbar_bg . ';'
        . '--wparabic-footer-text:' . wparabic_footer_text_color($footer_bg) . ';'
        . '--wparabic-thumb-ratio:' . wparabic_thumb_ratio() . ';'
        . '--wparabic-tile-overlay:' . $overlay_rgba . ';'
        . '}';

    echo '<style id="wparabic-inline">'
        . $vars
        . 'body,button,input,select{font-family:"Cairo",sans-serif!important}'
        . '.top-head-wrap{background-color:' . $topbar_bg . '}'
        . '.navigation-top>li>a,.top-head-wrap{color:' . $topbar_text . '}'
        . '.footer-widgets{background-color:' . $footer_bg . '}'
        . '</style>' . "\n";
}

/* -------------------------------------------------------------------------
 * WooCommerce — ajustements de hooks
 * ------------------------------------------------------------------------- */

add_action('after_setup_theme', 'wparabic_woocommerce_hooks', 20);
function wparabic_woocommerce_hooks() {
    if (!class_exists('WooCommerce')) {
        return;
    }
    // Le badge « promo » est rendu a l'interieur de la galerie
    // (woocommerce/single-product/product-image.php), comme la reference.
    remove_action('woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10);

    /*
     * Fiche produit : on DECLENCHE woocommerce_single_product_summary (pour les
     * extensions, le partage et les donnees structurees) mais on retire ce que
     * le gabarit rend deja lui-meme, sans quoi tout apparaitrait en double.
     * L'ajout au panier est remplace par le formulaire COD.
     */
    remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_title', 5);
    remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10);
    remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_price', 10);
    remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20);
    remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
    remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);

    // Le wrapper #primary/.site-main de WooCommerce ferait doublon avec la
    // grille du theme, aux deux extremites.
    remove_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);

    /*
     * Boucle produit : meme principe. content-product.php declenche les cinq
     * hooks pour les extensions, mais rend lui-meme le lien, la vignette, le
     * badge, le titre, la note et le prix — on retire donc les callbacks par
     * defaut correspondants.
     */
    remove_action('woocommerce_before_shop_loop_item', 'woocommerce_template_loop_product_link_open', 10);
    remove_action('woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 10);
    remove_action('woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail', 10);
    remove_action('woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10);
    remove_action('woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5);
    remove_action('woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10);
    remove_action('woocommerce_after_shop_loop_item', 'woocommerce_template_loop_product_link_close', 5);

    // La grille du theme fournit son propre bouton : le tunnel COD mene a la
    // fiche produit, pas au panier.
    remove_action('woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10);

    // Le theme ne declenche jamais woocommerce_before_main_content, donc
    // wrapper-start.php ne s'execute pas. Sans ce remove_action, son pendant
    // wrapper-end.php emettait « </main></div> » sans balises ouvrantes sur
    // toutes les pages boutique. On garde le hook disponible pour les
    // extensions, on retire seulement le rendu du wrapper.
    remove_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);

    // Le compteur de resultats et le tri sont rendus dans .shop-actions par
    // woocommerce/archive-product.php ; sans cela ils apparaitraient deux fois.
    remove_action('woocommerce_before_shop_loop', 'woocommerce_result_count', 20);
    remove_action('woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30);
}

/**
 * Le theme prend en charge l'integralite du style WooCommerce (section 11 de
 * style.css). Charger woocommerce-layout.css en plus reintroduirait ses regles
 * de gabarit — notamment div.product div.summary{float:right;width:48%} — qui
 * ecrasent la grille du theme. Le theme de reference ne les charge pas non plus.
 */
add_filter('woocommerce_enqueue_styles', '__return_empty_array');

/**
 * Onglets de la fiche produit masquables (Personnaliser -> panneau du theme ->
 * صفحة المنتج) : « معلومات إضافية » et « المراجعات ». Seul l'affichage change :
 * les avis restent geres par WooCommerce.
 */
add_filter('woocommerce_product_tabs', 'wparabic_product_tabs_visibility', 50);
function wparabic_product_tabs_visibility($tabs) {
    if (!get_theme_mod('product_tab_additional', true)) {
        unset($tabs['additional_information']);
    }
    if (!get_theme_mod('product_tab_reviews', true)) {
        unset($tabs['reviews']);
    }
    return $tabs;
}

/**
 * Boucles produit natives (produits lies, ventes incitatives, shortcodes) :
 * WooCommerce les enveloppe dans <ul class="products">, alors que
 * content-product.php rend des cartes <div class="col-*"> faites pour la grille
 * .row-items du theme. Dans un <ul> en grille, chaque carte gardait sa
 * max-width de colonne (25 % sur desktop) a l'interieur de sa propre cellule :
 * titre et prix s'affichaient une lettre par ligne. On emet donc le meme
 * conteneur que la boutique : bordure, bouton, survol et colonnes responsives
 * (4 -> 3 sous 992px -> 2 sous 768px) s'appliquent tels quels.
 * La classe « products » est conservee pour les extensions qui la ciblent.
 */
add_filter('woocommerce_product_loop_start', 'wparabic_product_loop_start');
function wparabic_product_loop_start() {
    $columns = (int) wc_get_loop_prop('columns', 4);
    $columns = max(2, min(5, $columns ?: 4));
    return '<div class="row row-items products columns-' . $columns . '">';
}

add_filter('woocommerce_product_loop_end', 'wparabic_product_loop_end');
function wparabic_product_loop_end() {
    return '</div>';
}

// Titres des sections sous la fiche produit, en arabe meme si les
// traductions de WooCommerce ne sont pas installees.
add_filter('woocommerce_product_related_products_heading', function () {
    return __('منتجات ذات صلة', 'wparabic-ecomm-cod');
});
add_filter('woocommerce_product_upsells_products_heading', function () {
    return __('قد يعجبك أيضاً', 'wparabic-ecomm-cod');
});

/**
 * Nombre d'articles par page pilote par ?view=25|50|all sur la boutique.
 */
add_action('pre_get_posts', 'wparabic_shop_view_per_page');
function wparabic_shop_view_per_page($query) {
    if (is_admin() || !$query->is_main_query() || !isset($_GET['view'])) {
        return;
    }
    if (!function_exists('is_woocommerce') || !(is_shop() || is_product_taxonomy())) {
        return;
    }
    $view = sanitize_text_field(wp_unslash($_GET['view']));
    $map  = array('25' => 25, '50' => 50, 'all' => -1);
    if (isset($map[$view])) {
        $query->set('posts_per_page', $map[$view]);
    }
}

/* -------------------------------------------------------------------------
 * Recherche AJAX de produits (formulaire .form-ajax-enabled du header)
 * ------------------------------------------------------------------------- */

add_action('wp_ajax_wparabic_search_products', 'wparabic_search_products');
add_action('wp_ajax_nopriv_wparabic_search_products', 'wparabic_search_products');
function wparabic_search_products() {
    $term = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $cat  = isset($_GET['product_cat']) ? sanitize_title(wp_unslash($_GET['product_cat'])) : '';

    if (mb_strlen($term) < 3) {
        wp_send_json_success(array());
    }

    $args = array(
        'post_type'           => 'product',
        'post_status'         => 'publish',
        'posts_per_page'      => 8,
        's'                   => $term,
        'ignore_sticky_posts' => true,
        'no_found_rows'       => true,
    );

    if ($cat) {
        $args['tax_query'] = array(array(
            'taxonomy' => 'product_cat',
            'field'    => 'slug',
            'terms'    => $cat,
        ));
    }

    $results = array();
    $q       = new WP_Query($args);

    foreach ($q->posts as $post) {
        $results[] = array(
            'title' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
            'url'   => get_permalink($post),
        );
    }

    wp_send_json_success($results);
}

/* -------------------------------------------------------------------------
 * Aides de gabarit
 * ------------------------------------------------------------------------- */

/**
 * Liste d'<option> des categories produit, calculee une seule fois par requete.
 * Utilisee par les deux formulaires de recherche (header + menu mobile).
 */
function wparabic_product_cat_options() {
    static $options = null;

    if ($options !== null) {
        return $options;
    }

    if (!taxonomy_exists('product_cat')) {
        $options = '';
        return $options;
    }

    $dropdown = wp_dropdown_categories(array(
        'taxonomy'         => 'product_cat',
        'show_option_all'  => '',
        'hierarchical'     => 1,
        'hide_empty'       => 0,
        'value_field'      => 'slug',
        'echo'             => 0,
    ));

    $options = preg_replace('#<select[^>]*>#', '', $dropdown);
    $options = str_replace('</select>', '', $options);

    return $options;
}

/**
 * Pourcentage d'economie, y compris pour les produits variables dont le parent
 * ne porte pas de get_regular_price().
 *
 * @return int 0 si aucune remise.
 */
function wparabic_savings_percent($product) {
    if (!$product instanceof WC_Product) {
        return 0;
    }

    if ($product->is_type('variable')) {
        $regular = (float) $product->get_variation_regular_price('min');
        $sale    = (float) $product->get_variation_sale_price('min');
    } else {
        $regular = (float) $product->get_regular_price();
        $sale    = (float) $product->get_sale_price();
    }

    if ($regular <= 0 || $sale <= 0 || $sale >= $regular) {
        return 0;
    }

    return (int) round(( ($regular - $sale) / $regular ) * 100);
}

/**
 * Numeros de telephone dans une page RTL.
 *
 * L'algorithme bidi place chaque groupe de chiffres separe par un espace de
 * droite a gauche : « 20 439 475 » s'affichait « 475 439 20 ». On isole le
 * numero dans <bdi dir="ltr"> pour qu'il reste dans l'ordre de saisie.
 *
 * wparabic_ltr_phone($phone)      : un numero seul, echappe.
 * wparabic_ltr_phones($text)      : texte libre (bandeau...), echappe, chaque
 *                                   suite de 2 groupes de chiffres ou plus
 *                                   (ex. +216 20 439 475, 20-439-475) isolee.
 */
function wparabic_ltr_phone($phone) {
    return '<bdi class="ltr-number" dir="ltr">' . esc_html($phone) . '</bdi>';
}

function wparabic_ltr_phones($text) {
    $text = esc_html($text);
    return preg_replace_callback(
        '/(?:\+|00)?\d+(?:[ .\-\x{00A0}]\d+)+/u',
        function ($m) {
            return '<bdi class="ltr-number" dir="ltr">' . $m[0] . '</bdi>';
        },
        $text
    );
}

require get_template_directory() . '/inc/customizer.php';
require get_template_directory() . '/inc/home-widgets.php';
require get_template_directory() . '/inc/tn-delegations.php';
require get_template_directory() . '/inc/tn-states.php';
require get_template_directory() . '/inc/shipping.php';
require get_template_directory() . '/inc/attribute-colors.php';
require get_template_directory() . '/inc/cod-form.php';
require get_template_directory() . '/inc/theme-updater.php';
