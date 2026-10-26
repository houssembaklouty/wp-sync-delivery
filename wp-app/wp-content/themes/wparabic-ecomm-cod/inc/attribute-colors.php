<?php
/**
 * Couleur associee a un terme d'attribut produit.
 *
 * Ajoute un selecteur de couleur sur les termes de TOUTES les taxonomies
 * d'attribut WooCommerce (pa_*), pas seulement pa_color : un futur pa_material
 * en beneficie sans une ligne de code.
 *
 * Cote boutique, les termes qui portent une couleur sont affiches en pastilles
 * cliquables au-dessus de leur liste deroulante. La liste deroulante reste la
 * seule source de verite : la pastille ecrit dedans et declenche « change ».
 *
 * @package wparabic-ecomm-cod
 */

defined('ABSPATH') || exit;

define('WPARABIC_TERM_COLOR_META', 'wparabic_color');

/**
 * Noms des taxonomies d'attribut WooCommerce (pa_color, pa_size, ...).
 *
 * @return string[]
 */
function wparabic_attribute_taxonomies() {
    if (!function_exists('wc_get_attribute_taxonomies')) {
        return array();
    }

    $names = array();
    foreach (wc_get_attribute_taxonomies() as $attribute) {
        $names[] = wc_attribute_taxonomy_name($attribute->attribute_name);
    }

    return $names;
}

/* -------------------------------------------------------------------------
 * Administration
 * ------------------------------------------------------------------------- */

// admin_init : WooCommerce a deja enregistre ses taxonomies (init), et
// admin-ajax.php (creation rapide d'un terme) le declenche aussi.
add_action('admin_init', 'wparabic_register_attribute_color_fields');
function wparabic_register_attribute_color_fields() {
    foreach (wparabic_attribute_taxonomies() as $taxonomy) {
        add_action($taxonomy . '_add_form_fields', 'wparabic_term_color_add_field');
        add_action($taxonomy . '_edit_form_fields', 'wparabic_term_color_edit_field', 10, 2);
        add_action('created_' . $taxonomy, 'wparabic_save_term_color');
        add_action('edited_' . $taxonomy, 'wparabic_save_term_color');

        add_filter('manage_edit-' . $taxonomy . '_columns', 'wparabic_term_color_column');
        add_filter('manage_' . $taxonomy . '_custom_column', 'wparabic_term_color_column_value', 10, 3);
    }
}

function wparabic_term_color_add_field() {
    ?>
    <div class="form-field">
        <label for="wparabic_color"><?php esc_html_e('اللون (Hex)', 'wparabic-ecomm-cod'); ?></label>
        <input type="text" name="wparabic_color" id="wparabic_color" value="" class="wparabic-color-field">
        <p class="description"><?php esc_html_e('اتركه فارغًا لعرض قائمة منسدلة عادية بدون دوائر ملونة.', 'wparabic-ecomm-cod'); ?></p>
    </div>
    <?php
}

function wparabic_term_color_edit_field($term, $taxonomy) {
    $value = get_term_meta($term->term_id, WPARABIC_TERM_COLOR_META, true);
    ?>
    <tr class="form-field">
        <th scope="row"><label for="wparabic_color"><?php esc_html_e('اللون (Hex)', 'wparabic-ecomm-cod'); ?></label></th>
        <td>
            <input type="text" name="wparabic_color" id="wparabic_color" value="<?php echo esc_attr($value); ?>" class="wparabic-color-field">
            <p class="description"><?php esc_html_e('اتركه فارغًا لعرض قائمة منسدلة عادية بدون دوائر ملونة.', 'wparabic-ecomm-cod'); ?></p>
        </td>
    </tr>
    <?php
}

function wparabic_save_term_color($term_id) {
    // Le nonce du formulaire de terme est deja verifie par WordPress.
    if (!current_user_can('manage_product_terms') || !isset($_POST['wparabic_color'])) {
        return;
    }

    $raw = trim((string) wp_unslash($_POST['wparabic_color']));

    if ($raw === '') {
        delete_term_meta($term_id, WPARABIC_TERM_COLOR_META);
        return;
    }

    $hex = sanitize_hex_color($raw);
    if ($hex) {
        update_term_meta($term_id, WPARABIC_TERM_COLOR_META, $hex);
    }
}

function wparabic_term_color_column($columns) {
    $new = array();
    foreach ($columns as $key => $label) {
        $new[$key] = $label;
        if ($key === 'cb') {
            $new['wparabic_color'] = __('اللون', 'wparabic-ecomm-cod');
        }
    }
    return $new;
}

function wparabic_term_color_column_value($content, $column, $term_id) {
    if ($column !== 'wparabic_color') {
        return $content;
    }

    $hex = get_term_meta($term_id, WPARABIC_TERM_COLOR_META, true);

    if (!$hex) {
        return '&mdash;';
    }

    return sprintf(
        '<span style="display:inline-block;width:22px;height:22px;border-radius:50%%;background:%1$s;box-shadow:inset 0 0 0 1px rgba(0,0,0,.2)" title="%1$s"></span>',
        esc_attr($hex)
    );
}

