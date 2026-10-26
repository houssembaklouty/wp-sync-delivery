<?php
/**
 * Trait partagé de mapping des champs WooCommerce → transporteur.
 *
 * Factorise la logique commune aux providers (Colissimo TN, FirstDelivery) :
 *  - liste des sources WooCommerce mappables
 *  - lecture d'un champ mappé (source + secours)
 *  - extraction d'un champ WooCommerce depuis une commande
 *
 * Le provider consommateur doit :
 *  - définir const FIELD_DEFAULTS (clé champ transporteur → ['source', 'fallback'])
 *  - implémenter mapping_option_prefix()
 *
 * @package WC_MultiCarrier_Sync
 */

defined( 'ABSPATH' ) || exit;

trait WCMCS_Field_Mapping {

    // ─────────────────────────────────────────────
    //  Contrat du provider consommateur
    // ─────────────────────────────────────────────

    /**
     * Préfixe des options de mapping du provider.
     * Ex: 'wcmcs_colissimo_map_' → option 'wcmcs_colissimo_map_client'
     * et 'wcmcs_colissimo_map_client_fallback'.
     */
    abstract public function mapping_option_prefix(): string;

    // ─────────────────────────────────────────────
    //  Sources WC mappables (partagées)
    // ─────────────────────────────────────────────

    /**
     * Sources de champs WC disponibles pour le mapping.
     * (Méthode et non constante : les traits ne supportent les constantes qu'à partir de PHP 8.1.)
     *
     * @return array<string, string>
     */
    public static function wc_field_options(): array {
        return [
            ''                       => '— Aucun —',
            'shipping_first_name'    => __( 'Prénom (livraison)', 'wc-multicarrier-sync' ),
            'shipping_last_name'     => __( 'Nom (livraison)', 'wc-multicarrier-sync' ),
            'shipping_company'       => __( 'Société (livraison)', 'wc-multicarrier-sync' ),
            'shipping_address_1'     => __( 'Adresse 1 (livraison)', 'wc-multicarrier-sync' ),
            'shipping_address_2'     => __( 'Adresse 2 (livraison)', 'wc-multicarrier-sync' ),
            'shipping_city'          => __( 'Ville (livraison)', 'wc-multicarrier-sync' ),
            'shipping_state'         => __( 'État/Gouvernorat (livraison)', 'wc-multicarrier-sync' ),
            'shipping_postcode'      => __( 'Code postal (livraison)', 'wc-multicarrier-sync' ),
            'shipping_country'       => __( 'Pays (livraison)', 'wc-multicarrier-sync' ),
            'shipping_phone'         => __( 'Téléphone (livraison)', 'wc-multicarrier-sync' ),
            'billing_first_name'     => __( 'Prénom (facturation)', 'wc-multicarrier-sync' ),
            'billing_last_name'      => __( 'Nom (facturation)', 'wc-multicarrier-sync' ),
            'billing_company'        => __( 'Société (facturation)', 'wc-multicarrier-sync' ),
            'billing_address_1'      => __( 'Adresse 1 (facturation)', 'wc-multicarrier-sync' ),
            'billing_address_2'      => __( 'Adresse 2 (facturation)', 'wc-multicarrier-sync' ),
            'billing_city'           => __( 'Ville (facturation)', 'wc-multicarrier-sync' ),
            'billing_state'          => __( 'État/Gouvernorat (facturation)', 'wc-multicarrier-sync' ),
            'billing_postcode'       => __( 'Code postal (facturation)', 'wc-multicarrier-sync' ),
            'billing_country'        => __( 'Pays (facturation)', 'wc-multicarrier-sync' ),
            'billing_phone'          => __( 'Téléphone (facturation)', 'wc-multicarrier-sync' ),
            'billing_email'          => __( 'Email (facturation)', 'wc-multicarrier-sync' ),
            'shipping_full_name'     => __( 'Nom complet (livraison)', 'wc-multicarrier-sync' ),
            'billing_full_name'      => __( 'Nom complet (facturation)', 'wc-multicarrier-sync' ),
            'full_address'           => __( 'Adresse complète (livraison)', 'wc-multicarrier-sync' ),
            'billing_full_address'   => __( 'Adresse complète (facturation)', 'wc-multicarrier-sync' ),
            'order_id'               => __( 'Numéro de commande', 'wc-multicarrier-sync' ),
            'customer_note'          => __( 'Note du client', 'wc-multicarrier-sync' ),
            'order_total'            => __( 'Montant total', 'wc-multicarrier-sync' ),
        ];
    }

    // ─────────────────────────────────────────────
    //  Lecture d'un champ mappé
    // ─────────────────────────────────────────────

    /**
     * Clés de champ « note transporteur » gérées par le sélecteur multiple
     * par transporteur (réglage wcmcs_{slug}_note_fields).
     *
     * @return string[]
     */
    private function note_field_keys(): array {
        return [ 'commentaire', 'msg' ];
    }

