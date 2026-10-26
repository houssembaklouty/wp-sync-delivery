<?php
/**
 * Formulaire de commande « paiement a la livraison » (COD).
 *
 * La structure HTML reproduit celle du theme de reference. Un detail est
 * porteur pour la mise en page : #codplugin_woo_single_form est en
 * display:grid / grid-template-columns:repeat(2,1fr), donc tout ce qui ne doit
 * PAS entrer dans cette grille (prix cache, id de variation, compteur de
 * quantite, bouton de validation) vit hors du <form> et lui est rattache via
 * l'attribut HTML5 form="codplugin_woo_single_form".
 *
 * @package wparabic-ecomm-cod
 */

defined('ABSPATH') || exit;

/**
 * @return array<string,string> slug => nom du gouvernorat
 */
function wparabic_get_tunisian_governorates() {
    return array(
        'tunis'       => __('تونس', 'wparabic-ecomm-cod'),
        'ariana'      => __('أريانة', 'wparabic-ecomm-cod'),
        'ben_arous'   => __('بن عروس', 'wparabic-ecomm-cod'),
        'manouba'     => __('منوبة', 'wparabic-ecomm-cod'),
        'nabeul'      => __('نابل', 'wparabic-ecomm-cod'),
        'zaghouan'    => __('زغوان', 'wparabic-ecomm-cod'),
        'bizerte'     => __('بنزرت', 'wparabic-ecomm-cod'),
        'beja'        => __('باجة', 'wparabic-ecomm-cod'),
        'jendouba'    => __('جندوبة', 'wparabic-ecomm-cod'),
        'kef'         => __('الكاف', 'wparabic-ecomm-cod'),
        'siliana'     => __('سليانة', 'wparabic-ecomm-cod'),
        'sousse'      => __('سوسة', 'wparabic-ecomm-cod'),
        'monastir'    => __('المنستير', 'wparabic-ecomm-cod'),
        'mahdia'      => __('المهدية', 'wparabic-ecomm-cod'),
        'sfax'        => __('صفاقس', 'wparabic-ecomm-cod'),
        'kairouan'    => __('القيروان', 'wparabic-ecomm-cod'),
        'kasserine'   => __('القصرين', 'wparabic-ecomm-cod'),
        'sidi_bouzid' => __('سيدي بوزيد', 'wparabic-ecomm-cod'),
        'gabes'       => __('ڨابس', 'wparabic-ecomm-cod'),
        'medenine'    => __('مدنين', 'wparabic-ecomm-cod'),
        'tataouine'   => __('تطاوين', 'wparabic-ecomm-cod'),
        'gafsa'       => __('ڨفصة', 'wparabic-ecomm-cod'),
        'tozeur'      => __('توزر', 'wparabic-ecomm-cod'),
        'kebili'      => __('ڨبلي', 'wparabic-ecomm-cod'),
    );
}

/**
 * Frais de livraison par gouvernorat, en dinars.
 *
 * @param string $governorate_slug
 * @return float
 */
function wparabic_get_shipping_cost($governorate_slug) {
    $costs = array(
        'tunis'     => 5, 'ariana'      => 5, 'ben_arous' => 5,  'manouba'  => 5,
        'nabeul'    => 5, 'zaghouan'    => 5, 'bizerte'   => 7,  'beja'     => 7,
        'jendouba'  => 7, 'kef'         => 7, 'siliana'   => 7,  'sousse'   => 5,
        'monastir'  => 5, 'mahdia'      => 7, 'sfax'      => 8,  'kairouan' => 7,
        'kasserine' => 8, 'sidi_bouzid' => 8, 'gabes'     => 10, 'medenine' => 10,
        'tataouine' => 12, 'gafsa'      => 10, 'tozeur'   => 12, 'kebili'   => 12,
    );

    $costs = apply_filters('wparabic_shipping_costs', $costs);

    return (float) (isset($costs[$governorate_slug]) ? $costs[$governorate_slug] : 7);
}

/**
 * Nombre maximum de « مقطع » (articles) selectionnables.
 *
 * Le formulaire rend un bloc de variation par article possible, en HTML
 * cache pour les articles au-dela du premier. La quantite du compteur ne
 * peut donc pas depasser ce plafond. Filtrable pour les marchands qui
 * vendent en gros lots.
 *
 * @return int
 */
function wparabic_cod_max_articles() {
    return max(1, (int) apply_filters('wparabic_cod_max_articles', 10));
}

/* -------------------------------------------------------------------------
 * Champs du formulaire
 *
 * Une seule table decrit les six champs. Elle pilote a la fois le rendu, la
 * lecture du POST, la validation et la note client : ajouter un champ ou en
 * retirer un ne se fait qu'ici.
 *
 * « locked » = toujours affiche, non desactivable depuis le personnalisateur :
 *   - le telephone est le seul moyen de confirmer une commande COD, et il sert
 *     de repli au nom (voir le handler) ;
 *   - la wilaya determine le prix de la livraison (wparabic_get_shipping_rate),
 *     alimente la cascade des delegations et la ligne « سعر التوصيل » du
 *     recapitulatif. Sans elle, le total resterait vide.
 * ------------------------------------------------------------------------- */

/**
 * Description des champs, dans leur ordre de rendu.
 *
 * A n'appeler qu'apres after_setup_theme : les libelles passent par __().
 *
 * @return array<string, array>
 */
/**
 * Libellé de note client selon la langue du front.
 *
 * Les notes de wparabic_cod_fields() sont maîtrisées en arabe (format
 * historique « طلب COD: … ») : en version FR, on traduit l'étiquette pour
 * que la note stockée soit intégralement française (valeurs déjà traduites
 * par ailleurs). Clé inconnue ou front arabe : libellé inchangé.
 *
 * @param string $ar Libellé arabe master.
 * @return string
 */
function wparabic_cod_note_label($ar) {
    if (!function_exists('wparabic_current_lang') || wparabic_current_lang() !== 'fr') {
        return $ar;
    }
    $fr = array(
        'طلب COD'  => 'Commande COD',
        'هاتف'     => 'Téléphone',
        'ولاية'    => 'Gouvernorat',
        'معتمدية'  => 'Délégation',
        'العنوان'  => 'Adresse',
        'المقاطع'  => 'Articles',
    );
    return isset($fr[$ar]) ? $fr[$ar] : $ar;
}

/**
 * Séparateur de liste selon la langue (virgule arabe vs latine).
 *
 * @return string
 */
