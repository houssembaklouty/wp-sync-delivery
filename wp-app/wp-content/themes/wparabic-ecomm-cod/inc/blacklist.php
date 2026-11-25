<?php
/**
 * Blacklist silencieuse téléphones / IP + strikes retours transporteur.
 *
 * Principe : le client n'est JAMAIS informé — tout est backoffice interne.
 *  - Strikes : +1 automatiquement par commande revenue du transporteur
 *    (meta _wcmcs_last_state écrite par MultiCarrier Sync), scan cron
 *    horaire, dédoublonné par commande. INFORMATIF uniquement.
 *  - Blocage : décision MANUELLE de l'admin (page « Blocage & Retours »).
 *    Numéro/IP banni → toute nouvelle commande COD est créée normalement
 *    côté client mais reçoit automatiquement le statut « محجوبة (Bloquée) ».
 *
 * @package wparabic-ecomm-cod
 */

defined('ABSPATH') || exit;

/* ────────────────────────────────────────────────────────────────────────────
 * Statut de commande « Bloquée »
 * ──────────────────────────────────────────────────────────────────────────── */

add_action('init', 'wparabic_register_blocked_order_status');
function wparabic_register_blocked_order_status() {
    register_post_status('wparabic-blocked', array(
        'label'                     => _x('محجوبة (Bloquée)', 'Order status', 'wparabic-ecomm-cod'),
        'public'                    => false,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        /* translators: %s: nombre de commandes */
        'label_count'               => _n_noop('محجوبة (Bloquée) <span class="count">(%s)</span>', 'محجوبة (Bloquée) <span class="count">(%s)</span>', 'wparabic-ecomm-cod'),
    ));
}

add_filter('wc_order_statuses', 'wparabic_add_blocked_order_status');
function wparabic_add_blocked_order_status($statuses) {
    $new = array();
    foreach ($statuses as $key => $label) {
        $new[$key] = $label;
        if ('wc-processing' === $key) {
            $new['wc-wparabic-blocked'] = _x('محجوبة (Bloquée)', 'Order status', 'wparabic-ecomm-cod');
        }
    }
    return $new;
}

