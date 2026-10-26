<?php
/**
 * Suivi automatique du colis + mise à jour du statut de commande.
 *
 * Job horaire (Action Scheduler) : interroge le suivi transporteur des
 * commandes synchronisées et met à jour la commande :
 *  - Livré  → statut « Terminée » (completed) + note interne
 *  - Retour → statut « En attente » (on-hold) + note interne
 * Anti-spam : actions UNIQUEMENT aux changements d'état (meta _wcmcs_last_state).
 * Jamais de dégradation : les commandes completed/cancelled/refunded/failed
 * ne sont pas interrogées.
 *
 * @package WC_MultiCarrier_Sync
 */

defined( 'ABSPATH' ) || exit;

class WCMCS_Order_Status_Sync {

	/** Hook du job récurrent (horaire). */
	const HOOK = 'wcmcs_poll_tracking';

	/** Cap par passage (rate-limits transporteurs : Jetpack 30 req/min). */
	const BATCH_CAP = 30;

	/** Option on/off. */
	const OPTION_ENABLED = 'wcmcs_auto_order_status';

	/** Meta du dernier état transporteur connu (anti-spam). */
	const META_LAST_STATE = '_wcmcs_last_state';

	/**
	 * Meta posée quand une transition vient du PRE-ÉTAT livreur (Jetpack) :
	 * la commande reste dans le poll jusqu'à confirmation par l'état
	 * OFFICIEL (clôture du runsheet) — sinon un « Livrer » pré-économé la
	 * retirerait du poll et un retour officiel tardif ne serait jamais vu.
	 */
	const META_AWAITING_OFFICIAL = '_wcmcs_awaiting_official_state';

	/** Curseur de rotation du poll horaire (offset dans les candidates ID ASC). */
	const OPT_POLL_CURSOR = 'wcmcs_poll_cursor';

	public function __construct() {
		add_action( self::HOOK, [ $this, 'poll_batch' ] );

		// Réglage changé → (re)planifier / désplaniller.
		add_action( 'update_option_' . self::OPTION_ENABLED, [ __CLASS__, 'refresh_schedule' ] );
		add_action( 'add_option_' . self::OPTION_ENABLED, [ __CLASS__, 'refresh_schedule' ] );

		// NB : Action Scheduler n'est chargé qu'après plugins_loaded —
		// planifier sur « init » (bootstrap complet) et non au constructeur.
		add_action( 'init', [ $this, 'maybe_schedule' ] );
	}

	/**
	 * Suivi auto activé ? (défaut : oui)
	 */
	public static function is_enabled(): bool {
		return get_option( self::OPTION_ENABLED, 'yes' ) === 'yes';
	}

	// ─────────────────────────────────────────────
	//  Planification (Action Scheduler récurrent)
	// ─────────────────────────────────────────────

	/**
	 * Planifie le job s'il ne l'est pas (hook init — AS chargé).
	 */
	public function maybe_schedule(): void {
		if ( ! self::scheduler_ready() || ! self::is_enabled() ) {
			return;
		}
		if ( ! as_next_scheduled_action( self::HOOK, [], 'wcmcs' ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::HOOK, [], 'wcmcs' );
		}
	}

