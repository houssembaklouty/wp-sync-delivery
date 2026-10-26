<?php
/**
 * Sections de la page d'accueil, sous forme de widgets.
 *
 * Chaque section est un WP_Widget depose dans la zone « widget-sections »
 * (Apparence -> Widgets). L'ordre a l'ecran suit l'ordre des widgets.
 *
 * Le nom interne (id_base) de chaque widget est choisi pour que WordPress
 * regenere exactement le markup du theme de reference : il construit l'id
 * comme « {id_base}-{numero} » et la classe comme « widget_{id_base} », donc
 * id_base = at-home-latest-products donne
 *   <section id="at-home-latest-products-1" class="widget-section widget_at-home-latest-products">
 * sans la moindre manipulation de chaine.
 *
 * Nos widgets n'utilisent pas before_title/after_title : ils ont besoin d'un
 * titre ET d'un sous-titre dans le meme .section-heading-content, ce que ces
 * gabarits ne savent pas exprimer. Ils appellent wparabic_home_section_heading()
 * a la place, tout en passant par le filtre widget_title.
 *
 * @package wparabic-ecomm-cod
 */

defined('ABSPATH') || exit;

/**
 * Titre + sous-titre d'une section, dans le markup de la reference.
 *
 * @param string $title
 * @param string $subtitle
 */
function wparabic_home_section_heading($title, $subtitle) {
    $title    = (string) $title;
    $subtitle = (string) $subtitle;

    if ($title === '' && $subtitle === '') {
        return;
    }
    ?>
    <div class="section-heading">
        <div class="section-heading-content">
            <?php if ($title !== '') : ?>
                <h2 class="section-title"><?php echo esc_html($title); ?></h2>
            <?php endif; ?>
            <?php if ($subtitle !== '') : ?>
                <p class="section-subtitle"><?php echo esc_html($subtitle); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * Classe de base
 * ------------------------------------------------------------------------- */

abstract class WPArabic_Home_Widget extends WP_Widget {

    /**
     * Valeurs par defaut de l'instance.
     *
     * @return array
     */
    public function defaults() {
        return array('enabled' => 1, 'title' => '', 'subtitle' => '');
    }

    /**
     * La section est-elle masquee ?
     *
     * A appeler en TOUT PREMIER dans widget(), avant d'emettre before_widget :
     * une <section> vide fausserait .widget-section:last-child{margin-bottom:0}.
     *
     * @param array $instance
     * @return bool
     */
    protected function is_disabled($instance) {
        $instance = wp_parse_args((array) $instance, $this->defaults());

        return empty($instance['enabled']);
    }

    /**
     * Fusionne l'instance avec les defauts et applique le filtre widget_title.
     *
     * wp_parse_args ne remplit que les cles ABSENTES : un titre volontairement
     * vide par l'utilisateur le reste.
     *
     * @param array $instance
     * @return array
     */
    protected function prepare($instance) {
        $instance = wp_parse_args((array) $instance, $this->defaults());

        // Traduction de la langue de la boutique (« title__fr »...) si elle
        // est remplie ; sinon le texte de base reste.
        $lang = function_exists('wparabic_current_lang') ? wparabic_current_lang() : '';
        foreach (array_keys($instance) as $key) {
            if (self::is_translatable_key($key)) {
                $variant = $key . '__' . $lang;
                if (isset($instance[$variant]) && $instance[$variant] !== '') {
                    $instance[$key] = $instance[$variant];
                }
            }
        }

        $instance['title'] = apply_filters('widget_title', $instance['title'], $instance, $this->id_base);

        return $instance;
    }

    /**
     * Champs texte qui ont une version par langue (titre, sous-titre, texte
     * du bouton, titres et textes des quatre avantages).
     *
     * @param string $key
     * @return bool
     */
    public static function is_translatable_key($key) {
        return in_array($key, array('title', 'subtitle', 'button_text'), true)
            || (bool) preg_match('/^item\d+_(title|text)$/', $key);
    }

    /** Libelle lisible d'un champ traduisible. */
    public static function translatable_label($key) {
        if ($key === 'title') {
            return __('العنوان', 'wparabic-ecomm-cod');
        }
        if ($key === 'subtitle') {
            return __('الوصف', 'wparabic-ecomm-cod');
        }
        if ($key === 'button_text') {
            return __('نص الزر', 'wparabic-ecomm-cod');
        }
        if (preg_match('/^item(\d+)_(title|text)$/', $key, $m)) {
            return sprintf(__('ميزة %d', 'wparabic-ecomm-cod'), (int) $m[1]) . ' — '
                . ($m[2] === 'title' ? __('العنوان', 'wparabic-ecomm-cod') : __('النص', 'wparabic-ecomm-cod'));
        }
        return $key;
    }

    /**
     * Bloc « الترجمات » ajoute sous le formulaire de chaque section
     * (hook in_widget_form) : un champ par langue proposee pour chaque champ
     * traduisible. Vide = le texte de base s'affiche.
     *
     * @param array $instance
     */
    public function translations_form($instance) {
        if (!function_exists('wparabic_languages')) {
            return;
        }

        $instance = wp_parse_args((array) $instance, $this->defaults());
        $keys     = array_values(array_filter(array_keys($instance), array(__CLASS__, 'is_translatable_key')));
        if (!$keys) {
            return;
        }

        echo '<details class="wparabic-widget-translations" style="margin:0 0 12px"><summary style="cursor:pointer;font-weight:600">'
            . esc_html__('الترجمات', 'wparabic-ecomm-cod') . '</summary>'
            . '<p class="description">' . esc_html__('اتركه فارغًا لاستعمال النص الأساسي أعلاه.', 'wparabic-ecomm-cod') . '</p>';

        foreach (wparabic_languages() as $code => $lang) {
            echo '<fieldset style="margin:10px 0;padding:8px 10px;border:1px solid #dcdcde"><legend>' . esc_html($lang['label']) . '</legend>';
            foreach ($keys as $key) {
                $field = $key . '__' . $code;
                printf(
                    '<p style="margin:6px 0"><label for="%1$s" style="font-size:12px;color:#646970">%2$s</label>'
                    . '<input class="widefat" type="text" id="%1$s" name="%3$s" value="%4$s" placeholder="%7$s" dir="%5$s" lang="%6$s"></p>',
                    esc_attr($this->get_field_id($field)),
                    esc_html(self::translatable_label($key)),
                    esc_attr($this->get_field_name($field)),
                    esc_attr(isset($instance[$field]) ? $instance[$field] : ''),
                    $lang['rtl'] ? 'rtl' : 'ltr',
                    esc_attr(str_replace('_', '-', $lang['locale'])),
                    esc_attr(isset($instance[$key]) ? $instance[$key] : '')
                );
            }
            echo '</fieldset>';
        }

        echo '</details>';
    }

    /**
     * Enregistre les traductions envoyees (filtre widget_update_callback),
     * sans toucher aux update() de chaque section.
     *
     * @param array $instance Instance nettoyee par update().
     * @param array $new      Donnees envoyees.
     * @return array
     */
    public function save_translations($instance, $new) {
        if (!function_exists('wparabic_languages')) {
            return $instance;
        }

        foreach (array_keys($instance) as $key) {
            if (!self::is_translatable_key($key)) {
                continue;
            }
            foreach (array_keys(wparabic_languages()) as $code) {
                $field = $key . '__' . $code;
                $instance[$field] = isset($new[$field]) ? sanitize_text_field(wp_unslash($new[$field])) : '';
            }
        }

        return $instance;
    }

    /* --- Aides de formulaire --------------------------------------------- */

    /**
     * Case a cocher. Rendue en tete de chaque form() pour l'interrupteur de
     * section.
     */
    protected function field_checkbox($instance, $key, $label) {
        printf(
            '<p><label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s> %4$s</label></p>',
            esc_attr($this->get_field_id($key)),
            esc_attr($this->get_field_name($key)),
            checked(!empty($instance[$key]), true, false),
            esc_html($label)
        );
    }

    /** Libelle de l'interrupteur, partage par les quatre sections. */
    protected function field_enabled($instance) {
        $this->field_checkbox($instance, 'enabled', __('إظهار هذا القسم في الصفحة الرئيسية', 'wparabic-ecomm-cod'));
    }

    /**
     * Valeur de l'interrupteur au moment de l'enregistrement.
     *
     * Une case decochee n'est PAS envoyee en POST : il faut donc ecrire 0
     * explicitement, sans quoi la section resterait visible.
     */
    protected function save_enabled($new) {
        return !empty($new['enabled']) ? 1 : 0;
    }

    /**
     * Ratios d'images proposes aux sections de la page d'accueil.
     * Cles au format CSS (utilisees telles quelles dans aspect-ratio),
     * libelles au format humain.
     *
     * @return array<string,string>
     */
    protected static function ratio_choices() {
        return array(
            '1/1'  => '1:1',
            '4/3'  => '4:3',
            '9/16' => '9:16',
            '7/10' => '7:10',
            '16/6' => '16:6',
        );
    }

    /** Ratio valide ('1/1' par defaut). */
    protected function sanitize_ratio($value) {
        return array_key_exists((string) $value, self::ratio_choices()) ? (string) $value : '1/1';
    }

    protected function field_text($instance, $key, $label, $type = 'text') {
        $value = isset($instance[$key]) ? $instance[$key] : '';
        printf(
            '<p><label for="%1$s">%2$s</label><input class="widefat" type="%5$s" id="%1$s" name="%3$s" value="%4$s"></p>',
            esc_attr($this->get_field_id($key)),
            esc_html($label),
            esc_attr($this->get_field_name($key)),
            esc_attr($value),
            esc_attr($type)
        );
    }

    protected function field_select($instance, $key, $label, $choices) {
        $value = isset($instance[$key]) ? $instance[$key] : '';

        printf(
            '<p><label for="%1$s">%2$s</label><select class="widefat" id="%1$s" name="%3$s">',
            esc_attr($this->get_field_id($key)),
            esc_html($label),
            esc_attr($this->get_field_name($key))
        );

        foreach ($choices as $k => $l) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($k),
                selected((string) $value, (string) $k, false),
                esc_html($l)
            );
        }

        echo '</select></p>';
    }

    /**
     * Champ image : input cache + apercu + deux boutons.
     * Le comportement est dans assets/js/admin-widgets.js (delegue).
     */
    protected function field_media($instance, $key, $label) {
        $id      = isset($instance[$key]) ? absint($instance[$key]) : 0;
        $preview = $id ? wp_get_attachment_image_url($id, 'medium') : '';
        ?>
        <p class="wparabic-media-field">
            <label><?php echo esc_html($label); ?></label><br>
            <input type="hidden" class="wparabic-media-id"
                   id="<?php echo esc_attr($this->get_field_id($key)); ?>"
                   name="<?php echo esc_attr($this->get_field_name($key)); ?>"
                   value="<?php echo esc_attr($id); ?>">
            <img class="wparabic-media-preview" alt=""
                 src="<?php echo esc_url($preview); ?>"
                 style="max-width:100%;height:auto;margin:6px 0;<?php echo $preview ? '' : 'display:none'; ?>">
            <button type="button" class="button wparabic-media-choose"><?php esc_html_e('اختر صورة', 'wparabic-ecomm-cod'); ?></button>
            <button type="button" class="button-link-delete wparabic-media-remove"
                    style="<?php echo $preview ? '' : 'display:none'; ?>"><?php esc_html_e('إزالة', 'wparabic-ecomm-cod'); ?></button>
        </p>
        <?php
    }

    /** Liste des categories produit pour les <select>, « auto » en tete. */
    protected function category_choices() {
        $choices = array(0 => __('— تلقائي —', 'wparabic-ecomm-cod'));

        if (!taxonomy_exists('product_cat')) {
            return $choices;
        }

        $terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));

        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $choices[$term->term_id] = $term->name;
            }
        }

        return $choices;
    }
}

