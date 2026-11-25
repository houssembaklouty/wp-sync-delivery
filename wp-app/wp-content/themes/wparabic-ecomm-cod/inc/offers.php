<?php
/**
 * Offres & incitations : remises quantité (paliers), code promo, urgence.
 *
 * Réglages dans le Customizer (section « العروض والتحفيز »), textes AR/FR/EN
 * selon la langue du front (pattern maison, cf. wparabic_cod_note_label).
 *
 * @package wparabic-ecomm-cod
 */

defined('ABSPATH') || exit;

/* ── Paliers & options ─────────────────────────────────────────────────── */

/**
 * Paliers de remise quantité : array de array('qty' => int, 'pct' => float).
 * Filtre wparabic_quantity_tiers($tiers, $product) pour un override par produit.
 */
function wparabic_quantity_tiers($product = null) {
    $tiers = array();

    // Kill-switch produit : TOUTES les offres coupées pour ce produit.
    if (wparabic_offers_disabled_for_product($product)) {
        return apply_filters('wparabic_quantity_tiers', array(), $product);
    }

    // Méta produit prioritaire (onglet « Offres & urgence ») : si AU MOINS un
    // palier est défini sur le produit, la méta REMPLACE la config thème.
    if ($product instanceof WC_Product) {
        $meta = array();
        $any = false;
        foreach (array(2, 3, 4) as $n) {
            $v = $product->get_meta("_wparabic_tier{$n}_pct", true);
            if ('' !== (string) $v && null !== $v) {
                $any = true;
            }
            $meta[$n] = (float) $v;
        }
        if ($any) {
            foreach ($meta as $n => $pct) {
                if ($pct > 0 && $pct <= 90) {
                    $tiers[] = array('qty' => $n, 'pct' => $pct);
                }
            }
            return apply_filters('wparabic_quantity_tiers', $tiers, $product);
        }
    }

    foreach (array(2, 3, 4) as $n) {
        $pct = (float) get_theme_mod("wparabic_tier{$n}_pct", 0);
        if ($pct > 0 && $pct <= 90) {
            $tiers[] = array('qty' => $n, 'pct' => $pct);
        }
    }
    return apply_filters('wparabic_quantity_tiers', $tiers, $product);
}

/** Kill-switch produit : toutes les offres coupées pour CE produit. */
function wparabic_offers_disabled_for_product($product) {
    return $product instanceof WC_Product && 'yes' === (string) $product->get_meta('_wparabic_offers_disabled', true);
}

/** Résolution tri-state produit (yes/no méta) > thème (bool). */
function wparabic_resolve_product_flag($product, $meta_key, $theme_mod_key, $theme_default = 'no') {
    if ($product instanceof WC_Product) {
        $meta = (string) $product->get_meta('_wparabic_' . $meta_key, true);
        if ('yes' === $meta || 'no' === $meta) {
            return 'yes' === $meta;
        }
    }
    return 'yes' === get_theme_mod($theme_mod_key, $theme_default);
}

/** Seuil « livraison gratuite dès N articles » (0 = désactivé). Méta produit prioritaire. */
function wparabic_free_shipping_qty($product = null) {
    if (wparabic_offers_disabled_for_product($product)) {
        return 0;
    }
    if ($product instanceof WC_Product) {
        $v = $product->get_meta('_wparabic_free_shipping_qty', true);
        if ('' !== (string) $v && null !== $v) {
            return max(0, (int) apply_filters('wparabic_free_shipping_qty', (int) $v));
        }
    }
    return max(0, (int) apply_filters('wparabic_free_shipping_qty', (int) get_theme_mod('wparabic_free_shipping_qty', 0)));
}

/** Champ code promo affiché ? Méta produit (yes/no) > thème. */
function wparabic_coupon_field_enabled($product = null) {
    if (wparabic_offers_disabled_for_product($product)) {
        return false;
    }
    return (bool) apply_filters('wparabic_coupon_field_enabled', wparabic_resolve_product_flag($product, 'coupon_show', 'wparabic_coupon_show', 'no'));
}

/** Palier applicable pour une quantité (plus fort % atteint). */
function wparabic_tier_for_qty($qty, $product = null) {
    $best = 0;
    foreach (wparabic_quantity_tiers($product) as $tier) {
        if ($qty >= (int) $tier['qty'] && (float) $tier['pct'] > $best) {
            $best = (float) $tier['pct'];
        }
    }
    return $best;
}

