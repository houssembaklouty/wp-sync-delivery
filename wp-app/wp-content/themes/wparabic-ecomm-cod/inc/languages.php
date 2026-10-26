<?php
/**
 * Langue de la boutique (cote visiteurs), independante de WordPress.
 *
 * - Personnaliser -> (panneau du theme) -> اللغة :
 *     * langue par defaut de la boutique (ar / fr / en) ;
 *     * langues proposees ;
 *     * afficher ou non le selecteur de langue aux visiteurs.
 * - La langue de l'administration n'est jamais touchee : chaque utilisateur
 *   garde la sienne (Profil -> Langue).
 * - Le choix du visiteur est memorise dans un cookie (1 an).
 *
 * Tout est porte par le theme : les traductions du theme sont dans
 * languages/, et le sens RTL/LTR est force selon la langue meme si les
 * traductions de WordPress ne sont pas installees sur le site. Les traductions
 * de WordPress et de WooCommerce (textes du panier, de la commande...) sont
 * installees automatiquement a l'enregistrement des reglages quand c'est
 * possible ; sinon un avis l'indique dans l'administration.
 *
 * @package wparabic-ecomm-cod
 */

defined('ABSPATH') || exit;

const WPARABIC_LANG_COOKIE = 'wparabic_lang';
const WPARABIC_LANG_PARAM  = 'site_lang';

/**
 * Langues prises en charge par le theme.
 *
 * @return array<string, array{locale:string, label:string, short:string, rtl:bool, flag:string}>
 */
function wparabic_languages() {
    return array(
        'ar' => array('locale' => 'ar',    'label' => 'العربية',  'short' => 'AR', 'rtl' => true,  'flag' => 'tn'),
        'fr' => array('locale' => 'fr_FR', 'label' => 'Français', 'short' => 'FR', 'rtl' => false, 'flag' => 'fr'),
        'en' => array('locale' => 'en_US', 'label' => 'English',  'short' => 'EN', 'rtl' => false, 'flag' => 'gb'),
    );
}

/** Locale WordPress d'un code de langue (ar -> ar, fr -> fr_FR...). */
function wparabic_lang_locale($code) {
    $languages = wparabic_languages();
    return isset($languages[$code]) ? $languages[$code]['locale'] : $languages['ar']['locale'];
}

/** Langue par defaut de la boutique. */
function wparabic_lang_default() {
    $lang = get_theme_mod('front_lang_default', 'ar');
    return isset(wparabic_languages()[$lang]) ? $lang : 'ar';
}

/** Langues cochees par l'admin (la langue par defaut en fait toujours partie). */
function wparabic_lang_enabled() {
    $enabled = array();
    foreach (array_keys(wparabic_languages()) as $code) {
        if (get_theme_mod('front_lang_' . $code, true) || $code === wparabic_lang_default()) {
            $enabled[] = $code;
        }
    }
    return $enabled;
}

/** Le selecteur est-il propose aux visiteurs ? */
function wparabic_lang_switcher_enabled() {
    return (bool) get_theme_mod('front_lang_switcher', false) && count(wparabic_lang_enabled()) > 1;
}

/**
 * Requete « boutique » : pages publiques et appels AJAX du formulaire de
 * commande / de la recherche. Jamais l'administration, la connexion ni l'API
 * REST (l'editeur de blocs y parle la langue de l'utilisateur).
 */
function wparabic_lang_is_front_request() {
    if (defined('WP_CLI') && WP_CLI) {
        return false;
    }
    if (isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'wp-login.php') {
        return false;
    }

    $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
    if (strpos($uri, '/' . rest_get_url_prefix() . '/') !== false || isset($_GET['rest_route'])) { // phpcs:ignore WordPress.Security.NonceVerification
        return false;
    }

    if (wp_doing_ajax()) {
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        return in_array($action, array('codplugin_submit', 'wparabic_search_products'), true);
    }

    return !is_admin();
}

/** Langue de la requete en cours (code court : ar, fr, en). */
function wparabic_current_lang($reset = false) {
    static $lang = null;
    if ($reset) {
        $lang = null;
    }
    if ($lang !== null) {
        return $lang;
    }

    $lang = wparabic_lang_default();

    if (wparabic_lang_switcher_enabled()) {
        $enabled = wparabic_lang_enabled();
        // phpcs:disable WordPress.Security.NonceVerification
        $asked = isset($_GET[WPARABIC_LANG_PARAM]) ? sanitize_key(wp_unslash($_GET[WPARABIC_LANG_PARAM]))
            : (isset($_COOKIE[WPARABIC_LANG_COOKIE]) ? sanitize_key(wp_unslash($_COOKIE[WPARABIC_LANG_COOKIE])) : '');
        // phpcs:enable
        if (in_array($asked, $enabled, true)) {
            $lang = $asked;
        }
    }

    return $lang;
}

