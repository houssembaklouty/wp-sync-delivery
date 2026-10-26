<?php
/**
 * Gouvernorats tunisiens enregistres aupres de WooCommerce.
 *
 * WooCommerce 10.7 ne connait AUCUN etat pour la Tunisie : « TN » est absent de
 * i18n/states.php, et WC()->countries->get_states('TN') renvoie false. Sans les
 * enregistrer, les zones de livraison ne peuvent pas cibler un gouvernorat, et
 * la colonne « state » des commandes ne contient qu'un nom libre.
 *
 * Codes ISO 3166-2:TN, pour que la donnee soit exploitable par n'importe quelle
 * integration (transporteur, ERP, export comptable).
 *
 * @package wparabic-ecomm-cod
 */

defined('ABSPATH') || exit;

/**
 * Correspondance slug interne -> code ISO.
 *
 * Les slugs restent la cle de inc/tn-delegations.php et de la table de tarifs
 * historique : cette table est le pont entre les deux mondes.
 *
 * @return array<string, string>
 */
function wparabic_state_codes() {
    return array(
        'tunis'       => 'TN-11',
        'ariana'      => 'TN-12',
        'ben_arous'   => 'TN-13',
        'manouba'     => 'TN-14',
        'nabeul'      => 'TN-21',
        'zaghouan'    => 'TN-22',
        'bizerte'     => 'TN-23',
        'beja'        => 'TN-31',
        'jendouba'    => 'TN-32',
        'kef'         => 'TN-33',
        'siliana'     => 'TN-34',
        'kairouan'    => 'TN-41',
        'kasserine'   => 'TN-42',
        'sidi_bouzid' => 'TN-43',
        'sousse'      => 'TN-51',
        'monastir'    => 'TN-52',
        'mahdia'      => 'TN-53',
        'sfax'        => 'TN-61',
        'gafsa'       => 'TN-71',
        'tozeur'      => 'TN-72',
        'kebili'      => 'TN-73',
        'gabes'       => 'TN-81',
        'medenine'    => 'TN-82',
        'tataouine'   => 'TN-83',
    );
}

/**
 * Code ISO d'un slug de gouvernorat.
 *
 * @param string $slug
 * @return string Chaine vide si inconnu.
 */
function wparabic_state_code($slug) {
    $codes = wparabic_state_codes();

    return isset($codes[$slug]) ? $codes[$slug] : '';
}

/**
 * Slug interne d'un code ISO (operation inverse).
 *
 * @param string $code
 * @return string Chaine vide si inconnu.
 */
function wparabic_state_slug($code) {
    $slug = array_search($code, wparabic_state_codes(), true);

    return $slug === false ? '' : $slug;
}

/**
 * Les 24 gouvernorats, code ISO => nom arabe.
 *
 * @return array<string, string>
 */
function wparabic_tn_states() {
    $names  = wparabic_get_tunisian_governorates(); // slug => nom arabe
    $codes  = wparabic_state_codes();
    $states = array();

    foreach ($codes as $slug => $code) {
        if (isset($names[$slug])) {
            $states[$code] = $names[$slug];
        }
    }

    return $states;
}

/**
 * Declare les gouvernorats a WooCommerce.
 *
 * Des lors, l'admin peut creer des zones de livraison ciblant un gouvernorat,
 * et les adresses de commande portent un code normalise.
 */
add_filter('woocommerce_states', 'wparabic_register_tn_states');
function wparabic_register_tn_states($states) {
    if (!function_exists('wparabic_get_tunisian_governorates')) {
        return $states;
    }

    // On n'ecrase pas une definition fournie par ailleurs (extension, WooCommerce
    // qui finirait par livrer la Tunisie dans une version future).
    if (empty($states['TN'])) {
        $states['TN'] = wparabic_tn_states();
    }

    return $states;
}

/**
 * Etiquette du champ « etat » pour la Tunisie.
 */
add_filter('woocommerce_get_country_locale', 'wparabic_tn_locale');
function wparabic_tn_locale($locale) {
    $locale['TN'] = array_merge(
        isset($locale['TN']) ? $locale['TN'] : array(),
        array(
            'state' => array(
                'label'    => __('الولاية', 'wparabic-ecomm-cod'),
                'required' => true,
            ),
            'postcode' => array(
                'required' => false,
                'hidden'   => true,
            ),
        )
    );

    return $locale;
}
