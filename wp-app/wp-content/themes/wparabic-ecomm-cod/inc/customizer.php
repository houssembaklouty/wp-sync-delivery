<?php
/**
 * Options de personnalisation.
 *
 * Le systeme de « presets » de couleurs a ete retire : le theme de reference
 * n'utilise pas de custom properties, il surcharge des selecteurs concrets.
 * Les trois reglages de couleur ci-dessous correspondent exactement aux trois
 * regles que la demo injecte en ligne (voir wparabic_inline_css()).
 *
 * @package wparabic-ecomm-cod
 */

defined('ABSPATH') || exit;

add_action('customize_register', 'wparabic_customize');
function wparabic_customize($wp) {

    $wp->add_panel('wparabic', array(
        // Le titre du site (Reglages -> General), pas une marque en dur.
        'title'    => wparabic_brand(),
        'priority' => 30,
    ));

    /* --- Langue de la boutique (inc/languages.php) ------------------------
     * Independante de la langue de l'administration. */
    $wp->add_section('wparabic_language', array(
        'title' => __('اللغة', 'wparabic-ecomm-cod'),
        'panel' => 'wparabic',
    ));

    $lang_choices = array();
    foreach (wparabic_languages() as $code => $lang) {
        $lang_choices[$code] = $lang['label'];
    }

    $wp->add_setting('front_lang_default', array(
        'default'           => 'ar',
        'sanitize_callback' => function ($value) {
            return array_key_exists($value, wparabic_languages()) ? $value : 'ar';
        },
    ));
    $wp->add_control('front_lang_default', array(
        'label'       => __('اللغة الافتراضية للمتجر', 'wparabic-ecomm-cod'),
        'description' => __('لغة الواجهة للزوار. لا تؤثر على لغة لوحة التحكم.', 'wparabic-ecomm-cod'),
        'section'     => 'wparabic_language',
        'type'        => 'select',
        'choices'     => $lang_choices,
    ));

    $wp->add_setting('front_lang_switcher', array(
        'default'           => false,
        'sanitize_callback' => 'wparabic_sanitize_checkbox',
    ));
    $wp->add_control('front_lang_switcher', array(
        'label'       => __('إظهار قائمة اختيار اللغة للزوار', 'wparabic-ecomm-cod'),
        'description' => __('في رأس الصفحة وفي قائمة الهاتف. يحفظ اختيار الزائر لمدة سنة.', 'wparabic-ecomm-cod'),
        'section'     => 'wparabic_language',
        'type'        => 'checkbox',
    ));

    foreach (wparabic_languages() as $code => $lang) {
        $wp->add_setting('front_lang_' . $code, array(
            'default'           => true,
            'sanitize_callback' => 'wparabic_sanitize_checkbox',
        ));
        $wp->add_control('front_lang_' . $code, array(
            /* translators: %s: language name */
            'label'       => sprintf(__('اقتراح اللغة : %s', 'wparabic-ecomm-cod'), $lang['label']),
            'section'     => 'wparabic_language',
            'type'        => 'checkbox',
        ));
    }

    /* --- Couleurs ------------------------------------------------------ */
    $wp->add_section('wparabic_colors', array(
        'title' => __('الألوان', 'wparabic-ecomm-cod'),
        'panel' => 'wparabic',
    ));

    $colors = array(
        // Teintes claires (bordures et fonds du formulaire, bouton desactive)
        // calculees a partir de la couleur principale : wparabic_inline_css().
        'primary_color' => array(WPARABIC_PRIMARY_DEFAULT, __('اللون الرئيسي (الأزرار، الروابط، الأسعار)', 'wparabic-ecomm-cod')),
        'accent_color'  => array(WPARABIC_ACCENT_DEFAULT, __('لون التمرير (الأزرار عند المرور)', 'wparabic-ecomm-cod')),
        'topbar_bg'   => array('#000000', __('خلفية الشريط العلوي', 'wparabic-ecomm-cod')),
        'topbar_text' => array('#ffdf86', __('لون نص الشريط العلوي', 'wparabic-ecomm-cod')),
        'footer_bg'   => array('#fafafa', __('خلفية أسفل الصفحة', 'wparabic-ecomm-cod')),
    );

    foreach ($colors as $id => $data) {
        list($default, $label) = $data;

        $wp->add_setting($id, array(
            'default'           => $default,
            'sanitize_callback' => 'sanitize_hex_color',
            // Apercu instantane : assets/js/customizer-preview.js
            'transport'         => 'postMessage',
        ));

        $wp->add_control(new WP_Customize_Color_Control($wp, $id, array(
            'label'   => $label,
            'section' => 'wparabic_colors',
        )));
    }

    // Texte du pied de page : vide = automatique (clair sur fond sombre,
    // sombre sur fond clair), voir wparabic_footer_text_color().
    $wp->add_setting('footer_text_color', array(
        'default'           => '',
        'sanitize_callback' => 'sanitize_hex_color',
        'transport'         => 'postMessage',
    ));
    $wp->add_control(new WP_Customize_Color_Control($wp, 'footer_text_color', array(
        'label'       => __('لون نص أسفل الصفحة', 'wparabic-ecomm-cod'),
        'description' => __('اتركه فارغًا ليُختار تلقائيًا حسب لون الخلفية (فاتح على خلفية داكنة، داكن على خلفية فاتحة).', 'wparabic-ecomm-cod'),
        'section'     => 'wparabic_colors',
    )));

    /* --- Voile des tuiles (banniere d'accroche + mosaique categories) -------
     * .block-item::before : couleur + intensite du voile sombre. 0 % le
     * desactive. Variable CSS --wparabic-tile-overlay (wparabic_inline_css),
     * repli rgb(43 44 44 / 40%) dans style.css. */
    $wp->add_setting('tile_overlay_color', array(
        'default'           => '#2b2c2c',
        'sanitize_callback' => 'sanitize_hex_color',
        'transport'         => 'postMessage',
    ));
    $wp->add_control(new WP_Customize_Color_Control($wp, 'tile_overlay_color', array(
        'label'   => __('لون ستارة البلاطات (البانر)', 'wparabic-ecomm-cod'),
        'section' => 'wparabic_colors',
    )));

    $wp->add_setting('tile_overlay_opacity', array(
        'default'           => 40,
        'sanitize_callback' => function ($value) {
            return max(0, min(100, absint($value)));
        },
        'transport'         => 'postMessage',
    ));
    $wp->add_control('tile_overlay_opacity', array(
        'label'       => __('شدّة ستارة البلاطات (%)', 'wparabic-ecomm-cod'),
        'description' => __('0 % يعطّل الستارة تمامًا (الصورة كما هي).', 'wparabic-ecomm-cod'),
        'section'     => 'wparabic_colors',
        'type'        => 'range',
        'input_attrs' => array(
            'min'  => 0,
            'max'  => 90,
            'step' => 5,
        ),
    ));

    /* --- Barre haute --------------------------------------------------- */
    $wp->add_section('wparabic_topbar', array(
        'title' => __('الشريط العلوي', 'wparabic-ecomm-cod'),
        'panel' => 'wparabic',
    ));

    $wp->add_setting('topbar_msg', array(
        'default'           => wparabic_default('topbar_msg'),
        'sanitize_callback' => 'sanitize_text_field',
    ));
    $wp->add_control('topbar_msg', array(
        'label'       => __('الرسائل', 'wparabic-ecomm-cod'),
        'description' => __('افصل بين الرسائل بالرمز |', 'wparabic-ecomm-cod'),
        'section'     => 'wparabic_topbar',
        'type'        => 'textarea',
    ));

    // Vitesse du defilement continu (initTopMarquee, assets/js/main.js).
    $wp->add_setting('topbar_speed', array(
        'default'           => 50,
        'sanitize_callback' => function ($value) {
            return max(10, min(200, absint($value)));
        },
    ));
    $wp->add_control('topbar_speed', array(
        'label'       => __('سرعة تمرير الرسائل', 'wparabic-ecomm-cod'),
        'description' => __('بالبكسل في الثانية (10 بطيء جدًا — 200 سريع جدًا). القيمة المقترحة : 50.', 'wparabic-ecomm-cod'),
        'section'     => 'wparabic_topbar',
        'type'        => 'number',
        'input_attrs' => array('min' => 10, 'max' => 200, 'step' => 5),
    ));

    /* --- Largeur du contenu ---------------------------------------------- */
    $wp->add_section('wparabic_layout', array(
        'title'       => __('التخطيط', 'wparabic-ecomm-cod'),
        'description' => __('الشريط العلوي وأسفل الصفحة يبقيان بعرض الشاشة دائمًا.', 'wparabic-ecomm-cod'),
        'panel'       => 'wparabic',
    ));

    $layout_choices = array(
        'fluid' => __('بعرض الشاشة', 'wparabic-ecomm-cod'),
        'boxed' => __('محتوى محدود العرض', 'wparabic-ecomm-cod'),
    );

    $layouts = array(
        'layout_home'    => __('الصفحة الرئيسية', 'wparabic-ecomm-cod'),
        'layout_shop'    => __('المتجر', 'wparabic-ecomm-cod'),
        'layout_product' => __('صفحة المنتج', 'wparabic-ecomm-cod'),
        'layout_default' => __('بقية الصفحات', 'wparabic-ecomm-cod'),
    );

    foreach ($layouts as $id => $label) {
        $wp->add_setting($id, array(
            'default'           => 'fluid',
            'sanitize_callback' => 'wparabic_sanitize_layout',
        ));
        $wp->add_control($id, array(
            'label'   => $label,
            'section' => 'wparabic_layout',
            'type'    => 'select',
            'choices' => $layout_choices,
        ));
    }

    /* --- Fiche produit --------------------------------------------------- */
    $wp->add_section('wparabic_product', array(
        'title' => __('صفحة المنتج', 'wparabic-ecomm-cod'),
        'panel' => 'wparabic',
    ));

    // Elements de la fiche produit que le marchand peut masquer.
    $product_toggles = array(
        'product_tab_additional' => array(__('إظهار تبويب « معلومات إضافية »', 'wparabic-ecomm-cod'), ''),
        'product_tab_reviews'    => array(__('إظهار تبويب « المراجعات »', 'wparabic-ecomm-cod'), __('يخفي التبويب فقط. لإيقاف التقييمات نهائيًا : ووكومرس ← الإعدادات ← المنتجات.', 'wparabic-ecomm-cod')),
        'cod_order_summary'      => array(__('إظهار « ملخص الطلب » في نموذج الطلب', 'wparabic-ecomm-cod'), __('المنتج، سعر التوصيل والسعر الإجمالي تحت زر تأكيد الطلب.', 'wparabic-ecomm-cod')),
    );
    foreach ($product_toggles as $id => $toggle) {
        $wp->add_setting($id, array(
            'default'           => true,
            'sanitize_callback' => 'wparabic_sanitize_checkbox',
        ));
        $wp->add_control($id, array(
            'label'       => $toggle[0],
            'description' => $toggle[1],
            'section'     => 'wparabic_product',
            'type'        => 'checkbox',
        ));
    }

    $wp->add_setting('sticky_atc', array(
        'default'           => true,
        'sanitize_callback' => 'wparabic_sanitize_checkbox',
    ));
    $wp->add_control('sticky_atc', array(
        'label'       => __('زر « اشتري الآن » العائم', 'wparabic-ecomm-cod'),
        'description' => __('يظهر في أسفل الشاشة على الهاتف واللوحي عندما يخرج زر تأكيد الطلب من الشاشة.', 'wparabic-ecomm-cod'),
        'section'     => 'wparabic_product',
        'type'        => 'checkbox',
    ));

    /* --- Champs du formulaire de commande --------------------------------
     * La table vit dans inc/cod-form.php : elle pilote aussi le rendu et la
     * validation. Les champs « locked » (telephone, wilaya) n'apparaissent pas
     * ici — voir le commentaire de wparabic_cod_fields() pour le pourquoi.
     * ------------------------------------------------------------------- */
    $wp->add_section('wparabic_cod_fields', array(
        'title'       => __('حقول نموذج الطلب', 'wparabic-ecomm-cod'),
        'description' => __('اختر الحقول التي تظهر في نموذج الطلب. رقم الهاتف والولاية يظهران دائمًا: الهاتف هو وسيلة تأكيد الطلب، والولاية تحدد سعر التوصيل.', 'wparabic-ecomm-cod'),
        'panel'       => 'wparabic',
    ));

    foreach (wparabic_cod_fields() as $key => $field) {
        if ($field['locked']) {
            continue;
        }

        $wp->add_setting('cod_field_' . $key, array(
            'default'           => true,
            'sanitize_callback' => 'wparabic_sanitize_checkbox',
        ));
        $wp->add_control('cod_field_' . $key, array(
            'label'       => $field['label'],
            'description' => $field['desc'],
            'section'     => 'wparabic_cod_fields',
            'type'        => 'checkbox',
        ));
    }

    /* --- Sections de la page d'accueil ----------------------------------
     * Retirees : la page d'accueil est desormais composee de widgets.
     * Voir Apparence -> Widgets -> « أقسام الصفحة الرئيسية », ou l'onglet
     * Widgets de ce meme personnalisateur.
     *
     * Les anciens reglages home_* restent en base : inc/home-widgets.php les
     * relit dans defaults(), ce qui sert de pont de compatibilite pour le
     * rendu de repli (zone de widgets vide).
     * ------------------------------------------------------------------- */

    /* --- Contact -------------------------------------------------------- */
    $wp->add_section('wparabic_contact', array(
        'title' => __('التواصل', 'wparabic-ecomm-cod'),
        'panel' => 'wparabic',
    ));

    $wp->add_setting('contact_phone', array(
        'default'           => wparabic_default('contact_phone'),
        'sanitize_callback' => 'sanitize_text_field',
    ));
    $wp->add_control('contact_phone', array(
        'label'       => __('رقم الهاتف', 'wparabic-ecomm-cod'),
        'description' => __('اتركه فارغًا لإخفاء زر الاتصال العائم.', 'wparabic-ecomm-cod'),
        'section'     => 'wparabic_contact',
    ));

    $wp->add_setting('whatsapp', array(
        'default'           => wparabic_default('whatsapp'),
        'sanitize_callback' => 'sanitize_text_field',
    ));
    $wp->add_control('whatsapp', array(
        'label'       => __('رقم واتساب (اختياري)', 'wparabic-ecomm-cod'),
        'description' => __('اتركه فارغًا لاستعمال رقم الهاتف أعلاه. أدخل رقمًا آخر إذا كان رقم واتساب مختلفًا، مثال : 216XXXXXXXX أو XXXXXXXX.', 'wparabic-ecomm-cod'),
        'section'     => 'wparabic_contact',
    ));

    // Afficher ou masquer le bouton sans perdre le numero enregistre.
    $wp->add_setting('whatsapp_order_btn', array(
        'default'           => true,
        'sanitize_callback' => 'wparabic_sanitize_checkbox',
    ));
    $wp->add_control('whatsapp_order_btn', array(
        'label'       => __('إظهار زر « الطلب عبر الواتساب »', 'wparabic-ecomm-cod'),
        'description' => __('في صفحة المنتج، تحت زر تأكيد الطلب. يستعمل رقم واتساب، أو رقم الهاتف إن كان فارغًا.', 'wparabic-ecomm-cod'),
        'section'     => 'wparabic_contact',
        'type'        => 'checkbox',
    ));

    /* --- Reseaux sociaux ------------------------------------------------ */
    $wp->add_section('wparabic_socials', array(
        'title' => __('شبكات التواصل', 'wparabic-ecomm-cod'),
        'panel' => 'wparabic',
    ));

    foreach (array('facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok') as $key => $label) {
        $wp->add_setting('social_' . $key, array(
            'default'           => wparabic_default('social_' . $key),
            'sanitize_callback' => 'esc_url_raw',
        ));
        $wp->add_control('social_' . $key, array(
            'label'   => $label,
            'section' => 'wparabic_socials',
            'type'    => 'url',
        ));
    }

    /* --- Pied de page --------------------------------------------------- */
    $wp->add_section('wparabic_footer', array(
        'title' => __('أسفل الصفحة', 'wparabic-ecomm-cod'),
        'panel' => 'wparabic',
    ));

    $wp->add_setting('footer_text', array(
        'default'           => wparabic_default('footer_text'),
        'sanitize_callback' => 'sanitize_text_field',
    ));
    $wp->add_control('footer_text', array(
        'label'       => __('نص الحقوق', 'wparabic-ecomm-cod'),
        'description' => __('سطر إضافي أسفل اسم المتجر. اتركه فارغًا لإخفائه.', 'wparabic-ecomm-cod'),
        'section'     => 'wparabic_footer',
    ));

    $wp->add_setting('footer_credit', array(
        'default'           => wparabic_default('footer_credit'),
        'sanitize_callback' => 'wp_kses_post',
    ));
    $wp->add_control('footer_credit', array(
        'label'       => __('سطر الإسناد', 'wparabic-ecomm-cod'),
        'description' => __('اتركه فارغًا لإخفائه.', 'wparabic-ecomm-cod'),
        'section'     => 'wparabic_footer',
        'type'        => 'textarea',
    ));
}

