<?php
/**
 * Frais de livraison depuis les zones WooCommerce.
 *
 * Le formulaire COD ne passe pas par le panier : il ne peut donc pas s'appuyer
 * sur WC()->shipping()->calculate_shipping(). On construit ici un colis minimal
 * et on interroge directement WC_Shipping_Zones, ce qui donne exactement le
 * tarif que WooCommerce appliquerait — et laisse le gerant piloter ses prix
 * depuis WooCommerce -> Reglages -> Livraison.
 *
 * Repli : si aucune zone ne correspond (ou qu'aucune n'est encore configuree),
 * on retombe sur la table historique du theme. Le site ne peut donc jamais se
 * retrouver sans frais de port.
 *
 * @package wparabic-ecomm-cod
 */

defined('ABSPATH') || exit;

/**
 * Tarif de livraison pour un gouvernorat.
 *
 * @param string          $state_code Code ISO (TN-11...) ou slug interne.
 * @param WC_Product|null $product    Produit commande, pour le poids/les dimensions.
 * @param int             $qty
 * @return array{cost:float, label:string, method_id:string, instance_id:int, native:bool}
 */
function wparabic_get_shipping_rate($state_code, $product = null, $qty = 1) {
    // On accepte indifferemment le slug interne ou le code ISO.
    $slug = wparabic_state_slug($state_code);
    if ($slug === '') {
        $slug       = $state_code;
        $state_code = wparabic_state_code($state_code);
    }

    $fallback = array(
        'cost'        => (float) wparabic_get_shipping_cost($slug),
        'label'       => __('التوصيل', 'wparabic-ecomm-cod'),
        'method_id'   => 'flat_rate',
        'instance_id' => 0,
        'native'      => false,
    );

    if (!class_exists('WC_Shipping_Zones') || $state_code === '') {
        return $fallback;
    }

    // Cache : la fiche produit calcule le tarif des 24 gouvernorats a chaque
    // affichage, soit des dizaines de requetes sur les zones de livraison. Le
    // resultat est garde 12 h, et tout le cache est invalide d'un coup (numero
    // de version) des qu'une zone, une methode ou un produit change.
    $product_id = $product instanceof WC_Product ? $product->get_id() : 0;
    $cache_key  = 'wparabic_ship_' . md5(implode('|', array(
        wparabic_shipping_cache_version(), $state_code, $product_id, (int) $qty,
    )));
    $cached = get_transient($cache_key);

    if (is_array($cached)) {
        $rate = empty($cached['none']) ? $cached : null;
    } else {
        $rate = wparabic_match_zone_rate($state_code, $product, $qty);
        set_transient($cache_key, $rate ? $rate : array('none' => 1), 12 * HOUR_IN_SECONDS);
    }

    return $rate ? $rate : $fallback;
}

/** Numero de version du cache des tarifs (voir wparabic_get_shipping_rate). */
function wparabic_shipping_cache_version() {
    return (int) get_option('wparabic_shipping_cache_ver', 1);
}

/** Invalide tous les tarifs en cache. */
function wparabic_shipping_cache_flush() {
    update_option('wparabic_shipping_cache_ver', wparabic_shipping_cache_version() + 1, false);
}

foreach (array(
    'woocommerce_after_shipping_zone_object_save',
    'woocommerce_delete_shipping_zone',
    'woocommerce_shipping_zone_method_added',
    'woocommerce_shipping_zone_method_deleted',
    'woocommerce_shipping_zone_method_status_toggled',
    'woocommerce_update_options_shipping',
    'woocommerce_update_product',
    'woocommerce_update_product_variation',
) as $wparabic_hook) {
    add_action($wparabic_hook, 'wparabic_shipping_cache_flush');
}
unset($wparabic_hook);

// Reglages d'une methode de livraison (cout, seuil...) : options
// woocommerce_{methode}_{instance}_settings, enregistrees en AJAX depuis
// l'ecran des zones sans action dediee.
add_action('updated_option', function ($option) {
    if (preg_match('/^woocommerce_[a-z0-9_]+_\d+_settings$/', (string) $option)) {
        wparabic_shipping_cache_flush();
    }
});
// Table de tarifs du theme modifiable par filtre : vider aussi a l'activation.
add_action('after_switch_theme', 'wparabic_shipping_cache_flush');

/**
 * Interroge les zones de livraison pour un colis fictif.
 *
 * @return array|null Null si aucune zone/methode ne repond.
 */
function wparabic_match_zone_rate($state_code, $product, $qty) {
    $contents = array();
    $total    = 0.0;

    if ($product instanceof WC_Product) {
        $total      = (float) $product->get_price() * max(1, (int) $qty);
        $contents[] = array(
            'data'              => $product,
            'quantity'          => max(1, (int) $qty),
            'line_total'        => $total,
            'line_subtotal'     => $total,
            'line_tax'          => 0,
            'line_subtotal_tax' => 0,
        );
    }

    $package = array(
        'contents'        => $contents,
        'contents_cost'   => $total,
        'applied_coupons' => array(),
        'user'            => array('ID' => get_current_user_id()),
        'destination'     => array(
            'country'   => 'TN',
            'state'     => $state_code,
            'postcode'  => '',
            'city'      => '',
            'address'   => '',
            'address_1' => '',
            'address_2' => '',
        ),
    );

    $zone = WC_Shipping_Zones::get_zone_matching_package($package);

    if (!$zone) {
        return null;
    }

    // get_shipping_methods(true) ne renvoie que les methodes activees.
    $methods = $zone->get_shipping_methods(true);

    if (!$methods) {
        return null;
    }

    foreach ($methods as $method) {
        if (!is_callable(array($method, 'get_rates_for_package'))) {
            continue;
        }

        $rates = $method->get_rates_for_package($package);

        if (!$rates) {
            continue;
        }

        // Le tarif le moins cher de la zone, comme le ferait un panier avec
        // selection automatique.
        $cheapest = null;
        foreach ($rates as $rate) {
            if ($cheapest === null || (float) $rate->get_cost() < (float) $cheapest->get_cost()) {
                $cheapest = $rate;
            }
        }

        if ($cheapest) {
            return array(
                'cost'        => (float) $cheapest->get_cost(),
                'label'       => $cheapest->get_label(),
                'method_id'   => $cheapest->get_method_id(),
                'instance_id' => (int) $cheapest->get_instance_id(),
                'native'      => true,
            );
        }
    }

    return null;
}

/**
 * Ligne de livraison a poser sur la commande.
 *
 * Un WC_Order_Item_Shipping — et non un WC_Order_Item_Fee — pour que
 * shipping_total soit reel et que les extensions de transporteur trouvent une
 * methode a exploiter.
 *
 * @param array $rate Retour de wparabic_get_shipping_rate().
 * @return WC_Order_Item_Shipping
 */
function wparabic_build_shipping_item($rate) {
    $item = new WC_Order_Item_Shipping();

    $item->set_method_title($rate['label']);
    $item->set_method_id($rate['method_id']);
    $item->set_instance_id($rate['instance_id']);
    $item->set_total((float) $rate['cost']);

    return $item;
}