function wparabic_note_list_separator() {
    return (function_exists('wparabic_current_lang') && wparabic_current_lang() === 'fr') ? ', ' : '، ';
}
function wparabic_cod_fields() {
    return array(
        'name' => array(
            'name'     => 'full_name',
            'label'    => __('الاسم بالكامل', 'wparabic-ecomm-cod'),
            'desc'     => __('إذا أخفيته، يُسجَّل رقم الهاتف مكان الاسم في لوحة الطلبات.', 'wparabic-ecomm-cod'),
            'note'     => '',
            'sanitize' => 'sanitize_text_field',
            'required' => true,
            'locked'   => false,
        ),
        'phone' => array(
            'name'     => 'phone_number',
            'label'    => __('رقم الهاتف', 'wparabic-ecomm-cod'),
            'desc'     => '',
            'note'     => 'هاتف',
            'sanitize' => 'sanitize_text_field',
            'required' => true,
            'locked'   => true,
        ),
        'state' => array(
            'name'     => 'codplugin_state',
            'label'    => __('الولاية', 'wparabic-ecomm-cod'),
            'desc'     => '',
            'note'     => 'ولاية',
            'sanitize' => 'sanitize_text_field',
            'required' => true,
            'locked'   => true,
        ),
        'city' => array(
            'name'     => 'codplugin_city',
            'label'    => __('المعتمدية', 'wparabic-ecomm-cod'),
            'desc'     => '',
            'note'     => 'معتمدية',
            'sanitize' => 'sanitize_text_field',
            'required' => true,
            'locked'   => false,
        ),
        'address' => array(
            'name'     => 'full_address',
            'label'    => __('العنوان', 'wparabic-ecomm-cod'),
            'desc'     => __('إذا أخفيته، سيتعيّن عليك أخذ العنوان عبر الهاتف.', 'wparabic-ecomm-cod'),
            'note'     => 'العنوان',
            'sanitize' => 'sanitize_text_field',
            'required' => true,
            'locked'   => false,
        ),
        'email' => array(
            'name'     => 'billing_email',
            'label'    => __('البريد الإلكتروني', 'wparabic-ecomm-cod'),
            'desc'     => __('يُستعمل لإرسال تأكيد الطلب. اختياري دائمًا.', 'wparabic-ecomm-cod'),
            'note'     => '',
            'sanitize' => 'sanitize_email',
            'required' => false,
            'locked'   => false,
        ),
    );
}

/**
 * Ce champ doit-il etre rendu ?
 *
 * @param string $key Cle de wparabic_cod_fields().
 * @return bool
 */
function wparabic_cod_field_enabled($key) {
    $fields = wparabic_cod_fields();

    if (!isset($fields[$key])) {
        return false;
    }

    if ($fields[$key]['locked']) {
        return true;
    }

    return (bool) get_theme_mod('cod_field_' . $key, true);
}

/**
 * Valeur postee pour un champ, assainie.
 *
 * Renvoie TOUJOURS '' pour un champ desactive, quoi qu'il y ait dans $_POST :
 * sans ce garde-fou, un POST forge reinjecterait par exemple un billing_email
 * que le marchand a explicitement coupe — ce qui declencherait les emails
 * clients de WooCommerce.
 *
 * @param string $key Cle de wparabic_cod_fields().
 * @return string
 */
function wparabic_cod_posted($key) {
    $fields = wparabic_cod_fields();

    if (!isset($fields[$key]) || !wparabic_cod_field_enabled($key)) {
        return '';
    }

    $name = $fields[$key]['name'];

    if (!isset($_POST[$name])) { // phpcs:ignore WordPress.Security.NonceVerification
        return '';
    }

    return call_user_func(
        $fields[$key]['sanitize'],
        wp_unslash($_POST[$name]) // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
    );
}

/* -------------------------------------------------------------------------
 * Variations
 *
 * Le selecteur est le formulaire de variation natif de WooCommerce : une liste
 * deroulante par attribut, entierement pilotee par l'admin. Ajouter un terme ou
 * un attribut entier depuis wp-admin suffit, sans toucher au code.
 * ------------------------------------------------------------------------- */

/**
 * Message « choisis tes options », construit depuis les attributs reellement
 * declares sur le produit.
 *
 * Sert a la fois d'indice cote client et de message d'erreur cote serveur :
 * les deux ne peuvent donc pas diverger.
 *
 * @param WC_Product $product
 * @return string
 */
function wparabic_cod_choose_hint($product) {
    if (!$product instanceof WC_Product || !$product->is_type('variable')) {
        return __('يرجى ملء جميع الحقول المطلوبة', 'wparabic-ecomm-cod');
    }

    $labels = array();
    foreach (array_keys($product->get_variation_attributes()) as $taxonomy) {
        $labels[] = wc_attribute_label($taxonomy, $product);
    }

    return $labels
        ? sprintf(__('يرجى اختيار %s', 'wparabic-ecomm-cod'), implode(__(' و ', 'wparabic-ecomm-cod'), $labels))
        : __('يرجى اختيار الخيار المناسب', 'wparabic-ecomm-cod');
}

/**
 * Combinaison pre-selectionnee au chargement de la page.
 *
 * Lit $_REQUEST['attribute_*'] (liens profonds) puis les attributs par defaut
 * du produit — le meme ordre de priorite que
 * wc_dropdown_variation_attribute_options(), pour que le <select> et le prix
 * affiche ne puissent pas diverger.
 *
 * Resoudre cet etat cote serveur evite le clignotement : le premier
 * check_variations de WooCommerce ne s'execute qu'apres la construction de
 * VariationForm.
 *
 * @param WC_Product $product
 * @return array{variation_id:int, price:float, complete:bool}
 */
function wparabic_cod_default_selection($product) {
    $fallback = array(
        'variation_id' => 0,
        'price'        => (float) $product->get_price(),
        'complete'     => false,
    );

    if (!$product->is_type('variable')) {
        $fallback['complete'] = true;
        return $fallback;
    }

    $wanted = array();

    foreach (array_keys($product->get_variation_attributes()) as $taxonomy) {
        $key = 'attribute_' . sanitize_title($taxonomy);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $value = isset($_REQUEST[$key])
            ? wc_clean(wp_unslash($_REQUEST[$key]))
            : $product->get_variation_default_attribute($taxonomy);

        if ($value === '') {
            return $fallback; // combinaison incomplete
        }

        $wanted[$key] = $value;
    }

    if (!$wanted) {
        return $fallback;
    }

    $variation_id = WC_Data_Store::load('product')->find_matching_product_variation($product, $wanted);
    $variation    = $variation_id ? wc_get_product($variation_id) : null;

    if (!$variation || !$variation->is_purchasable() || !$variation->is_in_stock()) {
        return $fallback;
    }

    return array(
        'variation_id' => (int) $variation_id,
        'price'        => (float) $variation->get_price(),
        'complete'     => true,
    );
}

