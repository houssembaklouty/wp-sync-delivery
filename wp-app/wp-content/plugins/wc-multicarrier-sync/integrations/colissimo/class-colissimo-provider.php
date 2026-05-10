<?php
/**
 * Intégration Colissimo (La Poste France).
 *
 * API : Colissimo Expeditor - Service Web generateLabel
 * WSDL : https://ws.colissimo.fr/sls-ws/SlsServiceWSImpl?wsdl
 * Ou API REST : https://ws.colissimo.fr/coliship/ws/v1/
 *
 * @package WC_MultiCarrier_Sync
 * @subpackage Integrations
 */

defined( 'ABSPATH' ) || exit;

require_once WCMCS_PLUGIN_DIR . 'includes/interface-shipping-provider.php';

class WCMCS_Colissimo_Provider implements WCMCS_ShippingProviderInterface {

    const SLUG         = 'colissimo';
    const API_BASE_URL = 'https://ws.colissimo.fr/coliship/ws/v1/';

    /** @var string */
    private string $account_number;

    /** @var string */
    private string $password;

    /** @var bool */
    private bool $sandbox;

    public function __construct() {
        $this->account_number = (string) get_option( 'wcmcs_colissimo_account_number', '' );
        $this->password       = (string) get_option( 'wcmcs_colissimo_password', '' );
        $this->sandbox        = ( get_option( 'wcmcs_colissimo_sandbox', 'no' ) === 'yes' );
    }

    // ─────────────────────────────────────────────
    //  Interface impl
    // ─────────────────────────────────────────────

    public function get_slug(): string {
        return self::SLUG;
    }

    public function get_label(): string {
        return 'Colissimo';
    }

    public function is_configured(): bool {
        return ! empty( $this->account_number ) && ! empty( $this->password );
    }

    public function get_settings_fields(): array {
        return [
            [
                'id'          => 'wcmcs_colissimo_account_number',
                'label'       => __( 'Numéro de compte', 'wc-multicarrier-sync' ),
                'type'        => 'text',
                'placeholder' => '9XXXXXXX',
            ],
            [
                'id'          => 'wcmcs_colissimo_password',
                'label'       => __( 'Mot de passe API', 'wc-multicarrier-sync' ),
                'type'        => 'password',
            ],
            [
                'id'          => 'wcmcs_colissimo_sandbox',
                'label'       => __( 'Mode test', 'wc-multicarrier-sync' ),
                'type'        => 'checkbox',
                'description' => __( 'Utilise l\'environnement de recette Colissimo.', 'wc-multicarrier-sync' ),
            ],
            [
                'id'          => 'wcmcs_colissimo_sender_name',
                'label'       => __( 'Nom expéditeur', 'wc-multicarrier-sync' ),
                'type'        => 'text',
            ],
            [
                'id'          => 'wcmcs_colissimo_sender_address',
                'label'       => __( 'Adresse expéditeur', 'wc-multicarrier-sync' ),
                'type'        => 'text',
            ],
            [
                'id'          => 'wcmcs_colissimo_sender_city',
                'label'       => __( 'Ville expéditeur', 'wc-multicarrier-sync' ),
                'type'        => 'text',
            ],
            [
                'id'          => 'wcmcs_colissimo_sender_postcode',
                'label'       => __( 'Code postal expéditeur', 'wc-multicarrier-sync' ),
                'type'        => 'text',
            ],
            [
                'id'          => 'wcmcs_colissimo_sender_country',
                'label'       => __( 'Pays expéditeur (ISO)', 'wc-multicarrier-sync' ),
                'type'        => 'text',
                'placeholder' => 'FR',
            ],
            [
                'id'          => 'wcmcs_colissimo_product_code',
                'label'       => __( 'Code produit par défaut', 'wc-multicarrier-sync' ),
                'type'        => 'select',
                'options'     => [
                    'DOM'  => 'Colissimo France (DOM)',
                    'COLI' => 'Colissimo International (COLI)',
                    'DOS'  => 'Colissimo sans signature',
                    'CDS'  => 'Colissimo avec signature',
                ],
            ],
        ];
    }