	/**
	 * (Re)planifie ou désplanille selon le réglage.
	 */
	public static function refresh_schedule(): void {
		if ( ! self::scheduler_ready() ) {
			return;
		}
		$scheduled = as_next_scheduled_action( self::HOOK, [], 'wcmcs' );
		if ( self::is_enabled() && ! $scheduled ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::HOOK, [], 'wcmcs' );
		} elseif ( ! self::is_enabled() && $scheduled ) {
			as_unschedule_action( self::HOOK, [], 'wcmcs' );
		}
	}

	/**
	 * Désactivation du plugin : purge du job.
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( self::HOOK, [], 'wcmcs' );
		}
	}

	private static function scheduler_ready(): bool {
		return WCMCS_WC_Compat::has_action_scheduler()
			&& function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_next_scheduled_action' );
	}

	// ─────────────────────────────────────────────
	//  Batch horaire
	// ─────────────────────────────────────────────

	/**
	 * Interroge le suivi des commandes éligibles et met à jour les statuts.
	 */
	public function poll_batch(): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		$orders  = $this->get_candidate_orders();
		$checked = 0;
		$updated = 0;
		$errors  = 0;

		foreach ( $orders as $order ) {
			try {
				$carrier_slug = WCMCS_Order_Meta::get_carrier_slug( $order );
				$provider = WCMCS_Carrier_Detector::get_provider_by_slug( $carrier_slug );
				if ( ! $provider || ! $provider->is_active() || ! $provider->is_configured() ) {
					continue;
				}

				// Matrice CARRIER_FEATURES : les transporteurs sans suivi
				// (ex. Navex — endpoint non documenté) ne sont JAMAIS interrogés
				// (leur get_tracking_status est un WP_Error systématique :
				// chaque passage horaire comptait une erreur parasite).
				$features = WCMCS_Carrier_Detector::get_carrier_features( $carrier_slug );
				if ( empty( $features['suivi'] ) ) {
					continue;
				}

				$tracking_id = WCMCS_Order_Meta::get_tracking_id( $order );
				if ( '' === $tracking_id ) {
					continue;
				}

				$checked++;
				$status = $provider->get_tracking_status( $tracking_id );
				if ( is_wp_error( $status ) ) {
					$errors++;
					continue;
				}
				if ( $this->process_tracking_result( $order, $provider, $status ) ) {
					$updated++;
				}
			} catch ( \Throwable $e ) {
				$errors++;
				WCMCS_Logger::error( 'Suivi auto : exception commande #' . $order->get_id() . ' — ' . $e->getMessage() );
			}
		}

		WCMCS_Logger::info( sprintf(
			'Suivi auto : %d vérifiée(s), %d mise(s) à jour, %d erreur(s) API.',
			$checked,
			$updated,
			$errors
		) );
	}

	/**
	 * Commandes éligibles au polling : synchronisées + tracking + statut WC
	 * non terminal (jamais completed/cancelled/refunded/failed).
	 *
	 * Transporteurs sans suivi (matrice CARRIER_FEATURES — ex. Navex)
	 * exclus DÈS LA REQUÊTE : sinon ils consomment le quota horaire et
	 * affament les autres. Rotation par curseur d'offset : chaque passage
	 * traite le LOT SUIVANT (ID ASC) — fini la famine des 30 plus anciennes
	 * candidates interrogées à vie (revue de code 2.10.0).
	 *
	 * @return WC_Order[]
	 */
	private function get_candidate_orders(): array {
		$no_tracking = [];
		foreach ( array_keys( WCMCS_Carrier_Detector::get_all_providers() ) as $slug ) {
			$features = WCMCS_Carrier_Detector::get_carrier_features( $slug );
			if ( empty( $features['suivi'] ) ) {
				$no_tracking[] = $slug;
			}
		}

		$args = [
			'status'  => apply_filters( 'wcmcs_tracking_poll_statuses', [ 'processing', 'on-hold', 'pending' ] ),
			'orderby' => 'ID',
			'order'   => 'ASC',
			'meta_query' => [
				[
					'key'   => WCMCS_Order_Meta::META_STATUS,
					'value' => WCMCS_Order_Meta::STATUS_SYNCED,
				],
				[
					'key'     => WCMCS_Order_Meta::META_TRACKING_ID,
					'compare' => 'EXISTS',
				],
			],
		];
		if ( ! empty( $no_tracking ) ) {
			$args['meta_query'][] = [
				'key'     => WCMCS_Order_Meta::META_CARRIER_SLUG,
				'value'   => $no_tracking,
				'compare' => 'NOT IN',
			];
		}

		// Rotation : offset = position du prochain lot dans la liste (ID ASC).
		$cursor = (int) get_option( self::OPT_POLL_CURSOR, 0 );
		$orders = wc_get_orders( $args + [ 'limit' => self::BATCH_CAP, 'offset' => $cursor ] );
		$orders = is_array( $orders ) ? $orders : [];

		if ( empty( $orders ) && $cursor > 0 ) {
			// Fin de cycle → reprise au début de la liste.
			$cursor = 0;
			$orders = wc_get_orders( $args + [ 'limit' => self::BATCH_CAP, 'offset' => 0 ] );
			$orders = is_array( $orders ) ? $orders : [];
		}

		// Lot complet → avancer le curseur ; lot partiel/vide → cycle terminé,
		// reprise au début au prochain passage.
		update_option(
			self::OPT_POLL_CURSOR,
			( $cursor + count( $orders ) ),
			false
		);

		// Commandes passées « Livrée » via PRE-ÉTAT livreur (Jetpack) : le
		// drapeau _wcmcs_awaiting_official_state les maintient dans le poll
		// jusqu'à confirmation par l'état OFFICIEL — sinon le statut livré
		// les retire et un retour officiel tardif n'est jamais vu.
		$delivered_target = class_exists( 'WCMCS_Order_Statuses' ) ? WCMCS_Order_Statuses::delivered_target() : 'wc-wcmcs-delivered';
		$target_nu        = (string) preg_replace( '/^wc-/', '', $delivered_target );
		if ( ! in_array( $target_nu, [ 'completed', 'cancelled', 'refunded', 'failed', 'on-hold', 'processing', 'pending' ], true ) ) {
			$awaiting = wc_get_orders( [
				'status'  => [ $delivered_target ],
				'orderby' => 'ID',
				'order'   => 'ASC',
				'limit'   => self::BATCH_CAP,
				'meta_query' => array_merge( $args['meta_query'], [
					[
						'key'   => self::META_AWAITING_OFFICIAL,
						'value' => '1',
					],
				] ),
			] );
			if ( is_array( $awaiting ) && ! empty( $awaiting ) ) {
				$orders = array_merge( $orders, $awaiting );
			}
		}

		return $orders;
	}

	/**
	 * Commandes synchronisées dont le suivi peut être interrogé (batch horaire
	 * OU vérification manuelle via la page commandes). Statuts WC élargis aux
	 * statuts custom non terminaux (« Téléchargée », « Retournée ») via le
	 * filtre poll_statuses.
	 *
	 * @param int         $limit    0 / -1 = illimité.
	 * @param array|null  $statuses Liste explicite (scan manuel multi-statuts) ;
	 *                              null = filtre poll_statuses (cron).
	 * @return WC_Order[]
	 */
	public function get_scan_candidates( int $limit = 0, ?array $statuses = null ): array {
		if ( null === $statuses || [] === $statuses ) {
			$statuses = apply_filters( 'wcmcs_tracking_poll_statuses', [ 'processing', 'on-hold', 'pending' ] );
		}

		$orders = wc_get_orders( [
			'status' => $statuses,
			'limit'  => $limit > 0 ? $limit : -1,
			'orderby' => 'ID',
			'order'  => 'ASC',
			'meta_query' => [
				[
					'key'   => WCMCS_Order_Meta::META_STATUS,
					'value' => WCMCS_Order_Meta::STATUS_SYNCED,
				],
				[
					'key'     => WCMCS_Order_Meta::META_TRACKING_ID,
					'compare' => 'EXISTS',
				],
			],
		] );

		return is_array( $orders ) ? $orders : [];
	}

	// ─────────────────────────────────────────────
	//  Traitement d'un résultat de suivi
	// ─────────────────────────────────────────────

	/**
	 * Catégorie d'un état transporteur connu (libellé mémorisé ou code brut) :
	 * unknown | transit | delivered | returned. Sert au scan manuel (badges +
	 * exclusion par défaut des colis déjà livrés/retournés).
	 *
	 * @param string $slug        Slug transporteur.
	 * @param string $state_label Libellé / code état stocké en meta.
	 * @return string
	 */
	public function classify_state( string $slug, string $state_label ): string {
		if ( '' === trim( $state_label ) ) {
			return 'unknown';
		}

		$category = $this->categorize( $slug, $state_label, $state_label );
		if ( in_array( $category, [ 'delivered', 'returned' ], true ) ) {
			return $category;
		}

		$n = $this->norm( $state_label );
		if ( '' === $n || false !== strpos( $n, 'annul' ) ) {
			return 'unknown';
		}
		if ( preg_match( '/^(livre|livrer|livree|delivered|delivr|delivree|delivrer)/', $n ) ) {
			return 'delivered';
		}
		if ( preg_match( '/^(rtn|retour|ret)/', $n ) ) {
			return 'returned';
		}

		return 'transit';
	}


	/**
	 * Catégorise l'état, mémorise et agit UNIQUEMENT au changement d'état.
	 *
	 * @param WC_Order $order    Commande.
	 * @param object   $provider Transporteur (WCMCS_ShippingProviderInterface).
	 * @param array    $status   Résultat get_tracking_status().
	 * @return bool  True si un changement d'état a déclenché une action (statut/note).
	 */
	public function process_tracking_result( WC_Order $order, $provider, array $status ): bool {
		$slug  = $provider->get_slug();
		$label = $provider->get_label();

		$state_code  = (string) ( $status['state_code'] ?? '' );
		$state_label = (string) ( $status['state_label'] ?? $state_code );
		$pre_etat    = 'jetpack' === $slug ? trim( (string) ( $status['pre_etat'] ?? '' ) ) : '';

		// NB : sans état officiel MAIS avec un pre-état livreur (Jetpack —
		// l'API peut omettre etat pendant la tournée), on continue sur le
		// pre-état (revue de code 2.10.0 : l'ancien early-return jetait
		// le résultat et neutralisait la détection temps réel).
		if ( '' === $state_code && '' === $state_label && '' === $pre_etat ) {
			return false;
		}

		$state_key = '' !== $state_code ? $state_code : $state_label;

		// Statuts terminaux WC (Terminée = payée en ligne / clôturée,
		// Annulée, Remboursée, Échouée) : jamais de transition — mémo de
		// l'état transporteur seul (« Jamais de dégradation ») ; la
		// correction ne vise que les commandes encore en jeu.
		if ( in_array( $order->get_status(), [ 'completed', 'cancelled', 'refunded', 'failed' ], true ) ) {
			$last = (string) WCMCS_WC_Compat::get_order_meta( $order, self::META_LAST_STATE );
			if ( $last !== $state_key ) {
				WCMCS_WC_Compat::update_order_meta( $order, self::META_LAST_STATE, $state_key, true );
			}
			return false;
		}

		// Catégorie transporteur + règle transit FirstDelivery :
		// colis « En attente » / « En cours » (codes 0/1, pas encore livré)
		// → commande « En attente », uniquement si la commande est encore
		// « En cours » (file de suivi de l'agent ; une commande déjà passée
		// en pipeline — Téléchargée etc. — n'est pas rabaisée).
		$category = $this->categorize( $slug, $state_code, $state_label );

		// Jetpack pre-état : info TEMPS RÉEL du livreur pendant la tournée
		// (doc Jetpack §4.4) — l'état officiel reste « En cours » jusqu'à la
		// clôture du runsheet à l'agence ; on agit dès le pré-état (Livrer /
		// Retour) au lieu d'attendre la clôture, potentiellement le lendemain.
		// Utilisé aussi quand l'état officiel est VIDE (voir early-return).
		$pre_state_label = '';
		if ( 'jetpack' === $slug && '' === $category ) {
			$pre_norm  = $this->norm( $pre_etat );
			if ( ! in_array( $pre_norm, [ '', 'encours', 'inconnu' ], true ) ) {
				$pre_state_label = $pre_etat;
				$category        = $this->categorize( $slug, $pre_etat, $pre_etat );
			}
		}

		// Transition issue du PRE-ÉTAT (pas encore confirmée par l'état
		// officiel) : poser le drapeau — la commande RESTE dans le poll
		// jusqu'à confirmation officielle (clôture du runsheet). Sans cela,
		// un « Livrer » pré-économé la retirait du poll et un retour
		// OFFICIEL tardif (client refusé après le scan terrain) n'était
		// jamais vu — commande figée « Livrée » à vie (revue 2.10.0).
		if ( '' !== $pre_state_label && '' !== $category ) {
			WCMCS_WC_Compat::update_order_meta( $order, self::META_AWAITING_OFFICIAL, '1', true );
		}

		// État OFFICIEL définitif (livré/retour confirmé, pas le pre-état) :
		// lever le drapeau — la confirmation a eu lieu.
		if ( '' === $pre_state_label && in_array( $category, [ 'delivered', 'returned' ], true )
			&& '1' === (string) WCMCS_WC_Compat::get_order_meta( $order, self::META_AWAITING_OFFICIAL ) ) {
			WCMCS_WC_Compat::update_order_meta( $order, self::META_AWAITING_OFFICIAL, '', true );
		}

		$transit_to_hold = (
			'' === $category
			&& 'firstdelivery' === $slug
			&& 'processing' === $order->get_status()
			&& in_array(
				$this->norm( '' !== $state_label ? $state_label : $state_code ),
				[ 'enattente', 'encours' ],
				true
			)
		);

		// Statut cible : catégorie transporteur (livré/retour) ou transit
		// FirstDelivery. Correction : on agit dès que la commande n'est pas
		// au statut cible, même si l'état transporteur est inchangé (une
		// commande « En cours » + retour déjà connu restée au mauvais statut
		// est corrigée au scan suivant) ; l'anti-spam ne sert plus qu'à
		// éviter les notes en double.
		$target = '';
		if ( 'delivered' === $category ) {
			$target = class_exists( 'WCMCS_Order_Statuses' ) ? WCMCS_Order_Statuses::delivered_target() : 'completed';
		} elseif ( 'returned' === $category ) {
			$target = class_exists( 'WCMCS_Order_Statuses' ) ? WCMCS_Order_Statuses::returned_target() : 'on-hold';
		} elseif ( $transit_to_hold ) {
			$target = 'on-hold';
		}

		if ( '' === $target ) {
			// Autres états (Enlevé, Au dépôt, Échange…) : anti-spam puis mémo
			// du nouvel état sans note.
			$last = (string) WCMCS_WC_Compat::get_order_meta( $order, self::META_LAST_STATE );
			if ( $last === $state_key ) {
				return false;
			}
			WCMCS_WC_Compat::update_order_meta( $order, self::META_LAST_STATE, $state_key, true );
			return false;
		}

		// Déjà au statut cible : rien à faire — peu importe que l'état
		// transporteur soit nouveau (pas de note en double).
		$target_nu = (string) preg_replace( '/^wc-/', '', $target );
		if ( $order->get_status() === $target_nu ) {
			return false;
		}

		$target_label = class_exists( 'WCMCS_Order_Statuses' )
			? WCMCS_Order_Statuses::wc_status_label( 'wc-' === substr( $target, 0, 3 ) ? $target : 'wc-' . $target )
			: ( wc_get_order_statuses()[ 'wc-' . $target ] ?? $target );
		$state_text = $state_label !== '' ? $state_label : $state_key;

		// Pré-état livreur Jetpack : mention explicite dans la note — la
		// clôture du runsheet confirmera l'état officiel.
		if ( '' !== $pre_state_label ) {
			$state_text .= ' — ' . sprintf(
				/* translators: %s : pré-état livreur (temps réel) */
				__( 'pré-état livreur : %s (clôture runsheet en attente)', 'wc-multicarrier-sync' ),
				$pre_state_label
			);
		}

		if ( 'delivered' === $category ) {
			$order->update_status(
				$target,
				sprintf(
					/* translators: 1: transporteur, 2: état transporteur, 3: libellé statut */
					__( '[WCMCS] Livré chez %1$s (%2$s) — statut commande : « %3$s ».', 'wc-multicarrier-sync' ),
					$label,
					$state_text,
					$target_label
				)
			);
			WCMCS_Logger::info( sprintf( 'Suivi auto : commande #%d LIVRÉE chez %s → %s.', $order->get_id(), $slug, $target ) );
		} elseif ( 'returned' === $category ) {
			$order->update_status(
				$target,
				sprintf(
					/* translators: 1: transporteur, 2: état transporteur, 3: libellé statut */
					__( '[WCMCS] Retour chez %1$s (%2$s) — statut commande : « %3$s ».', 'wc-multicarrier-sync' ),
					$label,
					$state_text,
					$target_label
				)
			);
			WCMCS_Logger::info( sprintf( 'Suivi auto : commande #%d RETOUR chez %s → %s.', $order->get_id(), $slug, $target ) );
		} else {
			// Transit → En attente (règle FirstDelivery ci-dessus).
			$order->update_status(
				$target,
				sprintf(
					/* translators: 1: transporteur, 2: état transporteur, 3: libellé statut */
					__( '[WCMCS] Colis encore chez %1$s (« %2$s ») — statut commande : « %3$s ».', 'wc-multicarrier-sync' ),
					$label,
					$state_text,
					$target_label
				)
			);
			WCMCS_Logger::info( sprintf( 'Suivi auto : commande #%d EN ATTENTE chez %s (« %s ») → %s.', $order->get_id(), $slug, $state_text, $target ) );
		}

		WCMCS_WC_Compat::update_order_meta( $order, self::META_LAST_STATE, $state_key, true );
		return true;
	}

	// ─────────────────────────────────────────────
	//  Catégorisation des états par transporteur
	// ─────────────────────────────────────────────

	/**
	 * Catégorise un état transporteur : 'delivered' | 'returned' | '' (autre).
	 */
	private function categorize( string $slug, string $state_code, string $state_label ): string {
		switch ( $slug ) {
			case 'adex':
				if ( '5' === $state_code ) {
					return 'delivered';
				}
				if ( '6' === $state_code ) {
					return 'returned';
				}
				return '';

			case 'firstdelivery':
				// Codes numériques : 2 = Livré · 5,7,8,11,30 + 201-203 = retours
				// (7 = Rtn client/agence, 8 = Au magasin = retour au dépôt,
				// 204 = retour annulé → pas d'action). Certains comptes renvoient
				// l'état en TEXTE (« Livré », « Rtn client/agence »…) : recherche
				// inverse dans STATUS_MAP puis filet par préfixe normalisé.
				$return_codes = [ '5', '7', '8', '11', '30', '31', '201', '202', '203' ];
				if ( '2' === $state_code ) {
					return 'delivered';
				}
				if ( in_array( $state_code, $return_codes, true ) ) {
					return 'returned';
				}
				$text_state = '' !== $state_code ? $state_code : $state_label;
				if ( class_exists( 'WCMCS_FirstDelivery_Provider' ) && '' !== $text_state ) {
					$lower = mb_strtolower( $text_state );
					foreach ( WCMCS_FirstDelivery_Provider::STATUS_MAP as $mk => $mv ) {
						if ( (string) $mv !== '' && mb_strtolower( (string) $mv ) === $lower ) {
							$mk = (string) $mk;
							if ( '2' === $mk ) {
								return 'delivered';
							}
							if ( in_array( $mk, $return_codes, true ) ) {
								return 'returned';
							}
							break;
						}
					}
				}
				$norm = $this->norm( $state_label !== '' ? $state_label : $state_code );
				if ( '' === $norm || preg_match( '/annul/', $norm ) ) {
					return '';
				}
				if ( preg_match( '/^livre/', $norm ) ) {
					return 'delivered';
				}
				if ( preg_match( '/^(rtn|retour)/', $norm ) ) {
					return 'returned';
				}
				if ( preg_match( '/^aumagasin/', $norm ) ) {
					return 'returned';
				}
				return '';

		case 'jetpack':
			// Doc §4.4 : « Livrer » = livré · retours = Rtn*/Retour* ET
			// « A verifier » (figure dans la liste officielle des états de
			// retour, doc §4.4). Le pre-etat temps réel livreur est géré en
			// amont dans process_tracking_result().
			$norm = $this->norm( $state_label );
			if ( 'livrer' === $norm ) {
				return 'delivered';
			}
			if ( preg_match( '/^(rtn|retour)/', $norm ) || 'averifier' === $norm ) {
				return 'returned';
			}
			return '';

		case 'fiabilo':
			// Doc Fiabilo : Livré / Livrer paye = livré · retours = Retour*,
			// client/agence ET Non reçu, Supprimé, Au magasin (retour au dépôt,
			// aligné sur FirstDelivery), À vérifier (état de retour).
			$norm = $this->norm( $state_label );
			if ( in_array( $norm, [ 'livre', 'livrerpaye' ], true ) ) {
				return 'delivered';
			}
			if ( preg_match( '/^(retour|clientagence|rtn)/', $norm )
				|| in_array( $norm, [ 'nonrecu', 'supprime', 'aumagasin', 'averifier' ], true ) ) {
				return 'returned';
			}
			return '';

		case 'intigo':
			// Doc INTIGO : 18 statuts de livraison avec valeurs numériques ET
			// libellés FR, exemples de réponses en anglais (« Delivered »).
			// Matching triple : valeur numérique · libellé FR normalisé ·
			// libellé anglais. 6 = Commande livrée. Retours (états finaux →
			// commande « Retournée ») : 8 (declined by admin), 12 (Adresse non
			// exacte), 14 (Annulée par vendeur), 15 (Retour provisoire),
			// 16 (Retour définitif), 17 (Retour vendeur), 19 (Retour depuis
			// point relais), 21 (Colis perdu), 24 (Retour vers dépôt).
			$intigo_return_codes = [ '8', '12', '14', '15', '16', '17', '19', '21', '24' ];
			if ( '6' === $state_code ) {
				return 'delivered';
			}
			if ( in_array( $state_code, $intigo_return_codes, true ) ) {
				return 'returned';
			}
			$norm = $this->norm( $state_label );
			if ( preg_match( '/^(commandelivree|delivered)/', $norm ) ) {
				return 'delivered';
			}
			if ( preg_match( '/^(retour|return|annulee|annule|cancel|lost|perdu|declined|adresse)/', $norm ) ) {
				return 'returned';
			}
			return '';

		case 'afex':
			// OpenAPI AFEX (23 états) : delivered = Livré · returned/canceled/
			// not_recovered/anomaly = retours · ET états TERMINAUX recovered
			// (récupération/échange terminé), exception_refunded,
			// exception_not_refunded (issues d'exception) — sans action, ces
			// commandes resteraient « En cours » indéfiniment.
			if ( 'delivered' === $state_code ) {
				return 'delivered';
			}
			if ( in_array( $state_code, [ 'returned', 'canceled', 'not_recovered', 'anomaly', 'recovered', 'exception_refunded', 'exception_not_refunded' ], true ) ) {
				return 'returned';
			}
			return '';

		case 'shipper':
			// Statuts Shipper Network : aucune nomenclature officielle publiée
			// (plateforme dropshipping — libellés anglais probables).
			// Matching ANCRÉ EN + FR (le cas générique ne matche que le FR).
			// NB : /^(deliver|livr) matchait « Delivering » (colis EN TRANSIT
			// catégorisé livré) — ancre resserrée sur les états finis
			// (revue de code 2.10.0).
			$norm = $this->norm( $state_label );
			if ( preg_match( '/^(delivered|livre|livrer)/', $norm ) ) {
				return 'delivered';
			}
			if ( preg_match( '/^(return|refund|cancel|retour|rtn)/', $norm ) ) {
				return 'returned';
			}
			return '';

		case 'aramex':
			// Codes Aramex : SH113 = Delivered · SH005/SH006 = Returned + ProblemCode.
			if ( 'SH113' === $state_code ) {
				return 'delivered';
			}
			if ( in_array( $state_code, [ 'SH005', 'SH006', 'SH531' ], true ) ) {
				return 'returned';
			}
			// Fallback par libellé (ancré — revue de code 2.10.0).
			$norm = $this->norm( $state_label );
			if ( preg_match( '/^deliveredtol?/', $norm ) ) {
				return 'delivered';
			}
			if ( preg_match( '/^return/', $norm ) ) {
				return 'returned';
			}
			return '';

		case 'ciblex':
			// Libellés libres ('.GetOrder' status — à confirmer avec l'accès réel) :
			// heuristique latin ANCRÉE (revue 2.10.0 : /livr|deliver/ non ancré
			// matchait « En livraison », « Livraison retour »…) + états arabes
			// (norm() retire l'arabe avec [^a-z0-9] ; strpos PHP 7.4-compatible).
			$norm = $this->norm( $state_label );
			$raw  = mb_strtolower( trim( $state_label ), 'UTF-8' );
			if ( preg_match( '/^(livre|livrer|livree|delivered|deliver)/', $norm ) || ( strpos( $raw, 'توصيل' ) !== false ) ) {
				return 'delivered';
			}
			if ( preg_match( '/^(retou|rtn|return)/', $norm ) || ( strpos( $raw, 'إرجاع' ) !== false ) || ( strpos( $raw, 'مرتجع' ) !== false ) ) {
				return 'returned';
			}
			return '';

		case 'colissimo':
		default:
			// Libellés inconnus : heuristique ancrée. NB : ^livre (et non ^livr)
			// — « Livraison en cours » / « Livraison programmée » commencent
			// par livr- mais pas livre- (revue de code 2.10.0).
			$norm = $this->norm( $state_label );
			if ( preg_match( '/^livre/', $norm ) ) {
				return 'delivered';
			}
			if ( preg_match( '/^(retour|rtn)/', $norm ) ) {
				return 'returned';
			}
			return '';
		}
	}

	/**
	 * Normalisation : minuscules, sans accents, alphanumérique.
	 */
	private function norm( string $s ): string {
		$s = remove_accents( mb_strtolower( trim( $s ), 'UTF-8' ) );
		$s = preg_replace( '/[^a-z0-9]/', '', $s );
		return (string) $s;
	}
}
