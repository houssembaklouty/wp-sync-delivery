<?php
/**
 * Intégration FirstDelivery.
 *
 * Doc API supposée : REST JSON, authentification Bearer Token.
 * Endpoint : https://api.firstdelivery.tn/v1/
 *
 * Adaptez les endpoints et le mapping des champs
 * selon la documentation réelle de FirstDelivery.
 *
 * @package WC_MultiCarrier_Sync
 * @subpackage Integrations
 */

defined( 'ABSPATH' ) || exit;

require_once WCMCS_PLUGIN_DIR . 'includes/interface-shipping-provider.php';

class WCMCS_FirstDelivery_Provider implements WCMCS_ShippingProviderInterface {

    const SLUG         = 'firstdelivery';
    const API_BASE_URL = 'https://api.firstdelivery.tn/v1/';

    /** @var string */
    private string $api_token;

    /** @var bool */
    private bool $sandbox;

    public function __construct() {
        $this->api_token = (string) get_option( 'wcmcs_firstdelivery_api_token', '' );
        $this->sandbox   = ( get_option( 'wcmcs_firstdelivery_sandbox', 'no' ) === 'yes' );
    }

    // ─────────────────────────────────────────────
    //  Interface impl
    // ─────────────────────────────────────────────

    public function get_slug(): string {
        return self::SLUG;
    }

    public function get_label(): string {
        return 'FirstDelivery';
    }

    public function is_configured(): bool {
        return ! empty( $this->api_token );
    }

    public function get_settings_fields(): array {
        return [
            [
                'id'          => 'wcmcs_firstdelivery_api_token',
                'label'       => __( 'Token API', 'wc-multicarrier-sync' ),
                'type'        => 'password',
                'description' => __( 'Token Bearer fourni par FirstDelivery.', 'wc-multicarrier-sync' ),
                'placeholder' => 'fd_live_xxxxxxxxxxxx',
            ],
            [
                'id'          => 'wcmcs_firstdelivery_sandbox',
                'label'       => __( 'Mode Sandbox', 'wc-multicarrier-sync' ),
                'type'        => 'checkbox',
                'description' => __( 'Activer le mode test (aucune expédition réelle).', 'wc-multicarrier-sync' ),
            ],
            [
                'id'          => 'wcmcs_firstdelivery_sender_name',
                'label'       => __( 'Nom expéditeur', 'wc-multicarrier-sync' ),
                'type'        => 'text',
                'placeholder' => 'Ma Boutique',
            ],
            [
                'id'          => 'wcmcs_firstdelivery_sender_phone',
                'label'       => __( 'Téléphone expéditeur', 'wc-multicarrier-sync' ),
                'type'        => 'text',
                'placeholder' => '+21612345678',
            ],
            [
                'id'          => 'wcmcs_firstdelivery_pickup_address',
                'label'       => __( 'Adresse enlèvement', 'wc-multicarrier-sync' ),
                'type'        => 'textarea',
            ],
        ];
    }

    // ─────────────────────────────────────────────
    //  Validation
    // ─────────────────────────────────────────────

    public function validate_order( WC_Order $order ) {
        $errors = new WP_Error();

        // Téléphone obligatoire
        $phone = trim( $order->get_shipping_phone() ?: $order->get_billing_phone() );
        if ( empty( $phone ) ) {
            $errors->add( 'missing_phone', __( 'Numéro de téléphone manquant.', 'wc-multicarrier-sync' ) );
        } elseif ( ! $this->is_valid_phone( $phone ) ) {
            $errors->add( 'invalid_phone', sprintf( __( 'Téléphone invalide : %s', 'wc-multicarrier-sync' ), $phone ) );
        }

        // Adresse livraison
        if ( empty( $order->get_shipping_address_1() ) ) {
            $errors->add( 'missing_address', __( 'Adresse de livraison manquante.', 'wc-multicarrier-sync' ) );
        }

        // Ville
        if ( empty( $order->get_shipping_city() ) ) {
            $errors->add( 'missing_city', __( 'Ville de livraison manquante.', 'wc-multicarrier-sync' ) );
        }

        // Montant > 0
        if ( (float) $order->get_total() <= 0 ) {
            $errors->add( 'zero_total', __( 'Le montant total de la commande est nul.', 'wc-multicarrier-sync' ) );
        }

        if ( $errors->has_errors() ) {
            // Retourner la première erreur (ou toutes concaténées)
            $messages = implode( ' | ', $errors->get_error_messages() );
            return new WP_Error( 'validation_failed', $messages );
        }

        return true;
    }