    // ─────────────────────────────────────────────
    //  Validation
    // ─────────────────────────────────────────────

    public function validate_order( WC_Order $order ) {
        $errors = new WP_Error();

        $phone = trim( $order->get_shipping_phone() ?: $order->get_billing_phone() );
        if ( empty( $phone ) ) {
            $errors->add( 'missing_phone', __( 'Numéro de téléphone requis par Colissimo.', 'wc-multicarrier-sync' ) );
        }

        $email = $order->get_billing_email();
        if ( empty( $email ) || ! is_email( $email ) ) {
            $errors->add( 'missing_email', __( 'Email valide requis par Colissimo.', 'wc-multicarrier-sync' ) );
        }

        $postcode = $order->get_shipping_postcode();
        if ( empty( $postcode ) ) {
            $errors->add( 'missing_postcode', __( 'Code postal de livraison manquant.', 'wc-multicarrier-sync' ) );
        }

        $city = $order->get_shipping_city();
        if ( empty( $city ) ) {
            $errors->add( 'missing_city', __( 'Ville de livraison manquante.', 'wc-multicarrier-sync' ) );
        }

        // Poids total (Colissimo limite : 30kg)
        $total_weight = $this->calculate_total_weight( $order );
        if ( $total_weight > 30 ) {
            $errors->add(
                'weight_exceeded',
                sprintf( __( 'Poids total (%s kg) dépasse la limite Colissimo de 30 kg.', 'wc-multicarrier-sync' ), $total_weight )
            );
        }

        if ( $errors->has_errors() ) {
            return new WP_Error( 'validation_failed', implode( ' | ', $errors->get_error_messages() ) );
        }

        return true;
    }

    // ─────────────────────────────────────────────
    //  Création d'expédition
    // ─────────────────────────────────────────────

    public function create_shipment( WC_Order $order ) {
        $payload = $this->build_shipment_payload( $order );

        $payload = apply_filters( 'wcmcs_colissimo_payload', $payload, $order );

        WCMCS_Logger::debug( 'Colissimo payload: ' . wp_json_encode( $payload ) );

        $response = $this->api_request( 'POST', 'generateLabel', $payload );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // L'API Colissimo retourne l'étiquette en base64 + le tracking
        $tracking_id = $response['parcelNumber']
            ?? $response['tracking_number']
            ?? $response['parcel_number']
            ?? '';

        if ( empty( $tracking_id ) ) {
            return new WP_Error(
                'no_tracking_id',
                __( 'Colissimo : aucun numéro de colis dans la réponse.', 'wc-multicarrier-sync' ),
                $response
            );
        }

        // Sauvegarder l'étiquette PDF si présente
        $label_url = '';
        if ( ! empty( $response['label'] ) ) {
            $label_url = $this->save_label_pdf( $tracking_id, $response['label'], $order->get_id() );
        }

        return [
            'tracking_id' => (string) $tracking_id,
            'label_url'   => $label_url,
            'raw'         => $response,
        ];
    }

    // ─────────────────────────────────────────────
    //  Annulation
    // ─────────────────────────────────────────────

    public function cancel_shipment( string $tracking_id, WC_Order $order ) {
        // Colissimo ne supporte pas toujours l'annulation API
        // Implémenter selon la disponibilité de l'endpoint
        WCMCS_Logger::warning( "Colissimo: annulation non supportée pour tracking {$tracking_id}. Action manuelle requise." );
        return new WP_Error(
            'cancel_not_supported',
            __( 'L\'annulation d\'expédition Colissimo doit être effectuée depuis l\'espace client La Poste.', 'wc-multicarrier-sync' )
        );
    }

    // ─────────────────────────────────────────────
    //  Tracking
    // ─────────────────────────────────────────────

    public function get_tracking_status( string $tracking_id ) {
        return $this->api_request( 'GET', 'tracking/' . rawurlencode( $tracking_id ), [] );
    }

    // ─────────────────────────────────────────────
    //  Construction du payload
    // ─────────────────────────────────────────────