/* -------------------------------------------------------------------------
 * 1. Grille de produits
 * ------------------------------------------------------------------------- */

class WPArabic_Home_Widget_Products extends WPArabic_Home_Widget {

    public function __construct() {
        parent::__construct('at-home-latest-products', wparabic_widget_name(__('قسم المنتجات', 'wparabic-ecomm-cod')), array(
            'description' => __('شبكة منتجات مع عنوان ووصف.', 'wparabic-ecomm-cod'),
        ));
    }

    public function defaults() {
        return array(
            'enabled'  => 1,
            // get_theme_mod : pont de compatibilite avec l'ancien reglage du
            // personnalisateur, utilise par le rendu de repli.
            'title'    => get_theme_mod('home_products_title', __('منتجاتنا أصلية و ذات جودة عالية', 'wparabic-ecomm-cod')),
            'subtitle' => get_theme_mod('home_products_subtitle', __('تصفح قائمة المنتجات الأكثر رواجا', 'wparabic-ecomm-cod')),
            'number'   => 8,
            'columns'  => 5,
            'orderby'  => 'date',
            'cat'      => 0,
            'ratio'    => '1/1',
            // Pleine largeur : la grille sort du conteneur, bord a bord.
            'full_width' => 0,
            // Defilement horizontal : une seule rangee qu'on fait glisser.
            'scroll'   => 0,
            // Defilement automatique du carrousel (secondes entre deux pas).
            'autoplay' => 0,
            'autoplay_sec' => 4,
        );
    }