/**
 * Affiche le formulaire de variation natif de WooCommerce.
 *
 * @param WC_Product $the_product
 */
function wparabic_render_variations_form($the_product) {
    if (!$the_product instanceof WC_Product || !$the_product->is_type('variable')) {
        return;
    }

    // variable.php lit le global, or wparabic_cod_form() recoit le produit en
    // parametre : on permute le temps du rendu.
    global $product;
    $previous = $product;
    $product  = $the_product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

    // Le bouton « ajouter au panier » n'a pas lieu d'etre ici : la commande
    // passe par #codplugin_woo_single_form. On garde en revanche la priorite 10
    // (<div class="woocommerce-variation single_variation">), exigee par
    // wc-add-to-cart-variation.js pour afficher « aucune combinaison ».
    remove_action('woocommerce_single_variation', 'woocommerce_single_variation_add_to_cart_button', 20);
    woocommerce_variable_add_to_cart();
    add_action('woocommerce_single_variation', 'woocommerce_single_variation_add_to_cart_button', 20);

    $product = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
}

/**
 * Libelle « اختر اللون » construit depuis l'etiquette de l'attribut, donc
 * valable pour tout attribut ajoute depuis l'admin.
 */
add_filter('woocommerce_dropdown_variation_attribute_options_args', 'wparabic_variation_dropdown_args');
function wparabic_variation_dropdown_args($args) {
    if (empty($args['attribute'])) {
        return $args;
    }

    $args['show_option_none'] = sprintf(__('اختر %s', 'wparabic-ecomm-cod'), wc_attribute_label($args['attribute']));
    // Inerte pour la validation (ce formulaire n'est jamais soumis) mais pilote
    // le selecteur :valid, donc le passage au vert comme les champs COD.
    $args['required'] = true;

    return $args;
}

/**
 * Id de <select> unique au sein de chaque bloc « مقطع ».
 *
 * Le meme formulaire de variation est rendu autant de fois qu'il y a
 * d'articles possibles : sans suffixe, dix selects partageraient id
 * « pa_color ». L'index du bloc courant est pose par la boucle de rendu de
 * wparabic_cod_form() ; hors du formulaire COD (mini-panier, blocs tiers) il
 * reste absent et le comportement inline de WooCommerce est conserve.
 */
add_filter('woocommerce_dropdown_variation_attribute_options_args', 'wparabic_variation_dropdown_block_id', 15);
function wparabic_variation_dropdown_block_id($args) {
    $index = isset($GLOBALS['_wparabic_article_index']) ? (int) $GLOBALS['_wparabic_article_index'] : 0;

    if ($index < 2) {
        return $args;
    }

    $base       = !empty($args['id']) ? $args['id'] : sanitize_title($args['attribute']);
    $args['id'] = $base . '-' . $index;

    return $args;
}

/**
 * Au-dela de 30 variations WooCommerce bascule en aller-retour AJAX et cesse de
 * filtrer les options disponibles. 5 couleurs x 8 pointures depassent ce seuil.
 */
add_filter('woocommerce_ajax_variation_threshold', 'wparabic_variation_threshold');
function wparabic_variation_threshold() {
    return 200;
}

/**
 * Rend la carte de commande COD.
 *
 * @param WC_Product $product
 */
