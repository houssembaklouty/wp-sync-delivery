<?php
/**
 * Onglet « Offres & urgence » dans les données produit WooCommerce.
 *
 * Chaque réglage est HÉRITÉ du thème par défaut ; l'admin peut surcharger
 * par produit ou tout couper d'un case (kill-switch). La résolution se fait
 * dans inc/offers.php (wparabic_effective_offers).
 *
 * @package wparabic-ecomm-cod
 */

defined('ABSPATH') || exit;

/* ── Onglet dans « Données produit » ─────────────────────────────────── */

add_filter('woocommerce_product_data_tabs', 'wparabic_offers_product_tab');
function wparabic_offers_product_tab($tabs) {
    $tabs['wparabic_offers'] = array(
        'label'    => __('Offres & urgence', 'wparabic-ecomm-cod'),
        'target'   => 'wparabic_offers_product_data',
        'class'    => array('show_if_simple', 'show_if_variable'),
        'priority' => 56,
    );
    return $tabs;
}

add_action('woocommerce_product_data_panels', 'wparabic_offers_product_panel');
function wparabic_offers_product_panel() {
    global $post;
    $product = $post ? wc_get_product($post->ID) : null;

    // Valeurs thème courantes, affichées en hint sous chaque champ.
    $theme = array(
        'tier2'   => (float) get_theme_mod('wparabic_tier2_pct', 0),
        'tier3'   => (float) get_theme_mod('wparabic_tier3_pct', 0),
        'tier4'   => (float) get_theme_mod('wparabic_tier4_pct', 0),
        'freeqty' => (int) get_theme_mod('wparabic_free_shipping_qty', 0),
        'coupon'  => 'yes' === get_theme_mod('wparabic_coupon_show', 'no'),
        'stock'   => 'yes' === get_theme_mod('wparabic_stock_show', 'no'),
        'orders'  => 'yes' === get_theme_mod('wparabic_orders24_show', 'no'),
        'count'   => 'yes' === get_theme_mod('wparabic_countdown_show', 'no'),
    );

    $m = function ($key, $default = '') use ($product) {
        return $product ? $product->get_meta('_wparabic_' . $key, true) : $default;
    };
    $tri = array('' => __('— Hériter du thème —', 'wparabic-ecomm-cod'), 'yes' => __('Oui', 'wparabic-ecomm-cod'), 'no' => __('Non', 'wparabic-ecomm-cod'));
    ?>
    <div id="wparabic_offers_product_data" class="panel woocommerce_options_panel" style="padding:12px 18px;">
        <p style="margin:12px 0 4px"><strong><?php esc_html_e('Offres & incitations COD — réglages de ce produit', 'wparabic-ecomm-cod'); ?></strong></p>
        <p class="description" style="margin-bottom:10px"><?php esc_html_e('Par défaut, chaque réglage hérite du thème (Apparence → Personnaliser → العروض والتحفيز). Vide = hériter. Le kill-switch coupe TOUT pour ce produit.', 'wparabic-ecomm-cod'); ?></p>

        <label style="display:block;margin:10px 0;background:#fdf2f2;border:1px solid #fca5a5;border-radius:6px;padding:10px">
            <input type="checkbox" name="_wparabic_offers_disabled" value="yes" <?php checked('yes', $m('offers_disabled')); ?> />
            <strong style="color:#b91c1c"><?php esc_html_e('Kill-switch : désactiver TOUTES les offres sur ce produit', 'wparabic-ecomm-cod'); ?></strong>
        </label>

        <p class="form-field" style="display:flex;gap:14px;flex-wrap:wrap">
            <span><?php esc_html_e('Remise 2 pièces %', 'wparabic-ecomm-cod'); ?><br>
                <input type="number" min="0" max="90" step="0.5" style="width:90px" name="_wparabic_tier2_pct" value="<?php echo esc_attr($m('tier2_pct')); ?>" placeholder="<?php echo esc_attr($theme['tier2'] ?: 0); ?>" />
                <span class="description" style="display:block">thème : <?php echo esc_html($theme['tier2']); ?>%</span>
            </span>
            <span><?php esc_html_e('Remise 3 pièces %', 'wparabic-ecomm-cod'); ?><br>
                <input type="number" min="0" max="90" step="0.5" style="width:90px" name="_wparabic_tier3_pct" value="<?php echo esc_attr($m('tier3_pct')); ?>" placeholder="<?php echo esc_attr($theme['tier3'] ?: 0); ?>" />
                <span class="description" style="display:block">thème : <?php echo esc_html($theme['tier3']); ?>%</span>
            </span>
            <span><?php esc_html_e('Remise 4 pièces %', 'wparabic-ecomm-cod'); ?><br>
                <input type="number" min="0" max="90" step="0.5" style="width:90px" name="_wparabic_tier4_pct" value="<?php echo esc_attr($m('tier4_pct')); ?>" placeholder="<?php echo esc_attr($theme['tier4'] ?: 0); ?>" />
                <span class="description" style="display:block">thème : <?php echo esc_html($theme['tier4']); ?>%</span>
            </span>
            <span><?php esc_html_e('Livraison offerte dès (pièces)', 'wparabic-ecomm-cod'); ?><br>
                <input type="number" min="0" max="99" style="width:90px" name="_wparabic_free_shipping_qty" value="<?php echo esc_attr($m('free_shipping_qty')); ?>" placeholder="<?php echo esc_attr($theme['freeqty'] ?: 0); ?>" />
                <span class="description" style="display:block">thème : <?php echo esc_html($theme['freeqty']); ?></span>
            </span>
        </p>

        <p class="form-field" style="display:flex;gap:14px;flex-wrap:wrap">
            <span><?php esc_html_e('Champ code promo', 'wparabic-ecomm-cod'); ?> <span class="description">(thème : <?php echo esc_html($theme['coupon'] ? 'Oui' : 'Non'); ?>)</span><br>
                <select name="_wparabic_coupon_show" style="width:150px">
                    <?php foreach ($tri as $v => $l) : ?><option value="<?php echo esc_attr($v); ?>" <?php selected($v, (string) $m('coupon_show')); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?>
                </select>
            </span>
            <span><?php esc_html_e('Stock restant', 'wparabic-ecomm-cod'); ?> <span class="description">(thème : <?php echo esc_html($theme['stock'] ? 'Oui' : 'Non'); ?>)</span><br>
                <select name="_wparabic_stock_show" style="width:150px">
                    <?php foreach ($tri as $v => $l) : ?><option value="<?php echo esc_attr($v); ?>" <?php selected($v, (string) $m('stock_show')); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?>
                </select>
            </span>
            <span><?php esc_html_e('Commandes 24h', 'wparabic-ecomm-cod'); ?> <span class="description">(thème : <?php echo esc_html($theme['orders'] ? 'Oui' : 'Non'); ?>)</span><br>
                <select name="_wparabic_orders24_show" style="width:150px">
                    <?php foreach ($tri as $v => $l) : ?><option value="<?php echo esc_attr($v); ?>" <?php selected($v, (string) $m('orders24_show')); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?>
                </select>
            </span>
            <span><?php esc_html_e('Compte à rebours', 'wparabic-ecomm-cod'); ?> <span class="description">(thème : <?php echo esc_html($theme['count'] ? 'Oui' : 'Non'); ?>)</span><br>
                <select name="_wparabic_countdown_show" style="width:150px">
                    <?php foreach ($tri as $v => $l) : ?><option value="<?php echo esc_attr($v); ?>" <?php selected($v, (string) $m('countdown_show')); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?>
                </select>
            </span>
        </p>
    </div>
    <?php
}