/** Badge rouge pour le statut bloqué dans la liste des commandes. */
add_action('admin_head', 'wparabic_blocked_status_css');
function wparabic_blocked_status_css() {
    if (!function_exists('get_current_screen') || !get_current_screen()) {
        return;
    }
    $screen = get_current_screen();
    if (!$screen || false === strpos((string) $screen->id, 'shop_order') && 'woocommerce_page_wc-orders' !== (string) $screen->id) {
        return;
    }
    echo '<style>
        .order-status.status-wparabic-blocked { background:#7f1d1d; color:#fff; }
        mark.order-status.status-wparabic-blocked { background:#fee2e2; color:#7f1d1d; border:1px solid #fca5a5; }
    </style>';
}

/* ────────────────────────────────────────────────────────────────────────────
 * Stockage + helpers
 * ──────────────────────────────────────────────────────────────────────────── */

/** Normalise un téléphone tunisien : 8 chiffres (préfixe 216/00216 retiré). */
function wparabic_blacklist_norm_phone($phone) {
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if (strlen($digits) === 11 && 0 === strpos($digits, '216')) {
        $digits = substr($digits, 3);
    } elseif (strlen($digits) === 13 && 0 === strpos($digits, '00216')) {
        $digits = substr($digits, 5);
    }
    return $digits;
}

/** @return array<string,array{reason:string,date:string}> */
function wparabic_get_banned_phones() {
    $v = get_option('wparabic_banned_phones', array());
    return is_array($v) ? $v : array();
}

/** @return array<string,array{reason:string,date:string}> */
function wparabic_get_banned_ips() {
    $v = get_option('wparabic_banned_ips', array());
    return is_array($v) ? $v : array();
}

/** @return array<string,int> */
function wparabic_get_phone_strikes() {
    $v = get_option('wparabic_phone_strikes', array());
    return is_array($v) ? $v : array();
}

function wparabic_is_phone_banned($phone) {
    $list = wparabic_get_banned_phones();
    return isset($list[wparabic_blacklist_norm_phone($phone)]);
}

function wparabic_is_ip_banned($ip) {
    $ip = trim((string) $ip);
    if ('' === $ip) {
        return false;
    }
    return isset(wparabic_get_banned_ips()[$ip]);
}

/**
 * Nombre de retours transporteur comptés pour un téléphone (info admin).
 */
function wparabic_phone_strikes($phone) {
    $all = wparabic_get_phone_strikes();
    $n = isset($all[wparabic_blacklist_norm_phone($phone)]) ? (int) $all[wparabic_blacklist_norm_phone($phone)] : 0;
    return apply_filters('wparabic_phone_strikes', $n, $phone);
}

/* ────────────────────────────────────────────────────────────────────────────
 * Comptage des strikes : scan des retours transporteur
 * ──────────────────────────────────────────────────────────────────────────── */

/** Cron horaire : balaye les commandes retournées non encore comptées. */
add_action('wparabic_blacklist_scan', 'wparabic_blacklist_scan_returns');
function wparabic_blacklist_scan_returns() {
    // Feature optionnelle (Personnaliser → العروض والتحفيز) : le blocage
    // manuel reste TOUJOURS actif — seul le comptage auto est désactivable.
    if ('yes' !== get_theme_mod('wparabic_blacklist_strikes', 'no')) {
        return;
    }
    $orders = wc_get_orders(array(
        'status'       => array_keys(wc_get_order_statuses()),
        'date_created' => '>' . (time() - 90 * DAY_IN_SECONDS),
        'limit'        => 300,
        'orderby'      => 'date',
        'order'        => 'DESC',
        'meta_query'   => array(
            array('key' => '_wcmcs_last_state', 'compare' => 'EXISTS'),
            array('key' => '_wparabic_strike_counted', 'compare' => 'NOT EXISTS'),
        ),
    ));

    if (empty($orders)) {
        return;
    }

    $strikes = wparabic_get_phone_strikes();
    $changed = false;

    foreach ($orders as $order) {
        $state = (string) $order->get_meta('_wcmcs_last_state');
        // « Retour payé / reçu payé » : argent récupéré → pas un faucheur.
        $is_return = (bool) preg_match('/retour|rtn/iu', $state)
            && !preg_match('/paye|payé|reçu paye/iu', $state);
        /**
         * Filtre l'appréciation d'un état transporteur comme « retour ».
         */
        $is_return = apply_filters('wparabic_is_return_state', $is_return, $state, $order);

        if ($is_return) {
            $phone = wparabic_blacklist_norm_phone($order->get_billing_phone());
            if ('' !== $phone) {
                $strikes[$phone] = (isset($strikes[$phone]) ? (int) $strikes[$phone] : 0) + 1;
                $changed = true;
            }
        }
        $order->update_meta_data('_wparabic_strike_counted', 1);
        $order->save();
    }

    if ($changed) {
        update_option('wparabic_phone_strikes', $strikes, false);
    }
}

/** Planification du cron. */
add_action('init', 'wparabic_blacklist_schedule');
function wparabic_blacklist_schedule() {
    if (!wp_next_scheduled('wparabic_blacklist_scan')) {
        wp_schedule_event(time() + 120, 'hourly', 'wparabic_blacklist_scan');
    }
}

/* ────────────────────────────────────────────────────────────────────────────
 * Interception à la commande COD (silencieuse)
 * ──────────────────────────────────────────────────────────────────────────── */

/**
 * À appeler dans le handler COD juste après la création de la commande.
 * Numéro/IP banni → statut « Bloquée » + meta d'audit. Le client voit le
 * parcours normal (merci inclus) : RIEN ne transparaît.
 *
 * @param WC_Order $order
 * @param string   $client_ip IP client brute (wparabic_cod_client_ip()).
 */
function wparabic_blacklist_guard_order($order, $client_ip = '') {
    $reason = '';

    $phone = $order->get_billing_phone();
    if ($phone && wparabic_is_phone_banned($phone)) {
        $reason = 'phone_banned';
    } elseif ($client_ip && wparabic_is_ip_banned($client_ip)) {
        $reason = 'ip_banned';
    }

    if ('' === $reason) {
        return;
    }

    $order->update_meta_data('_wparabic_blocked_reason', $reason);
    $order->update_status('wparabic-blocked', __('Commande bloquée automatiquement : ' . $reason . ' (liste de blocage marchand).', 'wparabic-ecomm-cod'));
}

/* ────────────────────────────────────────────────────────────────────────────
 * Back-office : bannière fiche commande + colonne liste
 * ──────────────────────────────────────────────────────────────────────────── */

add_action('add_meta_boxes', 'wparabic_blacklist_order_metabox');
function wparabic_blacklist_order_metabox() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $screen = class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')
        && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
        ? 'woocommerce_page_wc-orders'
        : 'shop_order';
    add_meta_box('wparabic_blacklist_banner', __('Blocage & retours', 'wparabic-ecomm-cod'), 'wparabic_blacklist_banner_html', $screen, 'side', 'high');
}

function wparabic_blacklist_banner_html($post_or_order) {
    $order = $post_or_order instanceof WP_Post ? wc_get_order($post_or_order->ID) : $post_or_order;
    if (!$order) {
        return;
    }
    $phone = $order->get_billing_phone();
    $banned = $phone && wparabic_is_phone_banned($phone);
    $strikes = $phone ? wparabic_phone_strikes($phone) : 0;

    if ($banned) {
        echo '<p style="background:#fee2e2;border:1px solid #ef4444;border-radius:8px;padding:10px 12px;color:#7f1d1d;font-weight:700">'
            . esc_html__('Numéro BLOQUÉ par le marchand — cette commande et les suivantes de ce numéro passent automatiquement en « Bloquée ».', 'wparabic-ecomm-cod') . '</p>';
    }
    if ($strikes > 0) {
        echo '<p style="background:#fff7ed;border:1px solid #fdba74;border-radius:8px;padding:10px 12px;color:#9a3412">'
            . esc_html(sprintf(_n('%d retour transporteur compté pour ce numéro.', '%d retours transporteur comptés pour ce numéro.', $strikes, 'wparabic-ecomm-cod'), $strikes))
            . ' <a href="' . esc_url(admin_url('admin.php?page=wparabic-blacklist')) . '">' . esc_html__('Décider →', 'wparabic-ecomm-cod') . '</a></p>';
    }
    if (!$banned && 0 === $strikes) {
        echo '<p style="color:#64748b">' . esc_html__('Aucun retour ni blocage pour ce numéro.', 'wparabic-ecomm-cod') . '</p>';
    }
}

/** Colonne compacte « Retours / blocage » dans la liste des commandes (listes classiques). */
add_filter('manage_edit-shop_order_columns', 'wparabic_blacklist_orders_column');
function wparabic_blacklist_orders_column($columns) {
    $columns['wparabic_blacklist'] = __('Retours', 'wparabic-ecomm-cod');
    return $columns;
}

add_action('manage_shop_order_posts_custom_column', 'wparabic_blacklist_orders_column_content', 10, 2);
function wparabic_blacklist_orders_column_content($column, $post_id) {
    if ('wparabic_blacklist' !== $column) {
        return;
    }
    $order = wc_get_order($post_id);
    if (!$order) {
        return;
    }
    $phone = $order->get_billing_phone();
    if ($phone && wparabic_is_phone_banned($phone)) {
        echo '<span title="' . esc_attr__('Numéro bloqué par le marchand', 'wparabic-ecomm-cod') . '">' . WParabic_Icons::svg( 'no-symbol' ) . '</span>';
        return;
    }
    $strikes = $phone ? wparabic_phone_strikes($phone) : 0;
    echo $strikes > 0
        ? '<span style="color:#b45309;font-weight:700" title="' . esc_attr__('Retours transporteur (strikes)', 'wparabic-ecomm-cod') . '">⚠ ' . (int) $strikes . '</span>'
        : '—';
}

/* ────────────────────────────────────────────────────────────────────────────
 * Page admin « Blocage & Retours »
 * ──────────────────────────────────────────────────────────────────────────── */

add_action('admin_menu', 'wparabic_blacklist_admin_menu');
function wparabic_blacklist_admin_menu() {
    add_submenu_page(
        'woocommerce',
        __('Blocage & Retours', 'wparabic-ecomm-cod'),
        __('Blocage & Retours', 'wparabic-ecomm-cod'),
        'manage_woocommerce',
        'wparabic-blacklist',
        'wparabic_blacklist_admin_page'
    );
}

function wparabic_blacklist_admin_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Permissions insuffisantes.', 'wparabic-ecomm-cod'));
    }

    // ── Actions (POST) ──────────────────────────────────────────────────
    $notice = '';
    if (isset($_POST['wparabic_bl_action']) && check_admin_referer('wparabic_blacklist_admin')) {
        $action = sanitize_key(wp_unslash($_POST['wparabic_bl_action']));

        if ('ban_phone' === $action) {
            $phone = wparabic_blacklist_norm_phone(sanitize_text_field(wp_unslash($_POST['phone'] ?? '')));
            $reason = sanitize_text_field(wp_unslash($_POST['reason'] ?? ''));
            if (8 === strlen($phone)) {
                $list = wparabic_get_banned_phones();
                $list[$phone] = array('reason' => $reason, 'date' => current_time('mysql'));
                update_option('wparabic_banned_phones', $list, false);
                $notice = sprintf(__('Numéro %s bloqué.', 'wparabic-ecomm-cod'), $phone);
            } else {
                $notice = __('Numéro invalide (8 chiffres attendus).', 'wparabic-ecomm-cod');
            }
        } elseif ('unban_phone' === $action) {
            $phone = wparabic_blacklist_norm_phone(sanitize_text_field(wp_unslash($_POST['phone'] ?? '')));
            $list = wparabic_get_banned_phones();
            unset($list[$phone]);
            update_option('wparabic_banned_phones', $list, false);
            $notice = sprintf(__('Numéro %s débloqué.', 'wparabic-ecomm-cod'), $phone);
        } elseif ('ban_ip' === $action) {
            $ip = filter_var(wp_unslash($_POST['ip'] ?? ''), FILTER_VALIDATE_IP);
            $reason = sanitize_text_field(wp_unslash($_POST['reason'] ?? ''));
            if ($ip) {
                $list = wparabic_get_banned_ips();
                $list[$ip] = array('reason' => $reason, 'date' => current_time('mysql'));
                update_option('wparabic_banned_ips', $list, false);
                $notice = sprintf(__('IP %s bloquée.', 'wparabic-ecomm-cod'), $ip);
            } else {
                $notice = __('Adresse IP invalide.', 'wparabic-ecomm-cod');
            }
        } elseif ('unban_ip' === $action) {
            $ip = filter_var(wp_unslash($_POST['ip'] ?? ''), FILTER_VALIDATE_IP);
            if ($ip) {
                $list = wparabic_get_banned_ips();
                unset($list[$ip]);
                update_option('wparabic_banned_ips', $list, false);
                $notice = sprintf(__('IP %s débloquée.', 'wparabic-ecomm-cod'), $ip);
            }
        } elseif ('reset_strikes' === $action) {
            $phone = wparabic_blacklist_norm_phone(sanitize_text_field(wp_unslash($_POST['phone'] ?? '')));
            $strikes = wparabic_get_phone_strikes();
            unset($strikes[$phone]);
            update_option('wparabic_phone_strikes', $strikes, false);
            $notice = sprintf(__('Compteur remis à zéro pour %s.', 'wparabic-ecomm-cod'), $phone);
        }
    }

    $banned_phones = wparabic_get_banned_phones();
    $banned_ips = wparabic_get_banned_ips();
    $strikes = wparabic_get_phone_strikes();
    arsort($strikes);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Blocage & Retours (COD)', 'wparabic-ecomm-cod'); ?></h1>
        <?php if ('' !== $notice) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
        <?php endif; ?>

        <p style="color:#64748b;max-width:760px">
            <?php esc_html_e('Les retours transporteur sont comptés automatiquement — information interne, le client n\'est jamais averti. Le blocage (🚫) est votre décision : toute commande d\'un numéro/IP bloqué est créée normalement côté client mais passe automatiquement en statut « Bloquée ».', 'wparabic-ecomm-cod'); ?>
        </p>

        <h2><?php echo WParabic_Icons::svg( 'no-symbol' ); ?> <?php esc_html_e('Numéros bloqués', 'wparabic-ecomm-cod'); ?></h2>
        <table class="widefat striped" style="max-width:760px">
            <thead><tr><th><?php esc_html_e('Numéro', 'wparabic-ecomm-cod'); ?></th><th><?php esc_html_e('Raison', 'wparabic-ecomm-cod'); ?></th><th><?php esc_html_e('Date', 'wparabic-ecomm-cod'); ?></th><th></th></tr></thead>
            <tbody>
            <?php if (empty($banned_phones)) : ?>
                <tr><td colspan="4" style="color:#94a3b8"><?php esc_html_e('Aucun numéro bloqué.', 'wparabic-ecomm-cod'); ?></td></tr>
            <?php else : foreach ($banned_phones as $phone => $info) : ?>
                <tr>
                    <td><strong><?php echo esc_html($phone); ?></strong></td>
                    <td><?php echo esc_html($info['reason']); ?></td>
                    <td><?php echo esc_html($info['date']); ?></td>
                    <td>
                        <form method="post">
                            <?php wp_nonce_field('wparabic_blacklist_admin'); ?>
                            <input type="hidden" name="wparabic_bl_action" value="unban_phone">
                            <input type="hidden" name="phone" value="<?php echo esc_attr($phone); ?>">
                            <button class="button button-small"><?php esc_html_e('Débloquer', 'wparabic-ecomm-cod'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <form method="post" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;max-width:760px">
            <?php wp_nonce_field('wparabic_blacklist_admin'); ?>
            <input type="hidden" name="wparabic_bl_action" value="ban_phone">
            <input type="text" name="phone" placeholder="<?php esc_attr_e('Numéro (8 chiffres)', 'wparabic-ecomm-cod'); ?>" style="width:180px" required>
            <input type="text" name="reason" placeholder="<?php esc_attr_e('Raison (optionnel)', 'wparabic-ecomm-cod'); ?>" style="flex:1;min-width:220px">
            <button class="button button-primary"><?php esc_html_e('Bloquer ce numéro', 'wparabic-ecomm-cod'); ?></button>
        </form>

        <h2 style="margin-top:28px"><?php echo WParabic_Icons::svg( 'exclamation-triangle' ); ?> <?php esc_html_e('Retours transporteur par numéro (auto)', 'wparabic-ecomm-cod'); ?></h2>
        <table class="widefat striped" style="max-width:760px">
            <thead><tr><th><?php esc_html_e('Numéro', 'wparabic-ecomm-cod'); ?></th><th><?php esc_html_e('Retours', 'wparabic-ecomm-cod'); ?></th><th><?php esc_html_e('Décision', 'wparabic-ecomm-cod'); ?></th></tr></thead>
            <tbody>
            <?php $top = array_slice($strikes, 0, 30, true); ?>
            <?php if (empty($top)) : ?>
                <tr><td colspan="3" style="color:#94a3b8"><?php esc_html_e('Aucun retour compté pour l\'instant.', 'wparabic-ecomm-cod'); ?></td></tr>
            <?php else : foreach ($top as $phone => $count) : ?>
                <tr>
                    <td><strong><?php echo esc_html($phone); ?></strong><?php echo isset($banned_phones[$phone]) ? ' ' . WParabic_Icons::svg( 'no-symbol' ) : ''; ?></td>
                    <td style="color:<?php echo $count >= 2 ? '#b45309' : '#64748b'; ?>;font-weight:700"><?php echo (int) $count; ?></td>
                    <td style="display:flex;gap:6px">
                        <?php if (!isset($banned_phones[$phone])) : ?>
                        <form method="post">
                            <?php wp_nonce_field('wparabic_blacklist_admin'); ?>
                            <input type="hidden" name="wparabic_bl_action" value="ban_phone">
                            <input type="hidden" name="phone" value="<?php echo esc_attr($phone); ?>">
                            <input type="hidden" name="reason" value="<?php echo esc_attr(sprintf('%d retours transporteur', $count)); ?>">
                            <button class="button button-small"><?php echo WParabic_Icons::svg( 'no-symbol' ); ?> <?php esc_html_e('Bloquer', 'wparabic-ecomm-cod'); ?></button>
                        </form>
                        <?php endif; ?>
                        <form method="post">
                            <?php wp_nonce_field('wparabic_blacklist_admin'); ?>
                            <input type="hidden" name="wparabic_bl_action" value="reset_strikes">
                            <input type="hidden" name="phone" value="<?php echo esc_attr($phone); ?>">
                            <button class="button button-small">↺ <?php esc_html_e('Remettre à 0', 'wparabic-ecomm-cod'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <h2 style="margin-top:28px"><?php echo WParabic_Icons::svg( 'globe-alt' ); ?> <?php esc_html_e('Adresses IP bloquées', 'wparabic-ecomm-cod'); ?></h2>
        <table class="widefat striped" style="max-width:760px">
            <thead><tr><th>IP</th><th><?php esc_html_e('Raison', 'wparabic-ecomm-cod'); ?></th><th><?php esc_html_e('Date', 'wparabic-ecomm-cod'); ?></th><th></th></tr></thead>
            <tbody>
            <?php if (empty($banned_ips)) : ?>
                <tr><td colspan="4" style="color:#94a3b8"><?php esc_html_e('Aucune IP bloquée.', 'wparabic-ecomm-cod'); ?></td></tr>
            <?php else : foreach ($banned_ips as $ip => $info) : ?>
                <tr>
                    <td><strong><?php echo esc_html($ip); ?></strong></td>
                    <td><?php echo esc_html($info['reason']); ?></td>
                    <td><?php echo esc_html($info['date']); ?></td>
                    <td>
                        <form method="post">
                            <?php wp_nonce_field('wparabic_blacklist_admin'); ?>
                            <input type="hidden" name="wparabic_bl_action" value="unban_ip">
                            <input type="hidden" name="ip" value="<?php echo esc_attr($ip); ?>">
                            <button class="button button-small"><?php esc_html_e('Débloquer', 'wparabic-ecomm-cod'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <form method="post" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;max-width:760px">
            <?php wp_nonce_field('wparabic_blacklist_admin'); ?>
            <input type="hidden" name="wparabic_bl_action" value="ban_ip">
            <input type="text" name="ip" placeholder="<?php esc_attr_e('Adresse IP', 'wparabic-ecomm-cod'); ?>" style="width:200px" required>
            <input type="text" name="reason" placeholder="<?php esc_attr_e('Raison (optionnel)', 'wparabic-ecomm-cod'); ?>" style="flex:1;min-width:220px">
            <button class="button button-primary"><?php esc_html_e('Bloquer cette IP', 'wparabic-ecomm-cod'); ?></button>
        </form>
    </div>
    <?php
}