    /**
     * Réglage partagé « Note / Commentaire envoyé » : sélecteur multiple des
     * champs à inclure. Rien de sélectionné (défaut) = note VIDE — la note
     * auto-composée (résumé COD du thème…) n'est plus envoyée telle quelle.
     *
     * @return array Définition de champ pour get_settings_fields().
     */
    protected function note_settings_field(): array {
        $options = self::wc_field_options();
        unset( $options[''] );

        return [
            'id'          => 'wcmcs_' . $this->get_slug() . '_note_fields',
            'label'       => __( 'Note / Commentaire envoyé au transporteur', 'wc-multicarrier-sync' ),
            'type'        => 'note_multiselect',
            'options'     => $options,
            'default'     => [],
            'description' => __( 'Champs à inclure dans la note envoyée (Ctrl/⌘ + clic pour en choisir plusieurs). Rien de sélectionné = note vide (défaut recommandé).', 'wc-multicarrier-sync' ),
        ];
    }

    /**
     * Compose la note depuis le sélecteur multiple du transporteur :
     * « Libellé : valeur | Libellé : valeur » (champs vides ignorés).
     */
    protected function compose_note( WC_Order $order ): string {
        $fields = get_option( 'wcmcs_' . $this->get_slug() . '_note_fields', [] );
        if ( ! is_array( $fields ) || empty( $fields ) ) {
            return '';
        }
        $options = self::wc_field_options();
        $parts   = [];
        foreach ( $fields as $field_key ) {
            $field_key = sanitize_key( (string) $field_key );
            if ( '' === $field_key || ! isset( $options[ $field_key ] ) ) {
                continue;
            }
            $value = trim( $this->extract_wc_field( $order, $field_key ) );
            if ( '' === $value ) {
                continue;
            }
            $parts[] = $options[ $field_key ] . ' : ' . $value;
        }
        return implode( ' | ', $parts );
    }

    /**
     * Récupère la valeur d'un champ mappé pour une commande.
     *
     * @param WC_Order $order
     * @param string   $field_name  Clé transporteur (ex: 'client', 'gouvernerat').
     * @return string
     */
    protected function get_mapped_field( WC_Order $order, string $field_name ): string {
        // Note transporteur : TOUJOURS composée via le sélecteur multiple du
        // transporteur (défaut vide) — l'ancien mapping simple « customer_note »
        // n'est plus appliqué pour ces clés.
        if ( in_array( $field_name, $this->note_field_keys(), true ) ) {
            return $this->compose_note( $order );
        }

        $defaults = static::FIELD_DEFAULTS[ $field_name ] ?? [ 'source' => '', 'fallback' => '' ];
        $prefix   = $this->mapping_option_prefix();
        $source   = get_option( $prefix . $field_name, $defaults['source'] );
        $fallback = get_option( $prefix . $field_name . '_fallback', $defaults['fallback'] );

        $value = $this->extract_wc_field( $order, $source );
        if ( empty( $value ) && ! empty( $fallback ) ) {
            $value = $this->extract_wc_field( $order, $fallback );
        }
        return $value;
    }

    // ─────────────────────────────────────────────
    //  Extraction d'un champ WooCommerce
    // ─────────────────────────────────────────────

    /**
     * Extrait la valeur d'un champ WooCommerce depuis une commande.
     *
     * @param WC_Order $order
     * @param string   $field_key  Clé du champ (ex: 'shipping_city', 'full_address').
     * @return string
     */
    protected function extract_wc_field( WC_Order $order, string $field_key ): string {
        if ( empty( $field_key ) ) {
            return '';
        }

        // Champs composites
        switch ( $field_key ) {
            case 'shipping_full_name':
                return trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
            case 'billing_full_name':
                return trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
            case 'full_address':
                return trim( $order->get_shipping_address_1() . ( $order->get_shipping_address_2() ? ' ' . $order->get_shipping_address_2() : '' ) );
            case 'billing_full_address':
                return trim( $order->get_billing_address_1() . ( $order->get_billing_address_2() ? ' ' . $order->get_billing_address_2() : '' ) );
            case 'order_id':
                return (string) $order->get_id();
            case 'customer_note':
                return (string) $order->get_customer_note();
            case 'order_total':
                return (string) $order->get_total();
        }

        // Champs simples via méthode WC_Order
        $methods = [
            'shipping_first_name' => 'get_shipping_first_name',
            'shipping_last_name'  => 'get_shipping_last_name',
            'shipping_company'    => 'get_shipping_company',
            'shipping_address_1'  => 'get_shipping_address_1',
            'shipping_address_2'  => 'get_shipping_address_2',
            'shipping_city'       => 'get_shipping_city',
            'shipping_state'      => 'get_shipping_state',
            'shipping_postcode'   => 'get_shipping_postcode',
            'shipping_country'    => 'get_shipping_country',
            'shipping_phone'      => 'get_shipping_phone',
            'billing_first_name'  => 'get_billing_first_name',
            'billing_last_name'   => 'get_billing_last_name',
            'billing_company'     => 'get_billing_company',
            'billing_address_1'   => 'get_billing_address_1',
            'billing_address_2'   => 'get_billing_address_2',
            'billing_city'        => 'get_billing_city',
            'billing_state'       => 'get_billing_state',
            'billing_postcode'    => 'get_billing_postcode',
            'billing_country'     => 'get_billing_country',
            'billing_phone'       => 'get_billing_phone',
            'billing_email'       => 'get_billing_email',
        ];

        if ( isset( $methods[ $field_key ] ) && method_exists( $order, $methods[ $field_key ] ) ) {
            return (string) $order->{$methods[ $field_key ]}();
        }

        return '';
    }
}