    public function widget($args, $instance) {
        if ($this->is_disabled($instance)) {
            return; // AVANT before_widget : pas de <section> vide.
        }

        if (!function_exists('wc_get_template_part')) {
            return;
        }

        $i       = $this->prepare($instance);
        $columns = in_array((int) $i['columns'], array(4, 5), true) ? (int) $i['columns'] : 5;

        $query_args = array(
            'post_type'           => 'product',
            'post_status'         => 'publish',
            'posts_per_page'      => max(1, min(24, (int) $i['number'])),
            'ignore_sticky_posts' => true,
        );

        if ($i['orderby'] === 'popularity') {
            $query_args['meta_key'] = 'total_sales';
            $query_args['orderby']  = 'meta_value_num';
        } elseif ($i['orderby'] === 'rand') {
            $query_args['orderby'] = 'rand';
        } else {
            $query_args['orderby'] = 'date';
        }

        if (!empty($i['cat'])) {
            $query_args['tax_query'] = array(array(
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => (int) $i['cat'],
            ));
        }

        $products = new WP_Query($query_args);

        // Rien a montrer : on sort AVANT before_widget, sinon une <section> vide
        // fausserait .widget-section:last-child{margin-bottom:0}.
        if (!$products->have_posts()) {
            wp_reset_postdata();
            return;
        }

        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput

        wparabic_home_section_heading($i['title'], $i['subtitle']);
        $ratio     = $this->sanitize_ratio($i['ratio'] ?? '1/1');
        $extra     = (empty($i['full_width']) ? '' : ' wparabic-row-full') . (empty($i['scroll']) ? '' : ' wparabic-scroll');
        $carattr   = '';
        if (!empty($i['scroll'])) {
            $carattr = ' data-wparabic-carousel';
            if (!empty($i['autoplay'])) {
                $carattr .= ' data-autoplay="1" data-autoplay-ms="' . (max(2, min(20, absint($i['autoplay_sec'] ?? 4))) * 1000) . '"';
            }
        }
        ?>
        <div class="woocommerce columns-<?php echo esc_attr($columns); ?> <?php echo esc_attr($extra); ?>"<?php echo $carattr; // phpcs:ignore WordPress.Security.EscapeOutput ?> style="--wparabic-thumb-ratio: <?php echo esc_attr($ratio); ?>">
            <?php if (!empty($i['scroll'])) : ?>
                <button type="button" class="wparabic-cats-nav wparabic-cats-nav--prev" aria-label="<?php esc_attr_e('السابق', 'wparabic-ecomm-cod'); ?>"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
            <?php endif; ?>
            <div class="row row-items columns-<?php echo esc_attr($columns); ?> <?php echo empty($i['scroll']) ? '' : 'wparabic-cats-track'; ?>">
                <?php
                // wc_setup_loop + wc_get_template_part pour que
                // content-product.php soit bien utilise : le shortcode
                // [products] emet ul.products > li et casserait la grille.
                if (function_exists('wc_setup_loop')) {
                    wc_setup_loop(array('columns' => $columns));
                }

                while ($products->have_posts()) {
                    $products->the_post();
                    wc_get_template_part('content', 'product');
                }

                if (function_exists('wc_reset_loop')) {
                    wc_reset_loop();
                }
                wp_reset_postdata();
                ?>
            </div>
            <?php if (!empty($i['scroll'])) : ?>
                <button type="button" class="wparabic-cats-nav wparabic-cats-nav--next" aria-label="<?php esc_attr_e('التالي', 'wparabic-ecomm-cod'); ?>"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
            <?php endif; ?>
        </div>
        <?php

        echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    public function form($instance) {
        $i = wp_parse_args((array) $instance, $this->defaults());

        $this->field_enabled($i);

        $this->field_text($i, 'title', __('العنوان', 'wparabic-ecomm-cod'));
        $this->field_text($i, 'subtitle', __('الوصف', 'wparabic-ecomm-cod'));
        $this->field_text($i, 'number', __('عدد المنتجات', 'wparabic-ecomm-cod'), 'number');
        $this->field_select($i, 'columns', __('عدد الأعمدة', 'wparabic-ecomm-cod'), array(4 => '4', 5 => '5'));
        $this->field_select($i, 'ratio', __('نسبة الصور', 'wparabic-ecomm-cod'), self::ratio_choices());
        $this->field_checkbox($i, 'full_width', __('عرض كامل (من الحافة إلى الحافة)', 'wparabic-ecomm-cod'));
        $this->field_checkbox($i, 'scroll', __('عرض ككاروسيل (صف واحد بأسهم للتنقل)', 'wparabic-ecomm-cod'));
        echo '<div class="wparabic-scroll-fields"' . (empty($i['scroll']) ? ' style="display:none"' : '') . '>';
        $this->field_checkbox($i, 'autoplay', __('تحريك تلقائي للكاروسيل', 'wparabic-ecomm-cod'));
        $this->field_text($i, 'autoplay_sec', __('المدة بين حركتين (بالثواني، 2-20)', 'wparabic-ecomm-cod'), 'number');
        echo '<p class="description">' . esc_html__('يتوقف التحريك عند مرور المؤشر أو اللمس، ويستأنف بعدها. عند نهاية الصف يعود إلى البداية.', 'wparabic-ecomm-cod') . '</p>';
        echo '</div>';
        $this->field_select($i, 'orderby', __('الترتيب', 'wparabic-ecomm-cod'), array(
            'date'       => __('الأحدث', 'wparabic-ecomm-cod'),
            'popularity' => __('الأكثر مبيعًا', 'wparabic-ecomm-cod'),
            'rand'       => __('عشوائي', 'wparabic-ecomm-cod'),
        ));
        $this->field_select($i, 'cat', __('التصنيف', 'wparabic-ecomm-cod'), $this->category_choices());
    }

    public function update($new, $old) {
        return array(
            'enabled'  => $this->save_enabled($new),
            'title'    => sanitize_text_field($new['title'] ?? ''),
            'subtitle' => sanitize_text_field($new['subtitle'] ?? ''),
            'number'   => max(1, min(24, absint($new['number'] ?? 8))),
            'columns'  => in_array((int) ($new['columns'] ?? 5), array(4, 5), true) ? (int) $new['columns'] : 5,
            'orderby'  => in_array($new['orderby'] ?? 'date', array('date', 'popularity', 'rand'), true) ? $new['orderby'] : 'date',
            'cat'      => absint($new['cat'] ?? 0),
            'ratio'    => $this->sanitize_ratio($new['ratio'] ?? '1/1'),
            'full_width' => !empty($new['full_width']) ? 1 : 0,
            'scroll'   => !empty($new['scroll']) ? 1 : 0,
            'autoplay' => !empty($new['autoplay']) ? 1 : 0,
            'autoplay_sec' => max(2, min(20, absint($new['autoplay_sec'] ?? 4))),
        );
    }
}

/* -------------------------------------------------------------------------
 * 1 bis. Sections produits par categorie
 * ------------------------------------------------------------------------- */

class WPArabic_Home_Widget_Products_By_Cat extends WPArabic_Home_Widget {

    /** Nombre maximum de groupes (une categorie + ses produits). */
    const MAX_GROUPS = 6;

    public function __construct() {
        parent::__construct('at-home-products-by-cat', wparabic_widget_name(__('أقسام حسب التصنيف', 'wparabic-ecomm-cod')), array(
            'description' => __('لكل تصنيف تختاره : عنوان + شبكة منتجات تختارها بنفسك.', 'wparabic-ecomm-cod'),
        ));
    }

    public function defaults() {
        return array(
            'enabled'  => 1,
            // En-tete global facultatif : vide = aucun titre au-dessus des groupes.
            'title'    => '',
            'subtitle' => '',
            'groups'   => 2,
            'columns'  => 4,
            'ratio'    => '1/1',
            // Pleine largeur : les grilles sortent du conteneur, bord a bord.
            'full_width' => 0,
            // Defilement horizontal : une seule rangee par groupe.
            'scroll'   => 0,
            // Defilement automatique du carrousel (secondes entre deux pas).
            'autoplay' => 0,
            'autoplay_sec' => 4,
        );
    }

    /**
     * Groupes affichables : categorie valide + au moins un produit choisi
     * encore publie. L'ordre suit la numerotation des groupes.
     *
     * @return array<int, array{term:WP_Term, title:string, products:int[]}>
     */
    protected function resolved_groups($i) {
        $groups = array();
        $count  = max(1, min(self::MAX_GROUPS, absint($i['groups'] ?? 2)));

        for ($n = 1; $n <= $count; $n++) {
            $cat_id = absint($i['g' . $n . '_cat'] ?? 0);
            if (!$cat_id) {
                continue;
            }
            $term = get_term($cat_id, 'product_cat');
            if (!$term || is_wp_error($term)) {
                continue;
            }

            $ids = array_values(array_filter(array_map('absint', (array) ($i['g' . $n . '_products'] ?? array()))));
            if (!$ids) {
                continue;
            }

            $title = trim((string) ($i['g' . $n . '_title'] ?? ''));
            $groups[] = array(
                'term'     => $term,
                'title'    => $title !== '' ? $title : $term->name,
                'products' => $ids,
            );
        }

        return $groups;
    }