add_action('admin_enqueue_scripts', 'wparabic_term_color_picker_assets');
function wparabic_term_color_picker_assets($hook) {
    if ($hook !== 'edit-tags.php' && $hook !== 'term.php') {
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $taxonomy = isset($_GET['taxonomy']) ? sanitize_key($_GET['taxonomy']) : '';

    if (!in_array($taxonomy, wparabic_attribute_taxonomies(), true)) {
        return;
    }

    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');
    // Le formulaire d'ajout est reinitialise en AJAX apres chaque creation,
    // d'ou la re-initialisation sur ajaxComplete.
    wp_add_inline_script(
        'wp-color-picker',
        'jQuery(function($){$(".wparabic-color-field").wpColorPicker();'
        . '$(document).on("ajaxComplete",function(){$(".wparabic-color-field:not(.wp-color-picker)").wpColorPicker();});});'
    );
}

/* -------------------------------------------------------------------------
 * Boutique
 * ------------------------------------------------------------------------- */

/**
 * Remplace visuellement chaque liste deroulante de variation par des boutons.
 *
 * - Attribut dont TOUS les termes ont une couleur (pa_color) : pastilles.
 * - Tout autre attribut (pointure, taille, attribut personnalise) : boutons
 *   texte. Aucun test ne porte sur le nom de l'attribut.
 *
 * Le <select> natif reste dans la page, masque (classe wparabic-hidden-select) :
 * wc-add-to-cart-variation.js continue d'y lire la selection et d'y marquer les
 * options indisponibles ; main.js recopie cet etat sur les boutons
 * (syncSwatches). Les boutons sont rendus HORS du <select> : onUpdateAttributes()
 * remplace tout son contenu a chaque changement.
 */
add_filter('woocommerce_dropdown_variation_attribute_options_html', 'wparabic_variation_buttons', 10, 2);
function wparabic_variation_buttons($html, $args) {
    $attribute = isset($args['attribute']) ? $args['attribute'] : '';
    $product   = isset($args['product']) ? $args['product'] : null;
    $options   = isset($args['options']) ? (array) $args['options'] : array();

    if (!$attribute || !$product instanceof WC_Product || !$options) {
        return $html;
    }

    // [valeur => [libelle, couleur|'']], dans l'ordre des termes de l'admin.
    $items = array();

    if (taxonomy_exists($attribute)) {
        $terms = wc_get_product_terms($product->get_id(), $attribute, array('fields' => 'all'));
        foreach ($terms as $term) {
            if (in_array($term->slug, $options, true)) {
                $items[$term->slug] = array(
                    $term->name,
                    (string) get_term_meta($term->term_id, WPARABIC_TERM_COLOR_META, true),
                );
            }
        }
    } else {
        foreach ($options as $option) {
            $items[$option] = array($option, '');
        }
    }

    if (!$items) {
        return $html;
    }

    $is_color = !in_array('', wp_list_pluck($items, 1), true);
    $buttons  = '';

    foreach ($items as $value => $item) {
        list($label, $hex) = $item;

        $inner = $is_color
            ? sprintf('<span class="wparabic-swatch-dot" style="background-color:%s"></span>', esc_attr($hex))
            : esc_html($label);

        $buttons .= sprintf(
            '<button type="button" class="wparabic-swatch%1$s" data-value="%2$s" data-label="%3$s" title="%3$s" aria-label="%3$s" aria-pressed="false">%4$s</button>',
            $is_color ? ' wparabic-swatch--color' : ' wparabic-swatch--text',
            esc_attr($value),
            esc_attr($label),
            $inner
        );
    }

    return $html . sprintf(
        '<div class="wparabic-swatches %1$s" role="group" aria-label="%2$s">%3$s</div>',
        $is_color ? 'wparabic-swatches--color' : 'wparabic-swatches--text',
        esc_attr(wc_attribute_label($attribute, $product)),
        $buttons
    );
}

/**
 * Le <select> devient un simple support pour WooCommerce : masque a l'ecran,
 * remplace par les boutons ci-dessus.
 */
add_filter('woocommerce_dropdown_variation_attribute_options_args', 'wparabic_hide_variation_select', 20);
function wparabic_hide_variation_select($args) {
    $args['class'] = trim((isset($args['class']) ? $args['class'] : '') . ' wparabic-hidden-select');
    return $args;
}

/**
 * Une variation epuisee est « inactive » : WooCommerce desactive alors son
 * option dans le <select>, et le bouton correspondant est grise.
 */
add_filter('woocommerce_variation_is_active', 'wparabic_variation_active_if_in_stock', 10, 2);
function wparabic_variation_active_if_in_stock($active, $variation) {
    return $active && $variation instanceof WC_Product && $variation->is_in_stock();
}