/** Locale imposee par la boutique pour la requete en cours ('' si aucune). */
function wparabic_lang_forced_locale($set = null) {
    static $forced = '';
    if ($set !== null) {
        $forced = $set;
    }
    return $forced;
}

/**
 * Filtre locale / determine_locale. Cede la place a switch_to_locale() :
 * WooCommerce s'en sert pour les e-mails, le theme pour les e-mails admin.
 */
function wparabic_lang_filter_locale($locale) {
    $forced = wparabic_lang_forced_locale();
    if ($forced === '' || (function_exists('is_locale_switched') && is_locale_switched())) {
        return $locale;
    }
    return $forced;
}

/**
 * Charge une langue : les traductions deja chargees dans une autre langue
 * (WordPress, extensions chargees avant le theme) sont dechargees, le
 * chargement a la demande de WordPress les rechargera ; WP_Locale (sens du
 * texte, jours, mois) est recree avec le sens force par le theme, juste meme
 * si la traduction arabe de WordPress n'est pas installee.
 */
function wparabic_lang_set_locale($code) {
    $languages = wparabic_languages();
    $locale    = wparabic_lang_locale($code);

    wparabic_lang_forced_locale($locale);
    add_filter('locale', 'wparabic_lang_filter_locale', 99);
    add_filter('determine_locale', 'wparabic_lang_filter_locale', 99);

    foreach (array_keys(isset($GLOBALS['l10n']) ? $GLOBALS['l10n'] : array()) as $domain) {
        unload_textdomain($domain, true);
    }
    load_default_textdomain($locale);

    $GLOBALS['wp_locale'] = new WP_Locale();
    $GLOBALS['wp_locale']->text_direction = $languages[$code]['rtl'] ? 'rtl' : 'ltr';
}

/** Applique la langue de la boutique, des le chargement du theme. */
function wparabic_lang_apply() {
    if (wparabic_lang_is_front_request()) {
        wparabic_lang_set_locale(wparabic_current_lang());
    }
}
wparabic_lang_apply();

/**
 * Apercu du personnalisateur : les valeurs non publiees ne sont visibles
 * qu'apres wp_loaded. On recalcule donc la langue a ce moment-la, pour que
 * changer « اللغة الافتراضية » se voie sans publier.
 */
add_action('wp_loaded', function () {
    if (!is_customize_preview() || !wparabic_lang_is_front_request()) {
        return;
    }
    $before = wparabic_current_lang();
    $after  = wparabic_current_lang(true);
    if ($after !== $before) {
        wparabic_lang_set_locale($after);
    }
}, 20);

/* -------------------------------------------------------------------------
 * Commandes et e-mails
 * ------------------------------------------------------------------------- */

/**
 * Nombre de changements de langue faits par le theme pour les e-mails
 * marchand et pas encore annules.
 *
 * @param int $delta
 * @return int
 */
function wparabic_lang_email_switches($delta = 0) {
    static $count = 0;
    $count = max(0, $count + $delta);
    return $count;
}

/**
 * Annule les changements de langue du theme encore actifs.
 *
 * restore_previous_locale() ramene WordPress a la langue du SITE (celle de
 * Reglages > General) et recharge les traductions dans cette langue : on
 * reapplique donc ensuite la langue de la boutique, sinon la suite de la
 * requete (e-mail client, message de confirmation) sortirait dans la langue
 * du site au lieu de celle du visiteur.
 */
function wparabic_lang_restore_email_locale() {
    $restored = false;
    while (wparabic_lang_email_switches() > 0) {
        if (is_locale_switched()) {
            restore_previous_locale();
            $restored = true;
        }
        wparabic_lang_email_switches(-1);
    }
    if ($restored && wparabic_lang_forced_locale() !== '') {
        wparabic_lang_set_locale(wparabic_current_lang());
    }
}

/**
 * E-mails WooCommerce : ceux du client restent dans la langue du visiteur
 * (WooCommerce bascule sur get_locale(), filtree ci-dessus) ; ceux destines
 * au marchand (nouvelle commande...) partent dans la langue par defaut de la
 * boutique, quelle que soit la langue du visiteur qui a commande.
 *
 * WooCommerce 10.7 ne rappelle pas toujours restore_locale() (ex. e-mail
 * « nouvelle commande » deja envoye : sortie anticipee). Le theme ne compte
 * donc que sur lui-meme : jamais deux changements empiles, et annulation
 * apres chaque changement de statut et en fin de commande.
 */