    public function widget($args, $instance) {
        if ($this->is_disabled($instance)) {
            return; // AVANT before_widget : pas de <section> vide.
        }

        if (!function_exists('wc_get_template_part')) {
            return;
        }

        $i      = $this->prepare($instance);
        $groups = $this->resolved_groups($i);
        if (!$groups) {
            return;
        }

        $columns = in_array((int) $i['columns'], array(2, 3, 4, 5, 6), true) ? (int) $i['columns'] : 4;
        $ratio   = $this->sanitize_ratio($i['ratio'] ?? '1/1');
        $extra   = (empty($i['full_width']) ? '' : ' wparabic-row-full') . (empty($i['scroll']) ? '' : ' wparabic-scroll');
        $carattr = '';
        if (!empty($i['scroll'])) {
            $carattr = ' data-wparabic-carousel';
            if (!empty($i['autoplay'])) {
                $carattr .= ' data-autoplay="1" data-autoplay-ms="' . (max(2, min(20, absint($i['autoplay_sec'] ?? 4))) * 1000) . '"';
            }
        }

        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput

        wparabic_home_section_heading($i['title'], $i['subtitle']);

        foreach ($groups as $group) :
            $products = new WP_Query(array(
                'post_type'           => 'product',
                'post_status'         => 'publish',
                'post__in'            => $group['products'],
                'orderby'             => 'post__in',
                'posts_per_page'      => count($group['products']),
                'ignore_sticky_posts' => true,
            ));
            if (!$products->have_posts()) {
                wp_reset_postdata();
                continue;
            }
            ?>
            <div class="wparabic-pbc-group">
                <?php wparabic_home_section_heading($group['title'], ''); ?>
                <div class="woocommerce columns-<?php echo esc_attr($columns); ?> <?php echo esc_attr($extra); ?>"<?php echo $carattr; // phpcs:ignore WordPress.Security.EscapeOutput ?> style="--wparabic-thumb-ratio: <?php echo esc_attr($ratio); ?>">
                    <?php if (!empty($i['scroll'])) : ?>
                        <button type="button" class="wparabic-cats-nav wparabic-cats-nav--prev" aria-label="<?php esc_attr_e('السابق', 'wparabic-ecomm-cod'); ?>"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
                    <?php endif; ?>
                    <div class="row row-items columns-<?php echo esc_attr($columns); ?> <?php echo empty($i['scroll']) ? '' : 'wparabic-cats-track'; ?>">
                        <?php
                        // Meme rendu que le widget « قسم المنتجات » : cartes
                        // completes (photo, badge, prix) via content-product.php.
                        if (function_exists('wc_setup_loop')) {
                            wc_setup_loop(array('columns' => $columns));
                        }

                        while ($products->have_posts()) {
                            $products->the_post();
                            wc_get_template_part('content', 'product');
                        }

                        if (function_exists('wc_reset_loop')) {
                            wc_reset_loop();
                        }
                        wp_reset_postdata();
                        ?>
                    </div>
                    <?php if (!empty($i['scroll'])) : ?>
                        <button type="button" class="wparabic-cats-nav wparabic-cats-nav--next" aria-label="<?php esc_attr_e('التالي', 'wparabic-ecomm-cod'); ?>"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        endforeach;

        echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    public function form($instance) {
        $i       = wp_parse_args((array) $instance, $this->defaults());
        $choices = $this->category_choices();

        $this->field_enabled($i);

        echo '<p><strong>' . esc_html__('عنوان عام (اختياري)', 'wparabic-ecomm-cod') . '</strong></p>';
        $this->field_text($i, 'title', __('العنوان', 'wparabic-ecomm-cod'));
        $this->field_text($i, 'subtitle', __('الوصف', 'wparabic-ecomm-cod'));
        $this->field_text($i, 'groups', __('عدد الأقسام (1-6)', 'wparabic-ecomm-cod'), 'number');
        echo '<p class="description">' . esc_html__('بعد تغيير عدد الأقسام احفظ النموذج لتحديث القوائم.', 'wparabic-ecomm-cod') . '</p>';
        $this->field_select($i, 'columns', __('عدد الأعمدة', 'wparabic-ecomm-cod'), array(2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6'));
        $this->field_select($i, 'ratio', __('نسبة الصور', 'wparabic-ecomm-cod'), self::ratio_choices());
        $this->field_checkbox($i, 'full_width', __('عرض كامل (من الحافة إلى الحافة)', 'wparabic-ecomm-cod'));
        $this->field_checkbox($i, 'scroll', __('عرض ككاروسيل (صف واحد بأسهم للتنقل)', 'wparabic-ecomm-cod'));
        echo '<div class="wparabic-scroll-fields"' . (empty($i['scroll']) ? ' style="display:none"' : '') . '>';
        $this->field_checkbox($i, 'autoplay', __('تحريك تلقائي للكاروسيل', 'wparabic-ecomm-cod'));
        $this->field_text($i, 'autoplay_sec', __('المدة بين حركتين (بالثواني، 2-20)', 'wparabic-ecomm-cod'), 'number');
        echo '<p class="description">' . esc_html__('يتوقف التحريك عند مرور المؤشر أو اللمس، ويستأنف بعدها. عند نهاية الصف يعود إلى البداية.', 'wparabic-ecomm-cod') . '</p>';
        echo '</div>';

        $count = max(1, min(self::MAX_GROUPS, absint($i['groups'] ?? 2)));
        for ($n = 1; $n <= $count; $n++) {
            echo '<hr><p style="margin:4px 0"><strong>' . esc_html(sprintf(
                /* translators: %d: group number */
                __('القسم %d', 'wparabic-ecomm-cod'),
                $n
            )) . '</strong></p>';
            $this->field_select($i, 'g' . $n . '_cat', __('التصنيف', 'wparabic-ecomm-cod'), $choices);
            $this->field_text($i, 'g' . $n . '_title', __('عنوان مخصص (اختياري)', 'wparabic-ecomm-cod'));
            $this->field_product_checklist($i, $n);
        }
    }

    /** Cases a cocher des produits publiés pour le groupe N. */
    protected function field_product_checklist($i, $n) {
        $posts = get_posts(array(
            'post_type'   => 'product',
            'post_status' => 'publish',
            'numberposts' => 200,
            'orderby'     => 'title',
            'order'       => 'ASC',
            'fields'      => 'ids',
        ));

        $selected = array_map('absint', (array) ($i['g' . $n . '_products'] ?? array()));

        echo '<p style="margin-bottom:4px"><span class="description">' . esc_html__('المنتجات المعروضة', 'wparabic-ecomm-cod') . '</span></p>';
        echo '<div style="max-height:180px;overflow:auto;border:1px solid #dcdcde;padding:6px 8px;margin-bottom:12px">';
        foreach ($posts as $id) {
            $terms = get_the_terms($id, 'product_cat');
            $cat = (is_array($terms) && $terms) ? $terms[0]->name : null;
            printf(
                '<label style="display:block;margin:3px 0"><input type="checkbox" name="%1$s[]" value="%2$d"%3$s> %4$s%5$s</label>',
                esc_attr($this->get_field_name('g' . $n . '_products')),
                (int) $id,
                checked(in_array((int) $id, $selected, true), true, false),
                esc_html(get_the_title($id)),
                $cat ? ' <span style="color:#8c8f94">(' . esc_html($cat) . ')</span>' : ''
            );
        }
        echo '</div>';
    }

    public function update($new, $old) {
        $clean = array(
            'enabled'  => $this->save_enabled($new),
            'title'    => sanitize_text_field($new['title'] ?? ''),
            'subtitle' => sanitize_text_field($new['subtitle'] ?? ''),
            'groups'   => max(1, min(self::MAX_GROUPS, absint($new['groups'] ?? 2))),
            'columns'  => in_array((int) ($new['columns'] ?? 4), array(2, 3, 4, 5, 6), true) ? (int) $new['columns'] : 4,
            'ratio'    => $this->sanitize_ratio($new['ratio'] ?? '1/1'),
            'full_width' => !empty($new['full_width']) ? 1 : 0,
            'scroll'   => !empty($new['scroll']) ? 1 : 0,
            'autoplay' => !empty($new['autoplay']) ? 1 : 0,
            'autoplay_sec' => max(2, min(20, absint($new['autoplay_sec'] ?? 4))),
        );

        for ($n = 1; $n <= $clean['groups']; $n++) {
            $clean['g' . $n . '_cat']      = absint($new['g' . $n . '_cat'] ?? 0);
            $clean['g' . $n . '_title']    = sanitize_text_field($new['g' . $n . '_title'] ?? '');
            $clean['g' . $n . '_products'] = array_values(array_filter(array_map('absint', (array) ($new['g' . $n . '_products'] ?? array()))));
        }

        return $clean;
    }
}

/* -------------------------------------------------------------------------
 * 2. Arguments de reassurance
 * ------------------------------------------------------------------------- */

class WPArabic_Home_Widget_Features extends WPArabic_Home_Widget {

    const ITEMS = 4;

    public function __construct() {
        parent::__construct('at-home-post-type-items', wparabic_widget_name(__('قسم المميزات', 'wparabic-ecomm-cod')), array(
            'description' => __('أربع مزايا بأيقونة وعنوان ونص.', 'wparabic-ecomm-cod'),
        ));
    }

    public function defaults() {
        $d = array(
            'enabled'  => 1,
            'title'    => get_theme_mod('home_features_title', __('لماذا تختارنا ؟', 'wparabic-ecomm-cod')),
            'subtitle' => get_theme_mod('home_features_subtitle', __('جودة مضمونة وخدمة قريبة منك', 'wparabic-ecomm-cod')),
        );

        $seed = array(
            array('fa-truck-fast', __('توصيل سريع', 'wparabic-ecomm-cod'), __('إلى جميع ولايات الجمهورية', 'wparabic-ecomm-cod')),
            array('fa-hand-holding-dollar', __('الدفع عند الاستلام', 'wparabic-ecomm-cod'), __('تدفع فقط عند استلام طلبك', 'wparabic-ecomm-cod')),
            array('fa-award', __('جودة مضمونة', 'wparabic-ecomm-cod'), __('منتجات مختارة بعناية', 'wparabic-ecomm-cod')),
            array('fa-rotate-left', __('استبدال مجاني', 'wparabic-ecomm-cod'), __('إن لم يناسبك المنتج', 'wparabic-ecomm-cod')),
        );

        foreach ($seed as $n => $item) {
            $d['item' . ($n + 1) . '_icon']  = $item[0];
            $d['item' . ($n + 1) . '_title'] = $item[1];
            $d['item' . ($n + 1) . '_text']  = $item[2];
        }

        return $d;
    }

    public function widget($args, $instance) {
        if ($this->is_disabled($instance)) {
            return; // AVANT before_widget : pas de <section> vide.
        }

        $i     = $this->prepare($instance);
        $items = array();

        for ($n = 1; $n <= self::ITEMS; $n++) {
            $title = trim((string) ($i['item' . $n . '_title'] ?? ''));
            $icon  = trim((string) ($i['item' . $n . '_icon'] ?? ''));
            $text  = trim((string) ($i['item' . $n . '_text'] ?? ''));

            if ($title !== '' || $text !== '') {
                $items[] = array($icon, $title, $text);
            }
        }

        if (!$items) {
            return;
        }

        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput

        wparabic_home_section_heading($i['title'], $i['subtitle']);
        ?>
        <div class="row row-items columns-4">
            <?php foreach ($items as $item) : ?>
                <div class="col-xl-3 col-lg-4 col-sm-6 col-6">
                    <div class="item">
                        <?php if ($item[0] !== '') : ?>
                            <div class="item-thumb"><i class="fas <?php echo esc_attr($item[0]); ?>"></i></div>
                        <?php endif; ?>
                        <div class="item-content">
                            <p class="item-title"><?php echo esc_html($item[1]); ?></p>
                            <?php if ($item[2] !== '') : ?>
                                <p class="item-excerpt"><?php echo esc_html($item[2]); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php

        echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    public function form($instance) {
        $i = wp_parse_args((array) $instance, $this->defaults());

        $this->field_enabled($i);

        $this->field_text($i, 'title', __('العنوان', 'wparabic-ecomm-cod'));
        $this->field_text($i, 'subtitle', __('الوصف', 'wparabic-ecomm-cod'));

        echo '<p class="description">' . esc_html__('الأيقونات من Font Awesome، مثال : fa-truck-fast', 'wparabic-ecomm-cod') . '</p>';

        for ($n = 1; $n <= self::ITEMS; $n++) {
            printf('<fieldset style="border:1px solid #dcdcde;padding:8px;margin:0 0 10px"><legend>%s %d</legend>',
                esc_html__('ميزة', 'wparabic-ecomm-cod'), $n);
            $this->field_text($i, 'item' . $n . '_icon', __('الأيقونة', 'wparabic-ecomm-cod'));
            $this->field_text($i, 'item' . $n . '_title', __('العنوان', 'wparabic-ecomm-cod'));
            $this->field_text($i, 'item' . $n . '_text', __('النص', 'wparabic-ecomm-cod'));
            echo '</fieldset>';
        }
    }

    public function update($new, $old) {
        $out = array(
            'enabled'  => $this->save_enabled($new),
            'title'    => sanitize_text_field($new['title'] ?? ''),
            'subtitle' => sanitize_text_field($new['subtitle'] ?? ''),
        );

        for ($n = 1; $n <= self::ITEMS; $n++) {
            // Classe Font Awesome : on ne laisse passer que ce qui peut en etre une.
            $out['item' . $n . '_icon']  = preg_replace('/[^a-z0-9 _-]/i', '', (string) ($new['item' . $n . '_icon'] ?? ''));
            $out['item' . $n . '_title'] = sanitize_text_field($new['item' . $n . '_title'] ?? '');
            $out['item' . $n . '_text']  = sanitize_text_field($new['item' . $n . '_text'] ?? '');
        }

        return $out;
    }
}

/* -------------------------------------------------------------------------
 * 3. Tuiles de categories
 * ------------------------------------------------------------------------- */

class WPArabic_Home_Widget_Categories extends WPArabic_Home_Widget {

    /** Nombre maximum de categories pour la grille, les cercles et le carrousel. */
    const MAX_ITEMS = 24;

    public function __construct() {
        parent::__construct('at-home-woocommerce-categories', wparabic_widget_name(__('قسم التصنيفات', 'wparabic-ecomm-cod')), array(
            'description' => __('تصنيفات المتجر بعدة نماذج عرض : فسيفساء، بلاطات متساوية، شبكة، دوائر أو شريط متحرك.', 'wparabic-ecomm-cod'),
        ));
    }

    /**
     * Modeles d'affichage proposes a l'admin.
     *
     * @return array<string,string>
     */
    public static function layouts() {
        return array(
            'mosaic'   => __('فسيفساء (كبيرة + متوسطتان + عريضة)', 'wparabic-ecomm-cod'),
            'equal'    => __('بلاطات متساوية', 'wparabic-ecomm-cod'),
            'grid'     => __('شبكة بطاقات', 'wparabic-ecomm-cod'),
            'circles'  => __('دوائر', 'wparabic-ecomm-cod'),
            'carousel' => __('شريط متحرك', 'wparabic-ecomm-cod'),
        );
    }

    public function defaults() {
        return array(
            'enabled'    => 1,
            'title'      => get_theme_mod('home_categories_title', __('أقسام المتجر', 'wparabic-ecomm-cod')),
            'subtitle'   => get_theme_mod('home_categories_subtitle', __('اختر الموديل الذي يناسبك', 'wparabic-ecomm-cod')),
            // Mosaique (modele d'origine).
            'layout'     => 'mosaic',
            'cat1'       => 0,
            'cat2'       => 0,
            'cat3'       => 0,
            'cat4'       => 0,
            // Grille, cercles, carrousel.
            'cats'       => array(),
            'limit'      => 8,
            'columns'    => 4,
            'show_count' => 1,
            // Ratio des vignettes (blocs egaux, grille, carrousel).
            'ratio'      => '1/1',
            // Pleine largeur (blocs egaux, grille, carrousel).
            'full_width' => 0,
        );
    }

    /**
     * Image d'une categorie : sa vignette, sinon la photo d'un de ses
     * produits, sinon l'image par defaut de WooCommerce.
     */
    protected function category_image($term, $size) {
        $thumb_id = (int) get_term_meta($term->term_id, 'thumbnail_id', true);

        if (!$thumb_id && function_exists('wc_get_products')) {
            $ids = wc_get_products(array(
                'status'   => 'publish',
                'limit'    => 1,
                'category' => array($term->slug),
                'return'   => 'ids',
                'orderby'  => 'date',
                'order'    => 'DESC',
            ));
            $thumb_id = $ids ? (int) get_post_thumbnail_id($ids[0]) : 0;
        }

        $image = $thumb_id ? wp_get_attachment_image_url($thumb_id, $size) : '';

        if (!$image && function_exists('wc_placeholder_img_src')) {
            $image = wc_placeholder_img_src($size);
        }

        return $image;
    }

    /**
     * Les quatre categories de la mosaique : celles choisies, completees par
     * les plus recentes pour les emplacements laisses sur « auto ».
     *
     * @return array<int, WP_Term|null>
     */
    protected function resolve_categories($i) {
        if (!taxonomy_exists('product_cat')) {
            return array(null, null, null, null);
        }

        $chosen = array();
        $used   = array();

        for ($n = 1; $n <= 4; $n++) {
            $id = absint($i['cat' . $n] ?? 0);
            if ($id) {
                $term = get_term($id, 'product_cat');
                if ($term && !is_wp_error($term)) {
                    $chosen[$n - 1] = $term;
                    $used[]         = $term->term_id;
                    continue;
                }
            }
            $chosen[$n - 1] = null;
        }

        $auto = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'exclude'    => array_merge(array(get_option('default_product_cat')), $used),
            'number'     => 4,
            'orderby'    => 'term_order',
        ));

        if (is_wp_error($auto)) {
            $auto = array();
        }

        foreach ($chosen as $slot => $term) {
            if ($term === null && $auto) {
                $chosen[$slot] = array_shift($auto);
            }
        }

        return $chosen;
    }

    /**
     * Categories de la grille, des cercles et du carrousel : celles cochees
     * (dans l'ordre de la liste), sinon toutes celles qui ont des produits.
     *
     * @return WP_Term[]
     */
    protected function resolve_list($i) {
        if (!taxonomy_exists('product_cat')) {
            return array();
        }

        $limit    = max(1, min(self::MAX_ITEMS, absint($i['limit'])));
        $selected = array_filter(array_map('absint', (array) $i['cats']));

        $args = array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => empty($selected),
            'exclude'    => array(get_option('default_product_cat')),
            'number'     => $limit,
            'orderby'    => 'term_order',
        );

        if ($selected) {
            $args['include'] = $selected;
            $args['orderby'] = 'include';
        }

        $terms = get_terms($args);

        return is_wp_error($terms) ? array() : $terms;
    }

    protected function tile($term, $size) {
        if (!$term) {
            return;
        }

        $image = $this->category_image($term, 'large');
        $style = $image ? ' style="background-image: url(' . esc_url($image) . ')"' : '';
        ?>
        <div class="block-item <?php echo esc_attr($size); ?>"<?php echo $style; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
            <div class="block-item-content-wrap">
                <div class="block-item-content">
                    <p class="block-item-title"><?php echo esc_html($term->name); ?></p>
                    <a href="<?php echo esc_url(get_term_link($term)); ?>" class="btn btn-sm btn-block-item"><?php esc_html_e('تسوق الان', 'wparabic-ecomm-cod'); ?></a>
                </div>
            </div>
        </div>
        <?php
    }

    /** Libelle « 12 منتج » sous le nom d'une categorie. */
    protected function count_label($term) {
        /* translators: %s: number of products */
        return sprintf(__('المنتجات : %s', 'wparabic-ecomm-cod'), number_format_i18n((int) $term->count));
    }

    protected function render_mosaic($cats) {
        ?>
        <div class="block-layout">
            <div class="row">
                <?php if ($cats[0]) : ?>
                    <div class="col-lg-8 col-12"><?php $this->tile($cats[0], 'block-item-lg'); ?></div>
                <?php endif; ?>

                <?php if ($cats[1] || $cats[2]) : ?>
                    <div class="col-lg-4 col-12">
                        <div class="row">
                            <?php if ($cats[1]) : ?>
                                <div class="col-lg-12 col-md-6 col-12"><?php $this->tile($cats[1], 'block-item-md'); ?></div>
                            <?php endif; ?>
                            <?php if ($cats[2]) : ?>
                                <div class="col-lg-12 col-md-6 col-12"><?php $this->tile($cats[2], 'block-item-md'); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($cats[3]) : ?>
                    <div class="col-12"><?php $this->tile($cats[3], 'block-item-long'); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Grille, cercles et carrousel partagent le meme balisage : seule la
     * classe change (assets/css/overrides.css, « Section categories »).
     */
    protected function render_list($terms, $i) {
        $layout  = $i['layout'];
        $columns = in_array((int) $i['columns'], array(2, 3, 4, 5, 6), true) ? (int) $i['columns'] : 4;
        $classes = 'wparabic-cats wparabic-cats--' . $layout
            . (in_array($layout, array('grid', 'equal'), true) ? ' wparabic-cats--cols-' . $columns : '')
            . (empty($i['full_width']) ? '' : ' wparabic-row-full');
        $ratio = $this->sanitize_ratio($i['ratio'] ?? '1/1');
        $size = $layout === 'circles' ? 'woocommerce_thumbnail' : ($layout === 'equal' ? 'large' : 'medium_large');
        ?>
        <div class="<?php echo esc_attr($classes); ?>" style="--wparabic-ratio: <?php echo esc_attr($ratio); ?>"<?php echo $layout === 'carousel' ? ' data-wparabic-carousel' : ''; ?>>
            <?php if ($layout === 'carousel') : ?>
                <button type="button" class="wparabic-cats-nav wparabic-cats-nav--prev" aria-label="<?php esc_attr_e('السابق', 'wparabic-ecomm-cod'); ?>"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
            <?php endif; ?>
            <ul class="wparabic-cats-track">
                <?php foreach ($terms as $term) : ?>
                    <li class="wparabic-cat">
                        <a class="wparabic-cat-link" href="<?php echo esc_url(get_term_link($term)); ?>">
                            <span class="wparabic-cat-media">
                                <img src="<?php echo esc_url($this->category_image($term, $size)); ?>" alt="" loading="lazy" decoding="async">
                            </span>
                            <?php if ($layout === 'equal') : ?>
                                <span class="wparabic-cat-caption">
                                    <span class="wparabic-cat-name"><?php echo esc_html($term->name); ?></span>
                                    <?php if (!empty($i['show_count'])) : ?>
                                        <span class="wparabic-cat-count"><?php echo esc_html($this->count_label($term)); ?></span>
                                    <?php endif; ?>
                                </span>
                            <?php else : ?>
                                <span class="wparabic-cat-name"><?php echo esc_html($term->name); ?></span>
                                <?php if (!empty($i['show_count']) && $layout !== 'circles') : ?>
                                    <span class="wparabic-cat-count"><?php echo esc_html($this->count_label($term)); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($layout === 'carousel') : ?>
                <button type="button" class="wparabic-cats-nav wparabic-cats-nav--next" aria-label="<?php esc_attr_e('التالي', 'wparabic-ecomm-cod'); ?>"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
            <?php endif; ?>
        </div>
        <?php
    }

    public function widget($args, $instance) {
        if ($this->is_disabled($instance)) {
            return; // AVANT before_widget : pas de <section> vide.
        }

        $i      = $this->prepare($instance);
        $layout = array_key_exists($i['layout'], self::layouts()) ? $i['layout'] : 'mosaic';
        $i['layout'] = $layout;

        if ($layout === 'mosaic') {
            $items = $this->resolve_categories($i);
            if (!array_filter($items)) {
                return;
            }
        } else {
            $items = $this->resolve_list($i);
            if (!$items) {
                return;
            }
        }

        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput

        wparabic_home_section_heading($i['title'], $i['subtitle']);

        if ($layout === 'mosaic') {
            $this->render_mosaic($items);
        } else {
            $this->render_list($items, $i);
        }

        echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    public function form($instance) {
        $i       = wp_parse_args((array) $instance, $this->defaults());
        $choices = $this->category_choices();

        $this->field_enabled($i);
        $this->field_text($i, 'title', __('العنوان', 'wparabic-ecomm-cod'));
        $this->field_text($i, 'subtitle', __('الوصف', 'wparabic-ecomm-cod'));

        // Le select porte la classe qui pilote l'affichage des reglages propres
        // a chaque modele (assets/js/admin-widgets.js).
        echo '<div class="wparabic-layout-switch">';
        $this->field_select($i, 'layout', __('نموذج العرض', 'wparabic-ecomm-cod'), self::layouts());
        echo '</div>';

        // --- Mosaique.
        echo '<div class="wparabic-layout-fields" data-layouts="mosaic">';
        $labels = array(
            1 => __('البلاطة الكبيرة', 'wparabic-ecomm-cod'),
            2 => __('البلاطة المتوسطة العلوية', 'wparabic-ecomm-cod'),
            3 => __('البلاطة المتوسطة السفلية', 'wparabic-ecomm-cod'),
            4 => __('البلاطة العريضة', 'wparabic-ecomm-cod'),
        );
        foreach ($labels as $n => $label) {
            $this->field_select($i, 'cat' . $n, $label, $choices);
        }
        echo '</div>';

        // --- Blocs egaux, grille, cercles, carrousel.
        echo '<div class="wparabic-layout-fields" data-layouts="equal,grid,carousel">';
        $this->field_select($i, 'ratio', __('نسبة الصور', 'wparabic-ecomm-cod'), self::ratio_choices());
        $this->field_checkbox($i, 'full_width', __('عرض كامل (من الحافة إلى الحافة)', 'wparabic-ecomm-cod'));
        echo '</div>';
        echo '<div class="wparabic-layout-fields" data-layouts="grid,equal,circles,carousel">';
        $this->field_category_checklist($i);
        $this->field_text($i, 'limit', __('العدد الأقصى للتصنيفات', 'wparabic-ecomm-cod'), 'number');
        echo '<div class="wparabic-layout-fields" data-layouts="grid,equal">';
        $this->field_select($i, 'columns', __('عدد الأعمدة في الصف', 'wparabic-ecomm-cod'), array(2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6'));
        echo '</div>';
        echo '<div class="wparabic-layout-fields" data-layouts="grid,equal,carousel">';
        $this->field_checkbox($i, 'show_count', __('إظهار عدد المنتجات', 'wparabic-ecomm-cod'));
        echo '</div>';
        echo '</div>';
    }

    /** Liste de cases a cocher des categories (vide = automatique). */
    protected function field_category_checklist($i) {
        $selected = array_map('absint', (array) $i['cats']);
        $terms    = taxonomy_exists('product_cat') ? get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'exclude'    => array(get_option('default_product_cat')),
            'orderby'    => 'term_order',
        )) : array();

        echo '<p style="margin-bottom:4px"><strong>' . esc_html__('التصنيفات المعروضة', 'wparabic-ecomm-cod') . '</strong><br><span class="description">'
            . esc_html__('بدون اختيار : كل التصنيفات التي تحتوي على منتجات.', 'wparabic-ecomm-cod') . '</span></p>';
        echo '<div style="max-height:180px;overflow:auto;border:1px solid #dcdcde;padding:6px 8px;margin-bottom:12px">';
        foreach (is_wp_error($terms) ? array() : $terms as $term) {
            printf(
                '<label style="display:block;margin:3px 0"><input type="checkbox" name="%1$s[]" value="%2$d"%3$s> %4$s <span style="color:#8c8f94">(%5$d)</span></label>',
                esc_attr($this->get_field_name('cats')),
                (int) $term->term_id,
                checked(in_array((int) $term->term_id, $selected, true), true, false),
                esc_html($term->name),
                (int) $term->count
            );
        }
        echo '</div>';
    }

    public function update($new, $old) {
        return array(
            'enabled'    => $this->save_enabled($new),
            'title'      => sanitize_text_field($new['title'] ?? ''),
            'subtitle'   => sanitize_text_field($new['subtitle'] ?? ''),
            'layout'     => array_key_exists($layout = $new['layout'] ?? 'mosaic', self::layouts()) ? $layout : 'mosaic',
            'cat1'       => absint($new['cat1'] ?? 0),
            'cat2'       => absint($new['cat2'] ?? 0),
            'cat3'       => absint($new['cat3'] ?? 0),
            'cat4'       => absint($new['cat4'] ?? 0),
            'cats'       => array_values(array_filter(array_map('absint', (array) ($new['cats'] ?? array())))),
            'limit'      => max(1, min(self::MAX_ITEMS, absint($new['limit'] ?? 8))),
            'columns'    => in_array((int) ($new['columns'] ?? 4), array(2, 3, 4, 5, 6), true) ? (int) $new['columns'] : 4,
            'ratio'      => $this->sanitize_ratio($new['ratio'] ?? '1/1'),
            'full_width' => !empty($new['full_width']) ? 1 : 0,
            'show_count' => !empty($new['show_count']) ? 1 : 0,
        );
    }
}

/* -------------------------------------------------------------------------
 * 4. Banniere d'accroche
 * ------------------------------------------------------------------------- */

class WPArabic_Home_Widget_Hero extends WPArabic_Home_Widget {

    public function __construct() {
        parent::__construct('at-home-hero', wparabic_widget_name(__('لافتة ترويجية', 'wparabic-ecomm-cod')), array(
            'description' => __('صورة خلفية مع عنوان ونص وزر.', 'wparabic-ecomm-cod'),
        ));
    }

    public function defaults() {
        return array(
            'enabled'     => 1,
            'title'       => '',
            'subtitle'    => '',
            'image_id'    => 0,
            'button_text' => __('تسوق الان', 'wparabic-ecomm-cod'),
            'button_url'  => '',
            'height'      => 'lg',
            'overlay'     => 1,
        );
    }

    public function widget($args, $instance) {
        if ($this->is_disabled($instance)) {
            return; // AVANT before_widget : pas de <section> vide.
        }

        $i     = $this->prepare($instance);
        $image = $i['image_id'] ? wp_get_attachment_image_url((int) $i['image_id'], 'full') : '';

        // Sans image ni titre la banniere n'a rien a montrer.
        if (!$image && trim((string) $i['title']) === '') {
            return;
        }

        $size  = in_array($i['height'], array('lg', 'long'), true) ? $i['height'] : 'lg';
        $style = $image ? ' style="background-image: url(' . esc_url($image) . ')"' : '';

        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
        ?>
        <div class="block-layout">
            <div class="row">
                <div class="col-12">
                    <div class="block-item block-item-<?php echo esc_attr($size); ?><?php echo empty($i['overlay']) ? ' block-item-no-overlay' : ''; ?>"<?php echo $style; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
                        <div class="block-item-content-wrap">
                            <div class="block-item-content">
                                <?php if (trim((string) $i['title']) !== '') : ?>
                                    <p class="block-item-title"><?php echo esc_html($i['title']); ?></p>
                                <?php endif; ?>
                                <?php if (trim((string) $i['subtitle']) !== '') : ?>
                                    <p class="block-item-subtitle"><?php echo esc_html($i['subtitle']); ?></p>
                                <?php endif; ?>
                                <?php if (trim((string) $i['button_text']) !== '' && $i['button_url'] !== '') : ?>
                                    <a href="<?php echo esc_url($i['button_url']); ?>" class="btn btn-sm btn-block-item"><?php echo esc_html($i['button_text']); ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    public function form($instance) {
        $i = wp_parse_args((array) $instance, $this->defaults());

        $this->field_enabled($i);

        $this->field_media($i, 'image_id', __('صورة الخلفية', 'wparabic-ecomm-cod'));
        $this->field_checkbox($i, 'overlay', __('تظليل الصورة (رمادي 40%)', 'wparabic-ecomm-cod'));
        $this->field_text($i, 'title', __('العنوان', 'wparabic-ecomm-cod'));
        $this->field_text($i, 'subtitle', __('النص', 'wparabic-ecomm-cod'));
        $this->field_text($i, 'button_text', __('نص الزر', 'wparabic-ecomm-cod'));
        $this->field_text($i, 'button_url', __('رابط الزر', 'wparabic-ecomm-cod'), 'url');
        $this->field_select($i, 'height', __('الارتفاع', 'wparabic-ecomm-cod'), array(
            'lg'   => __('كبير (16:9)', 'wparabic-ecomm-cod'),
            'long' => __('شريط (215px)', 'wparabic-ecomm-cod'),
        ));
    }

    public function update($new, $old) {
        return array(
            'enabled'     => $this->save_enabled($new),
            'title'       => sanitize_text_field($new['title'] ?? ''),
            'subtitle'    => sanitize_text_field($new['subtitle'] ?? ''),
            'image_id'    => absint($new['image_id'] ?? 0),
            'button_text' => sanitize_text_field($new['button_text'] ?? ''),
            'button_url'  => esc_url_raw($new['button_url'] ?? ''),
            'height'      => in_array($new['height'] ?? 'lg', array('lg', 'long'), true) ? $new['height'] : 'lg',
            'overlay'     => !empty($new['overlay']) ? 1 : 0,
        );
    }
}

/* -------------------------------------------------------------------------
 * Enregistrement
 * ------------------------------------------------------------------------- */

// Versions par langue des textes des sections (WPArabic_Home_Widget).
add_action('in_widget_form', function ($widget, $return, $instance) {
    if ($widget instanceof WPArabic_Home_Widget) {
        $widget->translations_form($instance);
    }
}, 10, 3);

add_filter('widget_update_callback', function ($instance, $new, $old, $widget) {
    if ($widget instanceof WPArabic_Home_Widget && is_array($instance)) {
        $instance = $widget->save_translations($instance, (array) $new);
    }
    return $instance;
}, 10, 4);

/**
 * URL du personnalisateur ouvert sur les sections de la page d'accueil.
 *
 * « url » est indispensable : la zone widget-sections n'est rendue que sur
 * l'accueil, l'apercu doit donc y demarrer pour que le panneau s'ouvre.
 *
 * @return string
 */
function wparabic_home_customize_url() {
    return add_query_arg(
        array(
            'autofocus' => array('section' => 'sidebar-widgets-widget-sections'),
            'url'       => rawurlencode(home_url('/')),
            'return'    => rawurlencode(admin_url('themes.php')),
        ),
        admin_url('customize.php')
    );
}

/**
 * Raccourci « الصفحة الرئيسية » sous Apparence.
 *
 * Mene au personnalisateur plutot qu'a Apparence -> Widgets : le gerant y
 * obtient au meme endroit la liste des sections (reordonnables par
 * glisser-deposer), tous leurs champs, l'interrupteur, et l'apercu en direct.
 */
add_action('admin_menu', 'wparabic_home_admin_link', 20);
function wparabic_home_admin_link() {
    add_submenu_page(
        'themes.php',
        __('الصفحة الرئيسية', 'wparabic-ecomm-cod'),
        __('الصفحة الرئيسية', 'wparabic-ecomm-cod'),
        'edit_theme_options',
        wparabic_home_customize_url()
    );
}

add_action('widgets_init', 'wparabic_register_home_widgets');
function wparabic_register_home_widgets() {
    register_widget('WPArabic_Home_Widget_Hero');
    register_widget('WPArabic_Home_Widget_Products');
    register_widget('WPArabic_Home_Widget_Products_By_Cat');
    register_widget('WPArabic_Home_Widget_Features');
    register_widget('WPArabic_Home_Widget_Categories');
}

/* -------------------------------------------------------------------------
 * Assets d'administration (selecteur d'image)
 * ------------------------------------------------------------------------- */

add_action('admin_enqueue_scripts', 'wparabic_home_widgets_admin_assets');
function wparabic_home_widgets_admin_assets($hook) {
    if ($hook !== 'widgets.php' || !current_user_can('upload_files')) {
        return;
    }

    wp_enqueue_media();
    wp_enqueue_script(
        'wparabic-admin-widgets',
        WPARABIC_ASSETS . '/js/admin-widgets.js',
        array('jquery'),
        wparabic_asset_ver('assets/js/admin-widgets.js'),
        true
    );
}

// Le personnalisateur rend les memes formulaires de widgets.
add_action('customize_controls_enqueue_scripts', 'wparabic_home_widgets_customizer_assets');
function wparabic_home_widgets_customizer_assets() {
    if (!current_user_can('upload_files')) {
        return;
    }

    wp_enqueue_media();
    wp_enqueue_script(
        'wparabic-admin-widgets',
        WPARABIC_ASSETS . '/js/admin-widgets.js',
        array('jquery'),
        wparabic_asset_ver('assets/js/admin-widgets.js'),
        true
    );
}

/* -------------------------------------------------------------------------
 * Rendu de repli
 * ------------------------------------------------------------------------- */

/**
 * Rend les trois sections d'origine quand la zone « widget-sections » est vide.
 *
 * On instancie les widgets plutot que de dupliquer leur markup : une seule
 * source de verite. On n'utilise PAS the_widget(), qui fait
 * sprintf($before_widget, $classname) avec un seul argument
 * (wp-includes/widgets.php) et leverait un ArgumentCountError sur notre %2$s.
 * Les gabarits sont relus depuis la zone enregistree pour qu'ils ne puissent
 * pas diverger de dynamic_sidebar().
 */
function wparabic_render_home_default_sections() {
    global $wp_registered_sidebars;

    $sidebar = isset($wp_registered_sidebars['widget-sections'])
        ? $wp_registered_sidebars['widget-sections']
        : array();

    $classes = array(
        'WPArabic_Home_Widget_Products',
        'WPArabic_Home_Widget_Features',
        'WPArabic_Home_Widget_Categories',
    );

    foreach ($classes as $class) {
        if (!class_exists($class)) {
            continue;
        }

        $widget = new $class();
        $widget->_set(1);

        $args = wp_parse_args($sidebar, array(
            'before_widget' => '<section id="%1$s" class="%2$s">',
            'after_widget'  => '</section>',
            'before_title'  => '<h2>',
            'after_title'   => '</h2>',
        ));

        $args['before_widget'] = sprintf(
            $args['before_widget'],
            esc_attr($widget->id),
            esc_attr($widget->widget_options['classname'])
        );
        $args['widget_id']   = $widget->id;
        $args['widget_name'] = $widget->name;

        // Instance vide : defaults() fournit tout, y compris les anciens
        // reglages du personnalisateur.
        $widget->widget($args, array());
    }
}