/* ── Textes par langue ─────────────────────────────────────────────────── */

function wparabic_offers_text($key) {
    $ar = array(
        'coupon_label'     => 'كود الخصم',
        'coupon_apply'     => 'تطبيق',
        'coupon_invalid'   => 'كود الخصم غير صالح أو منتهي',
        'coupon_applied'   => 'تم تطبيق الخصم',
        'coupon_free_ship' => 'توصيل مجاني مع هذا الكود',
        'tier_fee_name'    => 'خصم الكمية (%s%%)',
        'tier_hint'        => 'أضف قطعة أخرى ووفّر %s د',
        'free_ship_badge'  => 'التوصيل مجاناً من %d قطع',
        'stock_left'       => 'باقي %s فقط في المخزون',
        'orders_today'     => '%s طلب خلال 24 ساعة',
        'countdown_label'  => 'ينتهي العرض بعد',
        'saved'            => 'توفير',
    );
    $fr = array(
        'coupon_label'     => 'Code promo',
        'coupon_apply'     => 'Appliquer',
        'coupon_invalid'   => 'Code promo invalide ou expiré',
        'coupon_applied'   => 'Remise appliquée',
        'coupon_free_ship' => 'Livraison offerte avec ce code',
        'tier_fee_name'    => 'Remise quantité (%s%%)',
        'tier_hint'        => '+1 pièce = économisez %s DT',
        'free_ship_badge'  => 'Livraison offerte dès %d pièces',
        'stock_left'       => 'Plus que %s en stock',
        'orders_today'     => '%s commandés ces 24h',
        'countdown_label'  => 'L\'offre se termine dans',
        'saved'            => 'Économisez',
    );
    $en = array(
        'coupon_label'     => 'Promo code',
        'coupon_apply'     => 'Apply',
        'coupon_invalid'   => 'Invalid or expired promo code',
        'coupon_applied'   => 'Discount applied',
        'coupon_free_ship' => 'Free delivery with this code',
        'tier_fee_name'    => 'Quantity discount (%s%%)',
        'tier_hint'        => '+1 item = save %s DT',
        'free_ship_badge'  => 'Free delivery from %d items',
        'stock_left'       => 'Only %s left in stock',
        'orders_today'     => '%s ordered in 24h',
        'countdown_label'  => 'Offer ends in',
        'saved'            => 'Save',
    );
    $lang = function_exists('wparabic_current_lang') ? wparabic_current_lang() : 'ar';
    $map = 'fr' === $lang ? $fr : ('en' === $lang ? $en : $ar);
    return isset($map[$key]) ? $map[$key] : $key;
}

/* ── Urgence : données réelles ─────────────────────────────────────────── */

/** Nombre de commandes de ce produit (parent) sur les dernières 24h (cache 1h). */
function wparabic_orders_last_24h($product_id) {
    $product_id = (int) $product_id;
    if ($product_id < 1) {
        return 0;
    }
    $transient = 'wparabic_o24_' . $product_id . '_' . date('YmdH');
    $cached = get_transient($transient);
    if (false !== $cached) {
        return (int) $cached;
    }
    global $wpdb;
    $since = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
    $like  = '%' . $wpdb->esc_like('"' . $product_id . '"') . '%';
    // Commandes non annulées/bloquées créées depuis 24h contenant le produit.
    $count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT o.ID)
         FROM {$wpdb->prefix}posts o
         JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id = o.ID AND oi.order_item_type = 'line_item'
         JOIN {$wpdb->prefix}woocommerce_order_itemmeta om ON om.order_item_id = oi.order_item_id AND om.meta_key = '_product_id' AND om.meta_value = %s
         WHERE o.post_type = 'shop_order' AND o.post_status NOT IN ('wc-cancelled','wc-failed','wc-trash','wc-wparabic-blocked')
           AND o.post_date_gmt >= %s",
        $product_id, $since
    ));
    set_transient($transient, $count, HOUR_IN_SECONDS);
    return $count;
}

/* ── AJAX : vérification du code promo ─────────────────────────────────── */