function wparabic_cod_form($product) {
    if (!$product instanceof WC_Product) {
        return;
    }

    $governorates    = wparabic_get_tunisian_governorates();
    $currency_symbol = get_woocommerce_currency_symbol();

    $is_variable = $product->is_type('variable');
    $selection   = wparabic_cod_default_selection($product);
    $can_order   = $product->is_in_stock()
        && ($is_variable ? $selection['variation_id'] > 0 : $product->is_purchasable());

    // Tant que la combinaison n'est pas complete on n'affiche aucun prix
    // unitaire : recalc() s'arrete net et le recapitulatif montre un tiret.
    $default_price = $selection['complete'] ? $selection['price'] : '';

    $variation_label = $selection['variation_id']
        ? wc_get_formatted_variation(wc_get_product($selection['variation_id']), true, false)
        : '';

    /*
     * Un bloc de variation par article possible : le client choisit couleur
     * et pointure POUR CHAQUE « مقطع » des que la quantite depasse 1. Le
     * bloc 1 est seul visible au chargement (data-index 1), les suivants
     * attendent en display:none — wc-add-to-cart-variation.js les attache
     * des le chargement de la page, cache ou non.
     */
    $max_articles   = $is_variable ? wparabic_cod_max_articles() : 1;
    $choose_hint    = $is_variable ? wparabic_cod_choose_hint($product) : '';

    $state_placeholder   = __('الولاية', 'wparabic-ecomm-cod');
    $address_placeholder = '📍 ' . __('العنوان (الشارع، الحي، رقم المنزل)', 'wparabic-ecomm-cod');
    $email_placeholder   = '✉️ ' . __('البريد الإلكتروني (اختياري)', 'wparabic-ecomm-cod');
    $city_placeholder  = __('المعتمدية', 'wparabic-ecomm-cod');

    /*
     * Le formulaire est une grille a deux colonnes (assets/css/cod-form.css) et
     * l'adresse occupe toute la largeur (#codplugin_address_field, overrides.css).
     * Si un nombre IMPAIR de champs la precede, le dernier d'entre eux se
     * retrouverait seul avec une cellule vide a sa droite : on l'etale alors sur
     * les deux colonnes. Par defaut ils sont quatre — la classe n'est jamais
     * emise et la sortie reste celle de la reference.
     *
     * Volontairement calcule en PHP : un :nth-child() compterait aussi les six
     * <input type="hidden"> ci-dessous, qui ne sont pas des cellules de grille.
     */
    $span_key = null;
    $before   = array();

    foreach (array('name', 'phone', 'state', 'city') as $key) {
        if (wparabic_cod_field_enabled($key)) {
            $before[] = $key;
        }
    }

    if (wparabic_cod_field_enabled('address') && count($before) % 2 === 1) {
        $span_key = end($before);
    }
    ?>
    <div id="codplugin-checkout">
        <div class="codplugin-checkout-title">
            <h3><?php esc_html_e('أضف معلوماتك في الأسفل للطلب', 'wparabic-ecomm-cod'); ?> 👇</h3>
        </div>

        <form id="codplugin_woo_single_form" class="checkout woocommerce-checkout">
            <input type="hidden" id="rwc-form-nonce" name="rwc-form-nonce" value="<?php echo esc_attr(wp_create_nonce('codplugin_nonce')); ?>">
            <input type="hidden" id="codplugin_thankyou_url" value="<?php echo esc_url(wc_get_checkout_url()); ?>">
            <input type="hidden" name="product_id" value="<?php echo esc_attr($product->get_id()); ?>">
            <input type="hidden" name="everyprice" id="everyprice" value="0">
            <input type="hidden" name="d_price" id="d_price">
            <input type="hidden" name="codplugin_d_method" id="codplugin_d_method">

            <?php
            // Piege a robots : invisible pour un humain, rempli par les robots
            // qui remplissent tous les champs. Voir wparabic_cod_ajax_handler().
            ?>
            <p class="codplugin-hp" aria-hidden="true">
                <label>Website <input type="text" name="codplugin_website" value="" tabindex="-1" autocomplete="off"></label>
            </p>

            <?php
            // Origine de la commande (WooCommerce > Commandes > Origine) : le
            // script d'attribution de WooCommerce remplit cet element de
            // champs wc_order_attribution_*, lus a la creation de la commande.
            ?>
            <wc-order-attribution-inputs></wc-order-attribution-inputs>

            <?php if (wparabic_cod_field_enabled('name')) : ?>
            <label class="sr-only" for="codplugin_full_name"><?php esc_html_e('الاسم بالكامل', 'wparabic-ecomm-cod'); ?></label>
            <input type="text" name="full_name" id="codplugin_full_name" placeholder="👤 <?php esc_attr_e('الاسم بالكامل', 'wparabic-ecomm-cod'); ?>" required autocomplete="name"<?php echo 'name' === $span_key ? ' class="cod-span-2"' : ''; ?>>
            <?php endif; ?>
            <?php
            /*
             * Telephone tunisien : 8 chiffres exactement.
             * inputmode ouvre le pave numerique sur mobile, pattern fait la
             * validation native, et assets/js/main.js filtre la saisie et retire
             * un prefixe +216 / 00216 colle depuis le presse-papiers. Pas de
             * maxlength : il tronquait « +216 xx xxx xxx » avant ce filtre. Le
             * serveur revalide de toute facon.
             */
            ?>
            <label class="sr-only" for="codplugin_phone"><?php esc_html_e('رقم الهاتف', 'wparabic-ecomm-cod'); ?></label>
            <input type="tel" name="phone_number" id="codplugin_phone" placeholder="📞 <?php esc_attr_e('رقم الهاتف', 'wparabic-ecomm-cod'); ?>" required autocomplete="tel-national"
                   inputmode="numeric" pattern="[0-9]{8}"
                   title="<?php esc_attr_e('يجب أن يتكون رقم الهاتف من 8 أرقام', 'wparabic-ecomm-cod'); ?>"<?php echo 'phone' === $span_key ? ' class="cod-span-2"' : ''; ?>>

            <p class="form-row codplugin-field validate-required<?php echo 'state' === $span_key ? ' cod-span-2' : ''; ?>" id="codplugin_state_field" data-priority="">
                <label class="sr-only" for="codplugin_state"><?php esc_html_e('الولاية', 'wparabic-ecomm-cod'); ?></label>
                <span class="woocommerce-input-wrapper">
                    <select name="codplugin_state" id="codplugin_state" class="select " aria-required="true"
                            data-allow_clear="true" data-placeholder="<?php echo esc_attr($state_placeholder); ?>" required>
                        <option value="" selected='selected'><?php echo esc_html($state_placeholder); ?></option>
                        <?php
                        foreach ($governorates as $slug => $name) :
                            // Le tarif vient des zones WooCommerce (repli sur la
                            // table du theme si aucune zone ne correspond).
                            $rate = wparabic_get_shipping_rate($slug, $product, 1);
                            ?>
                            <option value="<?php echo esc_attr($slug); ?>" data-shipping="<?php echo esc_attr($rate['cost']); ?>"><?php echo esc_html($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </span>
            </p>

            <?php if (wparabic_cod_field_enabled('city')) : ?>
            <p class="form-row codplugin-field validate-required<?php echo 'city' === $span_key ? ' cod-span-2' : ''; ?>" id="codplugin_city_field" data-priority="">
                <label class="sr-only" for="codplugin_city"><?php esc_html_e('المعتمدية', 'wparabic-ecomm-cod'); ?></label>
                <span class="woocommerce-input-wrapper">
                    <select name="codplugin_city" id="codplugin_city" class="select " aria-required="true"
                            data-allow_clear="true" data-placeholder="<?php echo esc_attr($city_placeholder); ?>" required>
                        <option value="" selected='selected'><?php echo esc_html($city_placeholder); ?></option>
                    </select>
                </span>
            </p>
            <?php endif; ?>

            <?php
            // Adresse de livraison : occupe toute la largeur de la grille
            // (voir #codplugin_address_field dans assets/css/overrides.css).
            ?>
            <?php if (wparabic_cod_field_enabled('address')) : ?>
            <p class="form-row codplugin-field validate-required" id="codplugin_address_field" data-priority="">
                <label class="sr-only" for="full_address"><?php esc_html_e('العنوان', 'wparabic-ecomm-cod'); ?></label>
                <span class="woocommerce-input-wrapper">
                    <input type="text" name="full_address" id="full_address"
                           placeholder="<?php echo esc_attr($address_placeholder); ?>"
                           required autocomplete="street-address">
                </span>
            </p>
            <?php endif; ?>

            <?php
            // Facultatif : renseigne, il declenche l'email de confirmation
            // WooCommerce. Beaucoup d'acheteurs COD n'ont pas d'email, on
            // n'impose donc rien.
            ?>
            <?php if (wparabic_cod_field_enabled('email')) : ?>
            <p class="form-row codplugin-field" id="codplugin_email_field" data-priority="">
                <label class="sr-only" for="billing_email"><?php esc_html_e('البريد الإلكتروني', 'wparabic-ecomm-cod'); ?></label>
                <span class="woocommerce-input-wrapper">
                    <input type="email" name="billing_email" id="billing_email"
                           placeholder="<?php echo esc_attr($email_placeholder); ?>"
                           autocomplete="email">
                </span>
            </p>
            <?php endif; ?>
        </form>

        <?php if ($is_variable) : ?>
            <div class="codplugin-variations">
                <?php
                for ($i = 1; $i <= $max_articles; $i++) :
                    // Pour que le selecteur de variation garde un id unique
                    // dans chaque bloc (voir wparabic_variation_dropdown_block_id).
                    $GLOBALS['_wparabic_article_index'] = $i;
                    ?>
                    <div class="codplugin-article<?php echo $i > 1 ? ' is-hidden' : ''; ?>" data-index="<?php echo esc_attr($i); ?>">
                        <div class="codplugin-article-head">
                            <span class="codplugin-article-num"><?php echo esc_html($i); ?></span>
                        </div>

                        <?php wparabic_render_variations_form($product); ?>

                        <?php
                        // Variation et prix unitaire RESERVES a l'article i :
                        // Html attendus par recalc()/syncSwatches() — ils ne partent
                        // jamais seuls dans le POST (pas d'attribut form=), la
                        // soumission recopie explicitement var_id_n dans pairs[].
                        ?>
                        <input type="hidden" class="codplugin-item-var"   id="var_id_<?php echo esc_attr($i); ?>"          value="<?php echo esc_attr($i === 1 ? $selection['variation_id'] : 0); ?>">
                        <input type="hidden" class="codplugin-item-price" id="codplugin_price_<?php echo esc_attr($i); ?>" value="<?php echo esc_attr($i === 1 ? $default_price : ''); ?>">

                        <p class="codplugin-variation-hint"<?php echo ($i === 1 && $selection['complete']) ? ' style="display:none"' : ''; ?>>
                            <?php echo esc_html($choose_hint); ?>
                        </p>
                    </div>
                <?php
                endfor;
                unset($GLOBALS['_wparabic_article_index']);
                ?>
            </div>
        <?php endif; ?>

        <input type="hidden" name="codplugin_price" id="codplugin_price" value="<?php echo esc_attr($default_price); ?>" form="codplugin_woo_single_form">
        <input type="hidden" name="codplugin_c_number" id="codplugin_c_number" value="1" form="codplugin_woo_single_form">

        <div id="codplugin_gif">
            <img src="<?php echo esc_url(WPARABIC_ASSETS . '/img/processing.gif'); ?>" width="50" height="50" alt="">
        </div>

        <div class="form-footer clear ">
            <div class="form-qte">
                <span id="codplugin_add_button">+</span>
                <span id="codplugin_count_button">1</span>
                <span id="codplugin_remove_button">-</span>
            </div>
            <input id="nrwooconfirm" type="submit" name="codplugin-submit" value="<?php echo is_rtl() ? '👈' : '👉'; ?> <?php esc_attr_e('انقر هنا لتأكيد الطلب', 'wparabic-ecomm-cod'); ?>" form="codplugin_woo_single_form"<?php disabled(!$can_order); ?>>
        </div>

        <?php
        // Affiche si la case « إظهار زر الطلب عبر الواتساب » est cochee ET qu'un
        // numero existe : le numero WhatsApp, ou a defaut le telephone du site
        // (wparabic_whatsapp_number). Sans numero, le bouton ouvrirait
        // api.whatsapp.com/send?phone= : un lien mort.
        if (get_theme_mod('whatsapp_order_btn', true) && wparabic_whatsapp_number()) :
            ?>
            <div class="whatsapp-order-section">
                <button id="whatsapp-orders" type="button">
                    <i class="fab fa-whatsapp"></i>
                    <span><?php esc_html_e('أنقر هنا للطلب عبر الواتساب', 'wparabic-ecomm-cod'); ?></span>
                </button>
            </div>
        <?php endif; ?>

        <?php
        // Bloc « ملخص الطلب » : masquable (Personnaliser -> panneau du theme ->
        // صفحة المنتج). Masque par CSS seulement : main.js y lit le nom du produit
        // (message WhatsApp) et le copie dans l'ecran de remerciement.
        ?>
        <div class="codplugin-summary<?php echo get_theme_mod('cod_order_summary', true) ? '' : ' is-hidden'; ?>">
        <div id="codplugin_order_history" class="clear">
            <div id="codplugin_h_right">
                <span id="codplugin_h_o">
                    <i class="fas fa-shopping-cart "></i>
                    <?php esc_html_e('ملخص الطلب', 'wparabic-ecomm-cod'); ?>
                </span>
            </div>
            <div id="codplugin_h_left">
                <i class="fas fa-chevron-down"></i>
            </div>
        </div>

        <div id="codplugin_show_hide">
            <table>
                <?php
                /*
                 * Une ligne par article : la variation choisie dans le bloc i
                 * alimente la ligne i (assets/js/main.js). Les lignes au-dela
                 * du premier article restent cachees tant que le compteur ne
                 * les demandent pas. Produit simple : la ligne 1 seule existe.
                 */
                for ($i = 1; $i <= $max_articles; $i++) :
                    $row_price = ($i === 1) ? $default_price : '';
                    $row_label = ($i === 1) ? $variation_label : '';
                    ?>
                    <tr id="codplugin_row_<?php echo esc_attr($i); ?>"<?php echo $i > 1 ? ' style="display:none"' : ''; ?>>
                        <td class="summary-product-title">
                            <?php echo esc_html($product->get_name()); ?>
                            <?php if ($is_variable) : ?>
                                <small id="codplugin_variation_label_<?php echo esc_attr($i); ?>" class="codplugin-item-label"><?php echo esc_html($row_label); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span id="codplugin_count_number_<?php echo esc_attr($i); ?>">1</span>
                            <span id="codplugin_v_price_<?php echo esc_attr($i); ?>"><?php
                                echo $row_price === ''
                                    ? '&mdash;'
                                    : esc_html(number_format((float) $row_price, wc_get_price_decimals(), '.', ''));
                            ?></span>
                            <span> <?php echo esc_html($currency_symbol); ?></span>
                        </td>
                    </tr>
                <?php endfor; ?>
                <tr>
                    <td>
                        <span><?php esc_html_e('سعر التوصيل', 'wparabic-ecomm-cod'); ?></span>
                        <div id="shipping-methods"></div>
                    </td>
                    <td id="codplugin_d_has_price">
                        <span id="codplugin_d_price">
                            <span class="summary-select-state"><?php echo esc_html(sprintf(__('إختر %s', 'wparabic-ecomm-cod'), $state_placeholder)); ?></span>
                        </span>
                        <span class="codplugin_currency"> <?php echo esc_html($currency_symbol); ?></span>
                    </td>
                    <td id="codplugin_d_free" style="display:none;"><span><?php esc_html_e('مجانا', 'wparabic-ecomm-cod'); ?></span></td>
                </tr>
                <tr class="full-price">
                    <td><?php esc_html_e('السعر الإجمالي', 'wparabic-ecomm-cod'); ?></td>
                    <td><span id="codplugin_total_price"></span> <span> <?php echo esc_html($currency_symbol); ?></span></td>
                </tr>
            </table>
        </div>
        </div><!-- .codplugin-summary -->
    </div>
    <?php
}

/**
 * Ecran de remerciement. Comme la reference, il est rendu sur toutes les pages
 * (masque par CSS) : le JS le revele apres soumission.
 */
function wparabic_cod_thanks_popup() {
    ?>
    <div id="codplugin-thanks">
        <div id="codplugin-thanks-box">
            <p class="thanks-box-title blink-me"><?php esc_html_e('تم تقديم الطلب بنجاح', 'wparabic-ecomm-cod'); ?></p>
            <div class="thanks-box-content"><?php esc_html_e('سيقوم فريقنا بالتواصل معكم لتأكيد الطلبية.', 'wparabic-ecomm-cod'); ?></div>
            <div id="thanks-order-summary">
                <div class="order-summary-title">
                    <i class="fas fa-shopping-cart"></i>
                    <?php esc_html_e('ملخص الطلب', 'wparabic-ecomm-cod'); ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}
add_action('wp_footer', 'wparabic_cod_thanks_popup');

/* -------------------------------------------------------------------------
 * Creation de la commande
 * ------------------------------------------------------------------------- */

/**
 * Nonce frais pour le formulaire de commande. Une page produit servie depuis
 * un cache peut porter un nonce perime (12-24 h) : le JS en redemande un ici
 * et renvoie la commande une fois, au lieu d'echouer.
 */
add_action('wp_ajax_wparabic_cod_nonce', 'wparabic_cod_fresh_nonce');
add_action('wp_ajax_nopriv_wparabic_cod_nonce', 'wparabic_cod_fresh_nonce');
function wparabic_cod_fresh_nonce() {
    nocache_headers();
    wp_send_json_success(array('nonce' => wp_create_nonce('codplugin_nonce')));
}

/**
 * Adresse IP du client, pour la limite de debit uniquement (jamais stockee
 * en clair : seule une empreinte sert de cle de transient).
 *
 * Derriere un proxy (HTTPS, cache, Docker), REMOTE_ADDR est celle du proxy :
 * tous les clients partageraient la meme limite. Si la requete arrive d'une
 * adresse privee ou locale, donc d'un proxy interne, on prend la premiere
 * adresse publique de X-Forwarded-For. Une requete venue directement
 * d'Internet garde REMOTE_ADDR : l'en-tete, falsifiable, est alors ignore.
 */
function wparabic_cod_client_ip() {
    $remote = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    $ip     = $remote;

    $from_proxy = $remote !== '' && !filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

    if ($from_proxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
        foreach ($forwarded as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $ip = $candidate;
                break;
            }
        }
    }

    return (string) apply_filters('wparabic_cod_client_ip', $ip, $remote);
}