    // ─────────────────────────────────────────────
    //  Création d'expédition
    // ─────────────────────────────────────────────

    public function create_shipment( WC_Order $order ) {
        $payload = $this->build_shipment_payload( $order );

        // Hook pour modifier le payload avant envoi
        $payload = apply_filters( 'wcmcs_firstdelivery_payload', $payload, $order );

        WCMCS_Logger::debug( 'FirstDelivery payload: ' . wp_json_encode( $payload ) );

        $response = $this->api_request( 'POST', 'shipments', $payload );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // Extraire le tracking ID de la réponse
        // ADAPTER selon la structure réelle de l'API FirstDelivery
        $tracking_id = $response['tracking_number']     // Essayer différents champs
            ?? $response['barcode']
            ?? $response['id']
            ?? $response['shipment_id']
            ?? '';

        if ( empty( $tracking_id ) ) {
            return new WP_Error(
                'no_tracking_id',
                __( 'FirstDelivery : aucun numéro de tracking dans la réponse.', 'wc-multicarrier-sync' ),
                $response
            );
        }

        return [
            'tracking_id' => (string) $tracking_id,
            'label_url'   => $response['label_url'] ?? $response['label'] ?? '',
            'raw'         => $response,
        ];
    }

    // ─────────────────────────────────────────────
    //  Annulation
    // ─────────────────────────────────────────────

    public function cancel_shipment( string $tracking_id, WC_Order $order ) {
        $response = $this->api_request( 'DELETE', 'shipments/' . rawurlencode( $tracking_id ), [] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        WCMCS_Logger::info( "FirstDelivery: expédition {$tracking_id} annulée." );
        return true;
    }

    // ─────────────────────────────────────────────
    //  Tracking
    // ─────────────────────────────────────────────

    public function get_tracking_status( string $tracking_id ) {
        return $this->api_request( 'GET', 'shipments/' . rawurlencode( $tracking_id ) . '/tracking', [] );
    }

    // ─────────────────────────────────────────────
    //  Construction du payload
    // ─────────────────────────────────────────────

    /**
     * Construit le payload JSON pour l'API FirstDelivery.
     * ADAPTER selon la documentation réelle.
     *
     * @param WC_Order $order
     * @return array
     */
    private function build_shipment_payload( WC_Order $order ): array {
        $phone = trim( $order->get_shipping_phone() ?: $order->get_billing_phone() );

        // Récupérer les produits
        $items_description = [];
        $total_weight      = 0.0;
        foreach ( $order->get_items() as $item ) {
            /** @var WC_Order_Item_Product $item */
            $product = $item->get_product();
            if ( $product ) {
                $items_description[] = $item->get_name() . ' x' . $item->get_quantity();
                $total_weight += (float) ( $product->get_weight() ?: 0 ) * $item->get_quantity();
            }
        }

        return [
            // Expéditeur
            'sender' => [
                'name'    => get_option( 'wcmcs_firstdelivery_sender_name', get_bloginfo( 'name' ) ),
                'phone'   => get_option( 'wcmcs_firstdelivery_sender_phone', '' ),
                'address' => get_option( 'wcmcs_firstdelivery_pickup_address', '' ),
            ],
            // Destinataire
            'recipient' => [
                'name'         => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() )
                                  ?: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                'phone'        => $phone,
                'phone2'       => $order->get_meta( '_billing_phone2' ) ?: '',
                'address'      => $order->get_shipping_address_1() . ( $order->get_shipping_address_2() ? ', ' . $order->get_shipping_address_2() : '' ),
                'city'         => $order->get_shipping_city(),
                'state'        => $order->get_shipping_state(),
                'postcode'     => $order->get_shipping_postcode(),
                'country_code' => $order->get_shipping_country() ?: 'TN',
            ],
            // Colis
            'parcel' => [
                'weight'      => max( 0.1, round( $total_weight, 3 ) ),
                'description' => implode( '; ', $items_description ),
                'value'       => (float) $order->get_total(),
                'cod_amount'  => $this->get_cod_amount( $order ),
                'is_fragile'  => false,
            ],
            // Référence
            'reference'  => (string) $order->get_id(),
            'order_note' => $order->get_customer_note(),
            // Sandbox
            'test_mode'  => $this->sandbox,
        ];
    }