add_action('wp_ajax_wparabic_check_coupon', 'wparabic_ajax_check_coupon');
add_action('wp_ajax_nopriv_wparabic_check_coupon', 'wparabic_ajax_check_coupon');
function wparabic_ajax_check_coupon() {
    check_ajax_referer('wparabic_cod_nonce', 'nonce');

    $ajax_product = isset($_POST['product_id']) ? wc_get_product(absint($_POST['product_id'])) : null;
    if (!wparabic_coupon_field_enabled($ajax_product)) {
        wp_send_json_error(array('message' => 'disabled'));
    }

    $code = sanitize_text_field(wp_unslash($_POST['coupon'] ?? ''));
    if ('' === $code) {
        wp_send_json_error(array('message' => wparabic_offers_text('coupon_invalid')));
    }

    $coupon = new WC_Coupon($code);
    if (!$coupon->get_id()) {
        wp_send_json_error(array('message' => wparabic_offers_text('coupon_invalid')));
    }

    // Contrôles de base lisibles sans panier : dates, usage global, actif.
    $now  = current_time('timestamp');
    $from = (int) $coupon->get_date_expires() ? $coupon->get_date_expires()->getTimestamp() : 0;
    if ($from && $now > $from) {
        wp_send_json_error(array('message' => wparabic_offers_text('coupon_invalid')));
    }
    if ((int) $coupon->get_usage_limit() > 0 && (int) $coupon->get_usage_count() >= (int) $coupon->get_usage_limit()) {
        wp_send_json_error(array('message' => wparabic_offers_text('coupon_invalid')));
    }
    if ($coupon->get_individual_use()) {
        // Compatible : on n'applique qu'un coupon à la fois dans le tunnel COD.
    }

    wp_send_json_success(array(
        'code'         => $coupon->get_code(),
        'type'         => $coupon->get_discount_type(), // percent | fixed_cart | fixed_product | free_shipping...
        'amount'       => (float) $coupon->get_amount(),
        'free_shipping' => (bool) $coupon->get_free_shipping(),
        'message'      => wparabic_offers_text('coupon_applied'),
    ));
}

/* ── Customizer : section « العروض والتحفيز » ──────────────────────────── */

add_action('customize_register', 'wparabic_offers_customizer');
function wparabic_offers_customizer($wp) {
    $wp->add_section('wparabic_offers', array(
        'title'       => __('العروض والتحفيز', 'wparabic-ecomm-cod'),
        'description' => __('Remises quantité, code promo, urgence — vagues de conversion COD.', 'wparabic-ecomm-cod'),
        'panel'       => 'wparabic',
        'priority'    => 35,
    ));

    $fields = array(
        'wparabic_tier2_pct'        => array(__('خصم قطعتين % (0=إيقاف)', 'wparabic-ecomm-cod'), 0, 90),
        'wparabic_tier3_pct'        => array(__('خصم 3 قطع % (0=إيقاف)', 'wparabic-ecomm-cod'), 0, 90),
        'wparabic_tier4_pct'        => array(__('خصم 4 قطع % (0=إيقاف)', 'wparabic-ecomm-cod'), 0, 90),
        'wparabic_free_shipping_qty'=> array(__('توصيل مجاني من (قطع، 0=إيقاف)', 'wparabic-ecomm-cod'), 0, 99),
        'wparabic_stock_threshold'  => array(__('عتبة «الكمية المتبقية»', 'wparabic-ecomm-cod'), 1, 100),
    );
    foreach ($fields as $id => $f) {
        $wp->add_setting($id, array('default' => 0 === strpos($id, 'tier') || false !== strpos($id, 'free') ? 0 : 10, 'sanitize_callback' => 'absint', 'transport' => 'refresh'));
        $wp->add_control($id, array(
            'section'     => 'wparabic_offers',
            'label'       => $f[0],
            'type'        => 'number',
            'input_attrs'=> array('min' => $f[1], 'max' => $f[2]),
        ));
    }

    foreach (array(
        'wparabic_coupon_show'   => __('حقل كود الخصم', 'wparabic-ecomm-cod'),
        'wparabic_stock_show'    => __('الكمية المتبقية (حسب المخزون)', 'wparabic-ecomm-cod'),
        'wparabic_orders24_show' => __('عدد الطلبات خلال 24 ساعة (حقيقي)', 'wparabic-ecomm-cod'),
        'wparabic_countdown_show'=> __('عدّاد تنازلي للعروض', 'wparabic-ecomm-cod'),
        'wparabic_blacklist_strikes' => __('تتبّع مرتجعات الناقل (تحذيرات الإدارة فقط)', 'wparabic-ecomm-cod'),
    ) as $id => $label) {
        $wp->add_setting($id, array('default' => 'no', 'sanitize_callback' => 'wparabic_sanitize_yes_no', 'transport' => 'refresh'));
        $wp->add_control($id, array('section' => 'wparabic_offers', 'label' => $label, 'type' => 'checkbox'));
    }
}