/**
 * Assainit une case a cocher du personnalisateur.
 *
 * Les autres reglages du theme sont textuels et utilisent
 * sanitize_text_field ; c'est la premiere case a cocher.
 *
 * @param mixed $value
 * @return bool
 */
function wparabic_sanitize_checkbox($value) {
    return (bool) $value;
}

/**
 * Assainit un reglage de largeur de contenu.
 *
 * @param mixed $value
 * @return string « fluid » ou « boxed ».
 */
function wparabic_sanitize_layout($value) {
    return in_array($value, array('fluid', 'boxed'), true) ? $value : 'fluid';
}

/**
 * Script de l'apercu en direct des couleurs (transport postMessage).
 */
add_action('customize_preview_init', 'wparabic_customize_preview_js');
function wparabic_customize_preview_js() {
    wp_enqueue_script(
        'wparabic-customizer-preview',
        WPARABIC_ASSETS . '/js/customizer-preview.js',
        array('customize-preview'),
        wparabic_asset_ver('assets/js/customizer-preview.js'),
        true
    );
}

/**
 * Variantes par langue des textes du personnalisateur (wparabic_i18n_mods()).
 *
 * Pour chaque texte de base deja declare ci-dessus, un champ par langue
 * proposee (« الرسائل — Français »), juste apres lui, avec le meme type, la
 * meme section et le meme nettoyage. Vide : le texte de base s'affiche.
 * Lecture : wparabic_mod_i18n() dans functions.php.
 */
add_action('customize_register', 'wparabic_customize_i18n_mods', 20);
function wparabic_customize_i18n_mods($wp) {
    $languages = wparabic_languages();

    foreach (wparabic_i18n_mods() as $key) {
        $base_setting = $wp->get_setting($key);
        $base_control = $wp->get_control($key);
        if (!$base_setting || !$base_control) {
            continue;
        }

        $priority = (int) $base_control->priority;

        foreach ($languages as $code => $lang) {
            $id = $key . '__' . $code;

            $wp->add_setting($id, array(
                'default'           => '',
                'sanitize_callback' => $base_setting->sanitize_callback,
            ));
            $wp->add_control($id, array(
                'label'       => $base_control->label . ' — ' . $lang['label'],
                'description' => __('اتركه فارغًا لاستعمال النص الأساسي أعلاه.', 'wparabic-ecomm-cod'),
                'section'     => $base_control->section,
                'type'        => $base_control->type,
                'priority'    => $priority,
                'input_attrs' => array('dir' => $lang['rtl'] ? 'rtl' : 'ltr', 'lang' => str_replace('_', '-', $lang['locale'])),
            ));
        }
    }
}