    private function build_shipment_payload( WC_Order $order ): array {
        $total_weight = max( 0.1, $this->calculate_total_weight( $order ) );
        $country      = $order->get_shipping_country() ?: 'FR';
        $product_code = $this->get_product_code( $country );

        return [
            'contractNumber' => $this->account_number,
            'password'       => $this->password,

            // Expéditeur
            'sender' => [
                'companyName' => get_option( 'wcmcs_colissimo_sender_name', get_bloginfo( 'name' ) ),
                'address'     => [
                    'line1'       => get_option( 'wcmcs_colissimo_sender_address', '' ),
                    'city'        => get_option( 'wcmcs_colissimo_sender_city', '' ),
                    'zipCode'     => get_option( 'wcmcs_colissimo_sender_postcode', '' ),
                    'countryCode' => get_option( 'wcmcs_colissimo_sender_country', 'FR' ),
                ],
            ],

            // Destinataire
            'addressee' => [
                'lastName'  => $order->get_shipping_last_name() ?: $order->get_billing_last_name(),
                'firstName' => $order->get_shipping_first_name() ?: $order->get_billing_first_name(),
                'email'     => $order->get_billing_email(),
                'phone'     => $order->get_shipping_phone() ?: $order->get_billing_phone(),
                'address'   => [
                    'line1'       => $order->get_shipping_address_1(),
                    'line2'       => $order->get_shipping_address_2(),
                    'city'        => $order->get_shipping_city(),
                    'zipCode'     => $order->get_shipping_postcode(),
                    'countryCode' => $country,
                ],
            ],

            // Caractéristiques du colis
            'parcel' => [
                'weight'              => $total_weight,
                'nonMachinable'       => false,
                'returnReceipt'       => false,
                'instructions'        => $order->get_customer_note(),
                'pickupLocationId'    => null,
                'ftd'                 => false,
                'ddp'                 => false,
                'insuranceValue'      => (float) $order->get_total(),
                'insuranceAmount'     => 0,
                'recommendationLevel' => 'R0',
            ],

            // Configuration
            'contractNumber'        => $this->account_number,
            'customsDeclarations'   => $this->get_customs_declarations( $order, $country ),
            'outputFormat'          => [
                'x'              => 0,
                'y'              => 0,
                'outputPrintingType' => 'PDF_10x15_300dpi',
            ],
            'service' => [
                'productCode'            => $product_code,
                'depositDate'            => gmdate( 'Y-m-d' ),
                'commercialName'         => get_bloginfo( 'name' ),
                'totalAmount'            => (int) round( (float) $order->get_total() * 100 ), // en centimes
                'orderNumber'            => (string) $order->get_id(),
                'notificationParameters' => [
                    'email'        => $order->get_billing_email(),
                    'notifyBySMS'  => false,
                    'notifyByEMail' => true,
                ],
            ],
        ];
    }

    // ─────────────────────────────────────────────
    //  Utilitaires
    // ─────────────────────────────────────────────