function wparabic_sanitize_yes_no($v) {
    return $v ? 'yes' : 'no';
}

/* ── Données passées à main.js (fiche produit) ─────────────────────────── */

add_action('wp_enqueue_scripts', 'wparabic_offers_localize', 20);
function wparabic_offers_localize() {
    if (!function_exists('is_product') || !is_product()) {
        return;
    }
    // Au moment de wp_enqueue_scripts, global $product n'est PAS encore
    // rempli : on le résout depuis le post courant. instanceof obligatoire :
    // sur une page non-produit, wc_get_product() peut renvoyer autre chose.
    if (!function_exists('is_product') || !is_product() || !function_exists('wc_get_product')) {
        return;
    }
    $product = wc_get_product(get_the_ID());
    if (!$product instanceof WC_Product) {
        return;
    }

    $data = array(
        'tiers'        => wparabic_quantity_tiers($product),
        'freeQty'      => wparabic_free_shipping_qty($product),
        'coupon'       => wparabic_coupon_field_enabled($product),
        'ajaxUrl'      => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('wparabic_cod_nonce'),
        'currency'     => get_woocommerce_currency_symbol(),
        'productId'    => $product->get_id(),
        'urgency'      => array(
            'stockShow'      => !wparabic_offers_disabled_for_product($product) && wparabic_resolve_product_flag($product, 'stock_show', 'wparabic_stock_show', 'no'),
            'stockThreshold' => max(1, (int) get_theme_mod('wparabic_stock_threshold', 10)),
            'ordersShow'     => !wparabic_offers_disabled_for_product($product) && wparabic_resolve_product_flag($product, 'orders24_show', 'wparabic_orders24_show', 'no'),
            'countdownShow'  => !wparabic_offers_disabled_for_product($product) && wparabic_resolve_product_flag($product, 'countdown_show', 'wparabic_countdown_show', 'no'),
            'onSale'         => $product->is_on_sale(),
            'stock'          => $product->get_manage_stock() ? (int) $product->get_stock_quantity() : null,
            'orders24'       => wparabic_orders_last_24h($product->get_id()),
        ),
        'text'         => array(
            'couponLabel'    => wparabic_offers_text('coupon_label'),
            'couponApply'    => wparabic_offers_text('coupon_apply'),
            'couponInvalid'  => wparabic_offers_text('coupon_invalid'),
            'couponApplied'  => wparabic_offers_text('coupon_applied'),
            'couponFreeShip' => wparabic_offers_text('coupon_free_ship'),
            'tierFeeName'    => sprintf(wparabic_offers_text('tier_fee_name'), '%s'), // %s littéral pour le JS
            'tierHint'       => wparabic_offers_text('tier_hint'),
            'freeShipBadge'  => wparabic_offers_text('free_ship_badge'),
            'stockLeft'      => wparabic_offers_text('stock_left'),
            'ordersToday'    => wparabic_offers_text('orders_today'),
            'countdownLabel' => wparabic_offers_text('countdown_label'),
            'saved'          => wparabic_offers_text('saved'),
        ),
    );

    wp_localize_script('wparabic-main', 'wparabic_offers', $data);
}

/* ── Étoiles + avis sur les cartes produit (grille) ────────────────────── */

add_action('woocommerce_after_shop_loop_item_title', 'wparabic_loop_rating_stars', 7);
function wparabic_loop_rating_stars() {
    global $product;
    if (!$product || !(int) $product->get_rating_count()) {
        return;
    }
    echo '<div class="wparabic-loop-rating">' . wc_get_rating_html($product->get_average_rating())
        . '<span class="wparabic-loop-rating-count">(' . (int) $product->get_rating_count() . ')</span></div>';
}