add_action('wp_ajax_codplugin_submit', 'wparabic_cod_ajax_handler');
add_action('wp_ajax_nopriv_codplugin_submit', 'wparabic_cod_ajax_handler');
function wparabic_cod_ajax_handler() {
    $nonce = isset($_POST['rwc-form-nonce']) ? sanitize_text_field(wp_unslash($_POST['rwc-form-nonce'])) : '';

    if (!wp_verify_nonce($nonce, 'codplugin_nonce')) {
        // code=nonce : le JS recupere un nonce frais et renvoie une fois.
        wp_send_json_error(array(
            'message' => __('انتهت صلاحية الجلسة. يرجى تحديث الصفحة.', 'wparabic-ecomm-cod'),
            'code'    => 'nonce',
        ));
    }

    // Piege a robots rempli : on repond « succes » sans rien creer, pour ne
    // pas apprendre au robot qu'il a ete detecte.
    if (!empty($_POST['codplugin_website'])) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        wp_send_json_success(array(
            'redirect' => home_url('/'),
            'message'  => __('تم تقديم الطلب بنجاح', 'wparabic-ecomm-cod'),
        ));
    }

    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $var_id     = isset($_POST['var_id']) ? absint($_POST['var_id']) : 0;
    $qty        = isset($_POST['codplugin_c_number']) ? max(1, absint($_POST['codplugin_c_number'])) : 1;
    // Un champ desactive dans Personnaliser -> (panneau du theme) -> حقول نموذج الطلب
    // renvoie toujours '' ici, meme si le POST en contient un.
    $name   = wparabic_cod_posted('name');
    $phone  = wparabic_cod_posted('phone');
    $state  = wparabic_cod_posted('state');
    $city   = wparabic_cod_posted('city');
    $street = wparabic_cod_posted('address');
    $email  = wparabic_cod_posted('email');

    if (!$product_id) {
        wp_send_json_error(array('message' => __('يرجى ملء جميع الحقول المطلوبة', 'wparabic-ecomm-cod')));
    }

    // Seuls les champs REELLEMENT affiches sont exiges. Le test uniforme
    // « === '' » remplace l'ancien melange !$name / $street === '' : un nom
    // saisi « 0 » n'est plus rejete a tort.
    $posted = array(
        'name'    => $name,
        'phone'   => $phone,
        'state'   => $state,
        'city'    => $city,
        'address' => $street,
        'email'   => $email,
    );

    foreach (wparabic_cod_fields() as $key => $field) {
        if ($field['required'] && wparabic_cod_field_enabled($key) && $posted[$key] === '') {
            wp_send_json_error(array('message' => __('يرجى ملء جميع الحقول المطلوبة', 'wparabic-ecomm-cod')));
        }
    }

    // Telephone tunisien : 8 chiffres. On tolere un prefixe +216 / 00216 colle
    // par le client, puis on revalide — le filtrage cote client n'est qu'un
    // confort, il ne protege de rien.
    $phone = preg_replace('/\D+/', '', $phone);
    // Le prefixe n'est retire que s'il reste 8 chiffres derriere : un numero
    // Ooredoo qui commence par 216 (21 6xx xxx) n'est pas un prefixe.
    $phone = preg_replace('/^(?:00)?216(?=\d{8}$)/', '', $phone);

    if (!preg_match('/^\d{8}$/', $phone)) {
        wp_send_json_error(array('message' => __('يجب أن يتكون رقم الهاتف من 8 أرقام', 'wparabic-ecomm-cod')));
    }

    $governorates = wparabic_get_tunisian_governorates();
    if (!isset($governorates[$state])) {
        wp_send_json_error(array('message' => __('الولاية غير صحيحة', 'wparabic-ecomm-cod')));
    }

    if ($city && !wparabic_is_valid_delegation($state, $city)) {
        wp_send_json_error(array('message' => __('المعتمدية غير صحيحة', 'wparabic-ecomm-cod')));
    }

    $product = wc_get_product($product_id);
    if (!$product) {
        wp_send_json_error(array('message' => __('المنتج غير موجود', 'wparabic-ecomm-cod')));
    }

    /*
     * Produit variable : le client commande PLUSIEURS « مقطع », chacun avec
     * SA variation (pairs[0][var_id], pairs[1][var_id], …). Les combinaisons
     * identiques sont regroupees : une ligne de commande lisible
     * (« 2 × أسود/42 ») et un stock évalué groupe par groupe. Le POST
     * historique mono-article (var_id + quantite) reste accepte : une page
     * servie par un cache avec l'ancien JS doit continuer a fonctionner.
     */
    $pairs_post = array();

    if (isset($_POST['pairs']) && is_array($_POST['pairs'])) {
        $raw_pairs = wp_unslash($_POST['pairs']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        foreach ($raw_pairs as $pair) {
            if (is_array($pair) && isset($pair['var_id'])) {
                $pairs_post[] = absint($pair['var_id']);
            }
        }
    }

    // Un client trivial pourrait poster moins d'articles que le compteur :
    // completOuPas, le nombre doit correspondre exactement.
    if ($pairs_post && count($pairs_post) !== $qty) {
        $pairs_post = array();
    }
    if (!$pairs_post && $product->is_type('variable') && $var_id) {
        $pairs_post = array_fill(0, $qty, $var_id);
    }

    // Grouper les articles identiques : id de variation => quantite.
    $groups = array();
    foreach ($pairs_post as $vid) {
        $groups[$vid] = isset($groups[$vid]) ? $groups[$vid] + 1 : 1;
    }

    /*
     * Liste blanche + controles par groupe. Chaque variation doit appartenir
     * a CE produit (moins couteux que get_available_variations(), qui
     * chargeait chaque variation et ses images a chaque commande). Stock,
     * « vendu individuellement » et quantite maximale d'achat de WooCommerce
     * (-1 = illimitee) sont evalues avec la quantite GROUPEE.
     */
    $grouped   = array();
    $subtotal  = 0.0;

    if ($product->is_type('variable')) {
        if (!$groups) {
            wp_send_json_error(array('message' => wparabic_cod_choose_hint($product)));
        }

        foreach ($groups as $vid => $count) {
            $variation = wc_get_product($vid);

            if (!$variation instanceof WC_Product_Variation
                || $variation->get_parent_id() !== $product->get_id()) {
                wp_send_json_error(array('message' => wparabic_cod_choose_hint($product)));
            }

            if ($variation->is_sold_individually() && $count > 1) {
                wp_send_json_error(array(
                    /* translators: %d: maximum quantity */
                    'message' => sprintf(__('الكمية المطلوبة غير متوفرة. الحد الأقصى : %d', 'wparabic-ecomm-cod'), 1),
                ));
            }

            $max_qty = (int) $variation->get_max_purchase_quantity();
            if ($max_qty > 0 && $count > $max_qty) {
                wp_send_json_error(array(
                    /* translators: %d: maximum quantity */
                    'message' => sprintf(__('الكمية المطلوبة غير متوفرة. الحد الأقصى : %d', 'wparabic-ecomm-cod'), $max_qty),
                ));
            }

            // Controle de stock cote serveur : un POST forge sur une variation
            // epuisee passait jusqu'ici.
            if (!$variation->is_purchasable()
                || !$variation->is_in_stock()
                || !$variation->has_enough_stock($count)) {
                wp_send_json_error(array('message' => __('الخيار المختار غير متوفر حاليا', 'wparabic-ecomm-cod')));
            }

            $grouped[$vid] = array('variation' => $variation, 'count' => $count);
            $subtotal += (float) $variation->get_price() * $count;
        }
    } else {
        // Produit simple : comportement historique (une ligne = produit × qty).
        if ($product->is_sold_individually()) {
            $qty = 1;
        }
        $max_qty = (int) $product->get_max_purchase_quantity();
        if ($max_qty > 0 && $qty > $max_qty) {
            wp_send_json_error(array(
                /* translators: %d: maximum quantity */
                'message' => sprintf(__('الكمية المطلوبة غير متوفرة. الحد الأقصى : %d', 'wparabic-ecomm-cod'), $max_qty),
            ));
        }

        if (!$product->is_purchasable()
            || !$product->is_in_stock()
            || !$product->has_enough_stock($qty)) {
            wp_send_json_error(array('message' => __('الخيار المختار غير متوفر حاليا', 'wparabic-ecomm-cod')));
        }

        $grouped[$product->get_id()] = array('variation' => $product, 'count' => $qty);
        $subtotal = (float) $product->get_price() * $qty;
    }

    // Tarif recalcule cote serveur depuis les zones WooCommerce : jamais lu
    // depuis le POST. Zone + quantite TOTALE (le parent porte la classe de
    // livraison, pas les variations).
    $rate     = wparabic_get_shipping_rate($state, $product, $qty);
    $shipping = (float) $rate['cost'];
    $total    = $subtotal + $shipping;

    // Anti-doublon : meme telephone, meme LISTE d'articles en moins de
    // 3 minutes (double clic, retour arriere, robot) => pas de 2e commande.
    // Deux paniers differents (variations differentes) restent acceptes.
    $sig_items = array();
    foreach ($groups as $vid => $count) {
        $sig_items[] = $vid . 'x' . $count;
    }
    $dup_key = 'wparabic_cod_dup_' . md5($phone . '|' . $product->get_id() . '|' . implode('|', $sig_items));
    if (get_transient($dup_key)) {
        wp_send_json_error(array('message' => __('طلبك قيد المعالجة. لقد استلمنا هذا الطلب للتو.', 'wparabic-ecomm-cod')));
    }

    // Limite de debit par adresse IP, volontairement large : les operateurs
    // mobiles tunisiens partagent une meme IP entre de nombreux clients.
    // Filtre wparabic_cod_rate_limit : array(nombre, duree en secondes).
    $limit   = (array) apply_filters('wparabic_cod_rate_limit', array(10, 10 * MINUTE_IN_SECONDS));
    $ip      = wparabic_cod_client_ip();
    $ip_key  = 'wparabic_cod_ip_' . md5($ip);
    $ip_hits = (int) get_transient($ip_key);
    if ($ip !== '' && $ip_hits >= (int) $limit[0]) {
        wp_send_json_error(array('message' => __('عدد كبير من الطلبات. يرجى المحاولة بعد بضع دقائق.', 'wparabic-ecomm-cod')));
    }

    // created_via distingue ces commandes de « checkout » dans les rapports.
    $order = wc_create_order(array('created_via' => 'cod-form'));
    if (is_wp_error($order)) {
        wp_send_json_error(array('message' => __('حدث خطأ أثناء إنشاء الطلب. يرجى المحاولة مرة أخرى.', 'wparabic-ecomm-cod')));
    }

    // Tout ce qui suit peut lever une exception (WC_Data_Exception sur un
    // champ refuse...) : sans ce try, la commande restait « en attente »,
    // orpheline, et le client recevait une erreur 500.
    try {
        // Une ligne par combinaison choisie : « 2 × أسود/42 » reste lisible
        // pour le vendeur, et le total porte le vrai prix de chaque variation.
        foreach ($grouped as $group) {
            $order->add_product($group['variation'], $group['count']);
        }

        // Si le champ nom est masque, on retombe sur le telephone plutot que de
        // laisser un nom vide : la liste des commandes et les emails WooCommerce
        // afficheraient « Bonjour , ». Le telephone est garanti present (champ
        // verrouille) et c'est l'identifiant dont se sert reellement un vendeur COD.
        $billing_name = apply_filters('wparabic_cod_billing_name', $name !== '' ? $name : $phone, $name, $phone);

        $address = array(
            'first_name' => $billing_name,
            'phone'      => $phone,
            'country'    => 'TN',
            // Code ISO (TN-11...) et non le nom arabe : donnee normalisee,
            // exploitable par les zones de livraison et toute integration.
            'state'      => wparabic_state_code($state),
            'city'       => $city,
            'address_1'  => $street,
        );

        if ($email) {
            $address['email'] = $email;
        }

        $order->set_address($address, 'billing');
        $order->set_address($address, 'shipping');

        // Une vraie ligne de livraison, pas un frais : shipping_total devient
        // reel et les extensions de transporteur trouvent une methode.
        $order->add_item(wparabic_build_shipping_item($rate));

        $order->set_payment_method('cod');
        // Titre de la passerelle « paiement a la livraison » tel que regle dans
        // WooCommerce > Reglages > Paiements : le meme pour toutes les langues
        // de la boutique (il apparait sur la page de remerciement et les
        // e-mails). Repli traduit si la passerelle est absente.
        $gateways  = (function_exists('WC') && WC()->payment_gateways()) ? WC()->payment_gateways()->payment_gateways() : array();
        $cod_title = isset($gateways['cod']) ? $gateways['cod']->get_title() : '';
        $order->set_payment_method_title($cod_title !== '' ? $cod_title : __('الدفع عند الاستلام', 'wparabic-ecomm-cod'));

        // Langue dans laquelle le client a commande (inc/languages.php).
        if (function_exists('wparabic_current_lang')) {
            $order->update_meta_data('_wparabic_lang', wparabic_current_lang());
        }
        // Note client construite depuis les seuls champs affiches : pas de
        // « معتمدية: — » pour un champ que le marchand a coupe. A configuration par
        // defaut le texte est identique a l'ancien sprintf.
        $note_values = array(
            'phone'   => $phone,
            // Le nom arabe de la wilaya, plus lisible que le slug ou le code ISO.
            'state'   => isset($governorates[$state]) ? $governorates[$state] : $state,
            'city'    => $city,
            'address' => $street,
        );

        $note = array();

        foreach (wparabic_cod_fields() as $key => $field) {
            if ($field['note'] === '' || !wparabic_cod_field_enabled($key) || $note_values[$key] === '') {
                continue;
        }
            $note[] = wparabic_cod_note_label($field['note']) . ': ' . $note_values[$key];
        }

        array_unshift($note, wparabic_cod_note_label('طلب COD') . ': ' . $billing_name);

        // Le vendeur relit les articles choisis d'un coup d'œil, meme si les
        // lignes de commande suffisent : « أسود، 42 ×2، أسود، 44 ».
        $variation_note = array();
        foreach ($grouped as $group) {
            if ($group['variation'] instanceof WC_Product_Variation) {
                $variation_note[] = wc_get_formatted_variation($group['variation'], true, false)
                    . ($group['count'] > 1 ? ' ×' . $group['count'] : '');
            }
        }
        if ($variation_note) {
            $note[] = wparabic_cod_note_label('المقاطع') . ': ' . implode(wparabic_note_list_separator(), $variation_note);
        }

        $order->set_customer_note(implode(' | ', $note));
        $order->calculate_totals();
        $status_note = (function_exists('wparabic_current_lang') && wparabic_current_lang() === 'fr')
            ? 'Commande COD via le site'
            : 'طلب COD عبر الموقع';
        $order->set_status('processing', $status_note);

        $order_id = $order->save();
    } catch (Exception $e) {
        $order->delete(true);
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error('Commande COD annulee : ' . $e->getMessage(), array('source' => 'wparabic-cod'));
        }
        $order_id = 0;
    }

    if (!$order_id) {
        wp_send_json_error(array('message' => __('حدث خطأ أثناء إنشاء الطلب. يرجى المحاولة مرة أخرى.', 'wparabic-ecomm-cod')));
    }

    set_transient($dup_key, 1, 3 * MINUTE_IN_SECONDS);
    if ($ip !== '') {
        set_transient($ip_key, $ip_hits + 1, (int) $limit[1]);
    }

    /**
     * Hook : woocommerce_checkout_order_created.
     * Declenche par WC_Checkout pour toute commande creee au paiement.
     * WooCommerce y enregistre l'origine de la commande (champs
     * wc_order_attribution_* du formulaire) ; les extensions s'y accrochent.
     */
    do_action('woocommerce_checkout_order_created', $order);

    // Sans cela le controle de stock ci-dessus resterait decoratif des que la
    // gestion de stock par variation sera activee.
    wc_reduce_stock_levels($order_id);

    /**
     * Hook : woocommerce_checkout_order_processed.
     * C'est le point d'accroche de la plupart des integrations (ERP,
     * etiquettes de transport, notifications). Il ne se declenchait pas, le
     * formulaire COD ne passant pas par WC_Checkout.
     */
    do_action('woocommerce_checkout_order_processed', $order_id, array(), $order);

    wp_send_json_success(array(
        'redirect' => $order->get_checkout_order_received_url(),
        'message'   => sprintf(__('تم تأكيد طلبك بنجاح! سيتم الاتصال بك قريبًا على الرقم %s', 'wparabic-ecomm-cod'), $phone),
        'order_id'  => $order_id,
        'order_key' => $order->get_order_key(),
        'product'   => $product->get_name(),
        'quantity'  => $qty,
        'subtotal'  => wc_price($subtotal),
        'shipping'  => wc_price($shipping),
        'total'     => wc_price($total),
    ));
}
