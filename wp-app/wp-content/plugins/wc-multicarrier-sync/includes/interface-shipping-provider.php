<?php
/**
 * Interface principale pour tous les transporteurs.
 *
 * @package WC_MultiCarrier_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Interface WCMCS_ShippingProviderInterface
 *
 * Chaque transporteur DOIT implémenter cette interface.
 * Cela garantit l'extensibilité et le découplage.
 */
interface WCMCS_ShippingProviderInterface {

    /**
     * Retourne le slug unique du transporteur (ex: 'firstdelivery', 'colissimo').
     *
     * @return string
     */
    public function get_slug(): string;

    /**
     * Retourne le nom lisible du transporteur.
     *
     * @return string
     */
    public function get_label(): string;

    /**
     * Valide une commande avant envoi.
     * Doit retourner WP_Error si invalide, true si ok.
     *
     * @param WC_Order $order
     * @return true|WP_Error
     */
    public function validate_order( WC_Order $order );

    /**
     * Crée l'expédition côté API transporteur.
     * Retourne un tableau de données ou WP_Error.
     *
     * @param WC_Order $order
     * @return array|WP_Error  ['tracking_id' => '...', 'label_url' => '...', 'raw' => [...]]
     */
    public function create_shipment( WC_Order $order );

    /**
     * Annule une expédition existante.
     *
     * @param string   $tracking_id
     * @param WC_Order $order
     * @return bool|WP_Error
     */
    public function cancel_shipment( string $tracking_id, WC_Order $order );

    /**
     * Retourne le statut d'un tracking.
     *
     * @param string $tracking_id
     * @return array|WP_Error
     */
    public function get_tracking_status( string $tracking_id );

    /**
     * Retourne les paramètres de configuration requis pour ce provider.
     * Utilisé par la page de settings pour afficher les champs.
     *
     * @return array  [ ['id'=>'api_key','label'=>'Clé API','type'=>'password'], ... ]
     */
    public function get_settings_fields(): array;

    /**
     * Indique si le provider est correctement configuré (clés API renseignées, etc.).
     *
     * @return bool
     */
    public function is_configured(): bool;

    /**
     * Indique si le provider est actif (implémenté et disponible).
     * Les providers inactifs sont listés mais non utilisables.
     *
     * @return bool
     */
    public function is_active(): bool;
}