add_filter('woocommerce_allow_switching_email_locale', function ($allow, $email) {
    if ($allow && $email instanceof WC_Email && !$email->is_customer_email() && wparabic_lang_forced_locale() !== '') {
        wparabic_lang_restore_email_locale();
        if (switch_to_locale(wparabic_lang_locale(wparabic_lang_default()))) {
            wparabic_lang_email_switches(1);
        }
    }
    return $allow;
}, 10, 2);

add_filter('woocommerce_allow_restoring_email_locale', function ($allow, $email) {
    if ($allow && $email instanceof WC_Email && !$email->is_customer_email()) {
        wparabic_lang_restore_email_locale();
    }
    return $allow;
}, 10, 2);

add_action('woocommerce_order_status_changed', 'wparabic_lang_restore_email_locale', PHP_INT_MAX);
add_action('woocommerce_checkout_order_processed', 'wparabic_lang_restore_email_locale', PHP_INT_MAX);

/**
 * Lien du selecteur : ?site_lang=xx pose le cookie puis redirige vers l'URL
 * propre (pas de doublon d'URL, pas de parametre dans les liens partages).
 */
add_action('template_redirect', 'wparabic_lang_handle_switch', 0);
function wparabic_lang_handle_switch() {
    // phpcs:ignore WordPress.Security.NonceVerification
    if (!isset($_GET[WPARABIC_LANG_PARAM]) || !wparabic_lang_switcher_enabled()) {
        return;
    }

    $asked = sanitize_key(wp_unslash($_GET[WPARABIC_LANG_PARAM])); // phpcs:ignore WordPress.Security.NonceVerification
    if (in_array($asked, wparabic_lang_enabled(), true)) {
        setcookie(WPARABIC_LANG_COOKIE, $asked, array(
            'expires'  => time() + YEAR_IN_SECONDS,
            'path'     => COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    }

    wp_safe_redirect(remove_query_arg(WPARABIC_LANG_PARAM));
    exit;
}

/** Classe de body pour le CSS : lang-ar / lang-fr / lang-en. */
add_filter('body_class', function ($classes) {
    $classes[] = 'lang-' . wparabic_current_lang();
    return $classes;
});

/**
 * Selecteur de langue (en-tete et menu mobile) : liste deroulante a drapeaux.
 *
 * Bouton (drapeau + code + chevron) qui ouvre une liste de liens ?site_lang=xx.
 * Ouverture/fermeture : assets/js/main.js ; sans JS, :focus-within l'ouvre.
 * Drapeaux : assets/img/flags/*.svg, livres avec le theme.
 *
 * @param string $context header|mobile
 */
function wparabic_language_switcher($context = 'header') {
    if (!wparabic_lang_switcher_enabled()) {
        return;
    }

    $languages = wparabic_languages();
    $current   = wparabic_current_lang();
    $menu_id   = 'lang-switcher-menu-' . sanitize_html_class($context);
    $flag      = function ($code) {
        return sprintf(
            '<img class="lang-flag" src="%s" alt="" width="22" height="15" decoding="async">',
            esc_url(WPARABIC_ASSETS . '/img/flags/' . $code . '.svg')
        );
    };

    printf(
        '<div class="lang-switcher lang-switcher--%1$s">'
        . '<button type="button" class="lang-switcher-toggle" aria-haspopup="true" aria-expanded="false" aria-controls="%2$s" aria-label="%3$s">'
        . '%4$s<span class="lang-switcher-code">%5$s</span><i class="fas fa-chevron-down lang-switcher-chevron" aria-hidden="true"></i>'
        . '</button><ul id="%2$s" class="lang-switcher-menu">',
        esc_attr($context),
        esc_attr($menu_id),
        /* translators: %s: current language name */
        esc_attr(sprintf(__('اختيار اللغة (الحالية : %s)', 'wparabic-ecomm-cod'), $languages[$current]['label'])),
        $flag($languages[$current]['flag']), // phpcs:ignore WordPress.Security.EscapeOutput -- echappe dans $flag
        esc_html($context === 'mobile' ? $languages[$current]['label'] : $languages[$current]['short'])
    );

    foreach (wparabic_lang_enabled() as $code) {
        $lang   = $languages[$code];
        $is_cur = $code === $current;
        printf(
            '<li><a href="%1$s" lang="%2$s" hreflang="%2$s"%3$s>%4$s<span>%5$s</span></a></li>',
            esc_url(add_query_arg(WPARABIC_LANG_PARAM, $code)),
            esc_attr(str_replace('_', '-', $lang['locale'])),
            $is_cur ? ' class="is-active" aria-current="true"' : '',
            $flag($lang['flag']), // phpcs:ignore WordPress.Security.EscapeOutput -- echappe dans $flag
            esc_html($lang['label'])
        );
    }

    echo '</ul></div>';
}

/* -------------------------------------------------------------------------
 * Traductions WordPress / WooCommerce manquantes
 * ------------------------------------------------------------------------- */

/**
 * Locales actives dont la traduction WordPress ou WooCommerce manque.
 *
 * @return array<string, string[]> locale => ['WordPress', 'WooCommerce']
 */
function wparabic_lang_missing_packs() {
    $missing   = array();
    $installed = get_available_languages();
    $languages = wparabic_languages();

    foreach (wparabic_lang_enabled() as $code) {
        $locale = $languages[$code]['locale'];
        if ($locale === 'en_US') {
            continue; // langue source de WordPress et WooCommerce
        }
        if (!in_array($locale, $installed, true)) {
            $missing[$locale][] = 'WordPress';
        }
        if (class_exists('WooCommerce')) {
            $base = WP_LANG_DIR . '/plugins/woocommerce-' . $locale;
            if (!file_exists($base . '.mo') && !file_exists($base . '.l10n.php')) {
                $missing[$locale][] = 'WooCommerce';
            }
        }
    }

    return $missing;
}

/**
 * Tente d'installer les traductions manquantes (systeme de paquets de
 * langue de WordPress.org). Silencieux en cas d'echec : l'avis ci-dessous
 * prend le relais.
 */
function wparabic_lang_install_packs() {
    if (!current_user_can('install_languages')) {
        return;
    }
    $missing = wparabic_lang_missing_packs();
    if (!$missing) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/translation-install.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

    if (!wp_can_install_language_pack()) {
        return;
    }

    foreach ($missing as $locale => $what) {
        if (in_array('WordPress', $what, true)) {
            wp_download_language_pack($locale);
        }

        if (in_array('WooCommerce', $what, true) && defined('WC_VERSION')) {
            $api = translations_api('plugins', array('slug' => 'woocommerce', 'version' => WC_VERSION));
            if (is_wp_error($api) || empty($api['translations'])) {
                continue;
            }
            foreach ($api['translations'] as $t) {
                if ($t['language'] !== $locale) {
                    continue;
                }
                $upgrader = new Language_Pack_Upgrader(new Automatic_Upgrader_Skin());
                $upgrader->upgrade((object) array(
                    'type'       => 'plugin',
                    'slug'       => 'woocommerce',
                    'language'   => $locale,
                    'version'    => $t['version'],
                    'updated'    => $t['updated'],
                    'package'    => $t['package'],
                    'autoupdate' => true,
                ));
            }
        }
    }
}
add_action('customize_save_after', 'wparabic_lang_install_packs');
add_action('after_switch_theme', 'wparabic_lang_install_packs');

/** Avis dans l'administration si une traduction reste manquante. */
add_action('admin_notices', function () {
    if (!current_user_can('install_languages')) {
        return;
    }
    $missing = wparabic_lang_missing_packs();
    if (!$missing) {
        return;
    }

    $items = array();
    foreach ($missing as $locale => $what) {
        $items[] = esc_html($locale . ' : ' . implode(', ', $what));
    }

    printf(
        '<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p></div>',
        esc_html__('ترجمات ناقصة للغات المتجر :', 'wparabic-ecomm-cod'),
        implode(' — ', $items), // phpcs:ignore WordPress.Security.EscapeOutput -- echappe ci-dessus
        esc_html__('نصوص القالب مترجمة، لكن بعض نصوص ووردبريس أو ووكومرس ستظهر بالإنجليزية. ثبّتها من لوحة التحكم ← التحديثات ← تحديث الترجمات، أو بإضافة اللغة في الإعدادات ← عام.', 'wparabic-ecomm-cod')
    );
});

/**
 * Mode de paiement « a la livraison » affiche dans la langue de la boutique
 * (page de remerciement, compte client, e-mails). En base, la commande garde
 * le titre de la passerelle regle par le marchand ; seul l'affichage change,
 * et seulement sur les requetes boutique (pas dans l'administration).
 */
add_filter('woocommerce_order_get_payment_method_title', function ($title, $order) {
    if (wparabic_lang_forced_locale() === '' || !$order instanceof WC_Order || $order->get_payment_method() !== 'cod') {
        return $title;
    }
    return __('الدفع عند الاستلام', 'wparabic-ecomm-cod');
}, 10, 2);