/* ── Sauvegarde ─────────────────────────────────────────────────────── */

add_action('woocommerce_process_product_meta', 'wparabic_offers_product_save');
function wparabic_offers_product_save($post_id) {
    if (!current_user_can('manage_woocommerce') || wp_is_post_revision($post_id)) {
        return;
    }

    $product = wc_get_product($post_id);
    if (!$product) {
        return;
    }

    // Kill-switch
    $product->update_meta_data('_wparabic_offers_disabled', isset($_POST['_wparabic_offers_disabled']) ? 'yes' : 'no');

    // Nombres : vide = hériter (méta supprimée), sinon clamp
    foreach (array('tier2_pct' => 90, 'tier3_pct' => 90, 'tier4_pct' => 90, 'free_shipping_qty' => 99) as $key => $max) {
        $raw = isset($_POST['_wparabic_' . $key]) ? wp_unslash($_POST['_wparabic_' . $key]) : '';
        $val = trim((string) $raw);
        if ('' === $val) {
            $product->delete_meta_data('_wparabic_' . $key);
        } else {
            $product->update_meta_data('_wparabic_' . $key, max(0, min($max, (float) $val)));
        }
    }

    // Tri-state : '' (hériter) / yes / no
    foreach (array('coupon_show', 'stock_show', 'orders24_show', 'countdown_show') as $key) {
        $val = isset($_POST['_wparabic_' . $key]) ? sanitize_key(wp_unslash($_POST['_wparabic_' . $key])) : '';
        if (!in_array($val, array('yes', 'no'), true)) {
            $val = '';
        }
        if ('' === $val) {
            $product->delete_meta_data('_wparabic_' . $key);
        } else {
            $product->update_meta_data('_wparabic_' . $key, $val);
        }
    }

    $product->save();
}
