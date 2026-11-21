<?php
defined( 'ABSPATH' ) || exit;

/**
 * Étapes d'un colis COD. Étapes transporteur = codes ShipFlow (ColisStatus) ;
 * TRANSFERT (transfert inter-dépôts aller) et ANNULEE sont ajoutés.
 */
class CODCRM_Stages {

	// Boutique.
	const COMMANDE     = 'COMMANDE';
	const CONFIRMEE    = 'CONFIRMEE';
	const SYNCHRONISEE = 'SYNCHRONISEE';
	const EMBALLEE     = 'EMBALLEE';
	const ANNULEE      = 'ANNULEE';

	// Transporteur.
	const EN_ATTENTE         = 'EN_ATTENTE';
	const A_ENLEVER          = 'A_ENLEVER';
	const ENLEVES            = 'ENLEVES';
	const AU_DEPOT           = 'AU_DEPOT';
	const TRANSFERT          = 'TRANSFERT';
	const EN_COURS_LIVRAISON = 'EN_COURS_LIVRAISON';
	const RENDEZ_VOUS        = 'RENDEZ_VOUS';
	const NON_RECUS          = 'NON_RECUS';
	const EN_ANOMALIE        = 'EN_ANOMALIE';
	const LIVRES             = 'LIVRES';
	const LIVRES_PAYES       = 'LIVRES_PAYES';
	const RETOUR_DEPOT       = 'RETOUR_DEPOT';
	const RETOUR_INTER_AGENCE = 'RETOUR_INTER_AGENCE';
	const RETOUR_EXPEDITEUR  = 'RETOUR_EXPEDITEUR';
	const RETOUR_DEFINITIF   = 'RETOUR_DEFINITIF';
	const RETOUR_PAYE        = 'RETOUR_PAYE';
	const ECHANGE            = 'ECHANGE';
	const SUPPRIME           = 'SUPPRIME';
	const INCONNU            = 'INCONNU';

	/** Étapes où le colis est en tournée chez un livreur (cf. ROUND_STATUSES de ShipFlow). */
	const ROUND = [ self::EN_COURS_LIVRAISON ];

	/** Étapes d'une tentative de livraison ÉCHOUÉE quand elles suivent EN_COURS_LIVRAISON. */
	const FAILED_AFTER_ROUND = [
		self::AU_DEPOT, self::RENDEZ_VOUS, self::NON_RECUS, self::EN_ANOMALIE,
		self::RETOUR_DEPOT, self::RETOUR_INTER_AGENCE, self::RETOUR_EXPEDITEUR, self::RETOUR_DEFINITIF,
	];

	const TERMINAL = [
		self::LIVRES, self::LIVRES_PAYES, self::RETOUR_DEFINITIF, self::RETOUR_EXPEDITEUR,
		self::RETOUR_PAYE, self::SUPPRIME, self::ANNULEE,
	];