    private function calculate_total_weight( WC_Order $order ): float {
        $total = 0.0;
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product ) {
                $total += (float) ( $product->get_weight() ?: 0 ) * $item->get_quantity();
            }
        }
        return round( $total, 3 );
    }

    private function get_product_code( string $country_code ): string {
        $custom = get_option( 'wcmcs_colissimo_product_code', '' );
        if ( $custom ) {
            return $custom;
        }
        // Détection automatique par pays
        $domestic = [ 'FR', 'GP', 'MQ', 'GF', 'RE', 'YT', 'PM', 'MF', 'BL', 'WF', 'PF', 'NC' ];
        return in_array( $country_code, $domestic, true ) ? 'DOM' : 'COLI';
    }

    private function get_customs_declarations( WC_Order $order, string $country ): ?array {
        // Déclaration douanière uniquement hors France/UE
        $eu_countries = [ 'FR', 'DE', 'IT', 'ES', 'PT', 'BE', 'NL', 'LU', 'AT', 'FI', 'SE', 'DK', 'IE', 'GR' ];
        if ( in_array( $country, $eu_countries, true ) ) {
            return null;
        }

        $articles = [];
        foreach ( $order->get_items() as $item ) {
            $product  = $item->get_product();
            $articles[] = [
                'description'     => substr( $item->get_name(), 0, 64 ),
                'quantity'        => $item->get_quantity(),
                'weight'          => (float) ( $product ? $product->get_weight() : 0.1 ),
                'value'           => (float) $order->get_item_total( $item ),
                'hsCode'          => $product ? $product->get_meta( '_hs_code' ) : '',
                'originCountry'   => 'FR',
            ];
        }

        return [
            'includeCustomsDeclarations' => 1,
            'contents' => [
                'article'          => $articles,
                'category'         => [ 'value' => 3 ], // 3 = Marchandises
                'totalAmount'      => (float) $order->get_subtotal(),
                'totalWeight'      => $this->calculate_total_weight( $order ),
                'explanation'      => 'Sale of goods',
                'importersReference' => '',
            ],
        ];
    }

    /**
     * Sauvegarde le PDF d'étiquette dans les uploads WP.
     *
     * @param string $tracking_id
     * @param string $base64_pdf
     * @param int    $order_id
     * @return string URL du fichier ou chaîne vide
     */
    private function save_label_pdf( string $tracking_id, string $base64_pdf, int $order_id ): string {
        try {
            $upload_dir = wp_upload_dir();
            $label_dir  = $upload_dir['basedir'] . '/wcmcs-labels/' . $order_id . '/';

            if ( ! file_exists( $label_dir ) ) {
                wp_mkdir_p( $label_dir );
                file_put_contents( $label_dir . '.htaccess', 'Options -Indexes' );
            }

            $filename = sanitize_file_name( 'colissimo_' . $tracking_id . '_' . time() . '.pdf' );
            $file_path = $label_dir . $filename;

            $decoded = base64_decode( $base64_pdf, true );
            if ( $decoded === false ) {
                WCMCS_Logger::warning( "Colissimo: échec décodage base64 label pour tracking {$tracking_id}." );
                return '';
            }

            file_put_contents( $file_path, $decoded );
            return $upload_dir['baseurl'] . '/wcmcs-labels/' . $order_id . '/' . $filename;

        } catch ( \Throwable $e ) {
            WCMCS_Logger::error( "Colissimo: erreur sauvegarde label PDF: " . $e->getMessage() );
            return '';
        }
    }

    // ─────────────────────────────────────────────
    //  HTTP Client
    // ─────────────────────────────────────────────

    private function api_request( string $method, string $endpoint, array $body ) {
        $base_url = $this->sandbox
            ? 'https://ws.colissimo.fr/colissimo-ws-test/v1/'
            : self::API_BASE_URL;

        $url = rtrim( $base_url, '/' ) . '/' . ltrim( $endpoint, '/' );

        $args = [
            'method'  => strtoupper( $method ),
            'timeout' => 45, // Colissimo peut être lent
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
        ];

        if ( ! empty( $body ) && in_array( $method, [ 'POST', 'PUT' ], true ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            $msg = $response->get_error_message();
            WCMCS_Logger::error( "Colissimo HTTP error: {$msg}" );
            return new WP_Error( 'api_http_error', $msg );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $raw    = wp_remote_retrieve_body( $response );
        $parsed = json_decode( $raw, true );

        WCMCS_Logger::debug( "Colissimo response [{$status}]: " . substr( $raw, 0, 500 ) );

        if ( $status >= 200 && $status < 300 ) {
            return is_array( $parsed ) ? $parsed : [ 'raw' => $raw ];
        }

        // Erreurs Colissimo : format { "messages": [ { "id": "...", "messageContent": "..." } ] }
        $api_message = '';
        if ( isset( $parsed['messages'] ) && is_array( $parsed['messages'] ) ) {
            $msgs = array_column( $parsed['messages'], 'messageContent' );
            $api_message = implode( ' | ', array_filter( $msgs ) );
        }
        if ( empty( $api_message ) ) {
            $api_message = $parsed['message'] ?? sprintf( __( 'Erreur Colissimo HTTP %d', 'wc-multicarrier-sync' ), $status );
        }

        WCMCS_Logger::error( "Colissimo API error {$status}: {$api_message}" );
        return new WP_Error( 'colissimo_api_error_' . $status, $api_message, $parsed ?? [] );
    }
}
