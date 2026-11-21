<?php
defined( 'ABSPATH' ) || exit;

/**
 * Traduit les libellés/états transporteur et les statuts WooCommerce en étapes
 * CODCRM_Stages.
 */
class CODCRM_Stage_Mapper {

	/** Statut WooCommerce (sans « wc- ») → étape boutique. */
	const ORDER_STATUS_MAP = [
		'pending'          => CODCRM_Stages::COMMANDE,
		'processing'       => CODCRM_Stages::COMMANDE,
		'on-hold'          => CODCRM_Stages::COMMANDE,
		'wcmcs-confirmed'  => CODCRM_Stages::CONFIRMEE,
		'wcmcs-synced'     => CODCRM_Stages::SYNCHRONISEE,
		'wcmcs-packed'     => CODCRM_Stages::EMBALLEE,
		'wcmcs-delivered'  => CODCRM_Stages::LIVRES,
		'wcmcs-returned'   => CODCRM_Stages::RETOUR_DEFINITIF,
		'cancelled'        => CODCRM_Stages::ANNULEE,
	];

	/**
	 * Règles ordonnées (la 1re qui matche gagne) sur le libellé normalisé
	 * (minuscules, sans accents, alphanumérique — comme MCS norm()).
	 */
	const CARRIER_RULES = [
		[ '/^livre(e|r)?paye/',                CODCRM_Stages::LIVRES_PAYES ],
		[ '/^(livre|livree|livrer|delivered)/', CODCRM_Stages::LIVRES ],
		[ '/^retour(recu)?paye/',              CODCRM_Stages::RETOUR_PAYE ],
		[ '/^retourdefinitif/',                CODCRM_Stages::RETOUR_DEFINITIF ],
		[ '/^retourexpediteur/',               CODCRM_Stages::RETOUR_EXPEDITEUR ],
		[ '/^retourinteragence/',              CODCRM_Stages::RETOUR_INTER_AGENCE ],
		[ '/^(retour|rtn|return)/',            CODCRM_Stages::RETOUR_DEPOT ],
		[ '/^(transfert|interdepot)/',            CODCRM_Stages::TRANSFERT ],
		[ '/^encoursdelivraison/',             CODCRM_Stages::EN_COURS_LIVRAISON ],
		[ '/^encours$/',                       CODCRM_Stages::EN_COURS_LIVRAISON ],
		[ '/^(rendezvous|report|reporte)/',    CODCRM_Stages::RENDEZ_VOUS ],
		[ '/^nonrecu/',                        CODCRM_Stages::NON_RECUS ],
		[ '/^(anomalie|enanomalie|averifier)/', CODCRM_Stages::EN_ANOMALIE ],
		[ '/^(audepot|audepo|depot)/',         CODCRM_Stages::AU_DEPOT ],
		[ '/^aenlever/',                       CODCRM_Stages::A_ENLEVER ],
		[ '/^(enleve|enleves)/',               CODCRM_Stages::ENLEVES ],
		[ '/^echange/',                        CODCRM_Stages::ECHANGE ],
		[ '/^(supprime|annule)/',              CODCRM_Stages::SUPPRIME ],
		[ '/^enattente/',                      CODCRM_Stages::EN_ATTENTE ],
		[ '/^inconnu/',                        CODCRM_Stages::INCONNU ],
	];

	public static function from_order_status( string $status ): string {
		$status = preg_replace( '/^wc-/', '', $status );
		return self::ORDER_STATUS_MAP[ $status ] ?? '';
	}

	/**
	 * Libellé transporteur → étape ('' si non reconnu).
	 */
	public static function from_carrier_state( string $label ): string {
		$label = self::fix_mojibake( $label );
		$norm  = self::norm( $label );
		if ( '' === $norm ) {
			return '';
		}

		foreach ( self::CARRIER_RULES as $rule ) {
			if ( preg_match( $rule[0], $norm ) ) {
				return (string) apply_filters( 'codcrm_stage_for_carrier_state', $rule[1], $label );
			}
		}

		return (string) apply_filters( 'codcrm_stage_for_carrier_state', '', $label );
	}

	/** Répare « DÃ©pÃ´t » → « Dépôt » (UTF-8 lu comme Latin-1). */
	public static function fix_mojibake( string $s ): string {
		if ( false === strpos( $s, 'Ã' ) && false === strpos( $s, 'Â' ) ) {
			return $s;
		}
		$fixed = @mb_convert_encoding( $s, 'ISO-8859-1', 'UTF-8' );
		return ( is_string( $fixed ) && mb_check_encoding( $fixed, 'UTF-8' ) ) ? $fixed : $s;
	}

	public static function norm( string $s ): string {
		$s = remove_accents( mb_strtolower( trim( $s ), 'UTF-8' ) );
		return (string) preg_replace( '/[^a-z0-9]/', '', $s );
	}
}