	/** @return array<string,array{label:string,color:string,icon:string}> */
	public static function all(): array {
		return [
			self::COMMANDE            => [ 'label' => __( 'Commande', 'cod-tracker-crm' ),            'color' => '#6c757d', 'icon' => 'cart' ],
			self::CONFIRMEE           => [ 'label' => __( 'Confirmée', 'cod-tracker-crm' ),           'color' => '#2980b9', 'icon' => 'yes' ],
			self::SYNCHRONISEE        => [ 'label' => __( 'Colis créé', 'cod-tracker-crm' ),          'color' => '#16a085', 'icon' => 'update' ],
			self::EMBALLEE            => [ 'label' => __( 'Emballée', 'cod-tracker-crm' ),            'color' => '#8e44ad', 'icon' => 'archive' ],
			self::ANNULEE             => [ 'label' => __( 'Annulée', 'cod-tracker-crm' ),             'color' => '#c0392b', 'icon' => 'dismiss' ],
			self::EN_ATTENTE          => [ 'label' => __( 'En attente (transporteur)', 'cod-tracker-crm' ), 'color' => '#f1a208', 'icon' => 'clock' ],
			self::A_ENLEVER           => [ 'label' => __( 'À enlever', 'cod-tracker-crm' ),           'color' => '#e67e22', 'icon' => 'location' ],
			self::ENLEVES             => [ 'label' => __( 'Enlevé', 'cod-tracker-crm' ),              'color' => '#e67e22', 'icon' => 'migrate' ],
			self::AU_DEPOT            => [ 'label' => __( 'Au dépôt', 'cod-tracker-crm' ),            'color' => '#1f3a7a', 'icon' => 'admin-home' ],
			self::TRANSFERT           => [ 'label' => __( 'Transfert inter-dépôts', 'cod-tracker-crm' ), 'color' => '#0aa5c0', 'icon' => 'randomize' ],
			self::EN_COURS_LIVRAISON  => [ 'label' => __( 'En cours de livraison', 'cod-tracker-crm' ), 'color' => '#0b2a3a', 'icon' => 'car' ],
			self::RENDEZ_VOUS         => [ 'label' => __( 'Reporté / rendez-vous', 'cod-tracker-crm' ), 'color' => '#d35400', 'icon' => 'calendar-alt' ],
			self::NON_RECUS           => [ 'label' => __( 'Non reçu', 'cod-tracker-crm' ),            'color' => '#e74c3c', 'icon' => 'warning' ],
			self::EN_ANOMALIE         => [ 'label' => __( 'En anomalie', 'cod-tracker-crm' ),         'color' => '#e74c3c', 'icon' => 'flag' ],
			self::LIVRES              => [ 'label' => __( 'Livré', 'cod-tracker-crm' ),               'color' => '#00a651', 'icon' => 'yes-alt' ],
			self::LIVRES_PAYES        => [ 'label' => __( 'Livré & payé', 'cod-tracker-crm' ),        'color' => '#00a651', 'icon' => 'money-alt' ],
			self::RETOUR_DEPOT        => [ 'label' => __( 'Retour dépôt', 'cod-tracker-crm' ),        'color' => '#e67e22', 'icon' => 'undo' ],
			self::RETOUR_INTER_AGENCE => [ 'label' => __( 'Retour inter-agence', 'cod-tracker-crm' ), 'color' => '#e67e22', 'icon' => 'undo' ],
			self::RETOUR_EXPEDITEUR   => [ 'label' => __( 'Retour expéditeur', 'cod-tracker-crm' ),   'color' => '#e67e22', 'icon' => 'undo' ],
			self::RETOUR_DEFINITIF    => [ 'label' => __( 'Retour définitif', 'cod-tracker-crm' ),    'color' => '#c0392b', 'icon' => 'undo' ],
			self::RETOUR_PAYE         => [ 'label' => __( 'Retour payé', 'cod-tracker-crm' ),         'color' => '#c0392b', 'icon' => 'money-alt' ],
			self::ECHANGE             => [ 'label' => __( 'Échange', 'cod-tracker-crm' ),             'color' => '#8e44ad', 'icon' => 'update-alt' ],
			self::SUPPRIME            => [ 'label' => __( 'Supprimé', 'cod-tracker-crm' ),            'color' => '#7f8c8d', 'icon' => 'trash' ],
			self::INCONNU             => [ 'label' => __( 'État inconnu', 'cod-tracker-crm' ),        'color' => '#ff6b4a', 'icon' => 'editor-help' ],
		];
	}

	public static function label( string $stage ): string {
		$all = self::all();
		return $all[ $stage ]['label'] ?? $stage;
	}

	public static function color( string $stage ): string {
		$all = self::all();
		return $all[ $stage ]['color'] ?? '#6c757d';
	}

	public static function icon( string $stage ): string {
		$all = self::all();
		return $all[ $stage ]['icon'] ?? 'marker';
	}

	public static function is_terminal( string $stage ): bool {
		return in_array( $stage, self::TERMINAL, true );
	}
}