    /**
     * Retourne le montant COD (paiement à la livraison) si applicable.
     *
     * @param WC_Order $order
     * @return float
     */
    private function get_cod_amount( WC_Order $order ): float {
        $payment_method = $order->get_payment_method();
        // WooCommerce COD method IDs courants
        $cod_methods = apply_filters( 'wcmcs_cod_payment_methods', [ 'cod', 'cash_on_delivery', 'bacs' ] );

        if ( in_array( $payment_method, $cod_methods, true ) ) {
            return (float) $order->get_total();
        }
        return 0.0;
    }

    // ─────────────────────────────────────────────
    //  HTTP Client
    // ─────────────────────────────────────────────

    /**
     * Effectue une requête HTTP vers l'API FirstDelivery.
     *
     * @param string $method  GET|POST|PUT|DELETE
     * @param string $endpoint
     * @param array  $body
     * @return array|WP_Error
     */
    private function api_request( string $method, string $endpoint, array $body ) {
        $base_url = $this->sandbox
            ? 'https://sandbox.firstdelivery.tn/v1/'
            : self::API_BASE_URL;

        $url = rtrim( $base_url, '/' ) . '/' . ltrim( $endpoint, '/' );

        $args = [
            'method'  => strtoupper( $method ),
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'X-Source'      => 'woocommerce-wcmcs/' . WCMCS_VERSION,
            ],
        ];

        if ( ! empty( $body ) && in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        // Vérifier les erreurs WordPress (ex: timeout)
        if ( is_wp_error( $response ) ) {
            $error_msg = $response->get_error_message();
            WCMCS_Logger::error( "FirstDelivery HTTP error: {$error_msg} → {$url}" );
            return new WP_Error( 'api_http_error', $error_msg );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $raw_body    = wp_remote_retrieve_body( $response );
        $parsed      = json_decode( $raw_body, true );

        WCMCS_Logger::debug( "FirstDelivery response [{$status_code}]: " . substr( $raw_body, 0, 500 ) );

        // Succès HTTP
        if ( $status_code >= 200 && $status_code < 300 ) {
            return is_array( $parsed ) ? $parsed : [ 'raw_response' => $raw_body ];
        }

        // Erreur API
        $api_message = $parsed['message']
            ?? $parsed['error']
            ?? $parsed['detail']
            ?? sprintf( __( 'Erreur HTTP %d depuis FirstDelivery.', 'wc-multicarrier-sync' ), $status_code );

        WCMCS_Logger::error( "FirstDelivery API error {$status_code}: {$api_message}" );

        return new WP_Error(
            'firstdelivery_api_error_' . $status_code,
            $api_message,
            $parsed ?? []
        );
    }

    // ─────────────────────────────────────────────
    //  Utilitaires
    // ─────────────────────────────────────────────

    /**
     * Valide un numéro de téléphone tunisien ou international.
     *
     * @param string $phone
     * @return bool
     */
    private function is_valid_phone( string $phone ): bool {
        // Supprimer espaces et tirets
        $clean = preg_replace( '/[\s\-\(\)\.]+/', '', $phone );
        // Format international ou local
        return (bool) preg_match( '/^(\+|00)?[1-9]\d{7,14}$/', $clean );
    }
}
