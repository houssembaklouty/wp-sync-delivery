<?php
defined( 'ABSPATH' ) || exit;

/**
 * Tentatives de livraison, recalculées depuis la séquence des étapes
 * transporteur (même règle que ShipFlow : chaque entrée en
 * EN_COURS_LIVRAISON = une tournée = une tentative ; elle est ÉCHOUÉE si
 * l'étape suivante n'est pas LIVRES*).
 */
class CODCRM_Attempts {

	const META_ATTEMPTS = '_codcrm_attempts'; // tournées démarrées
	const META_FAILED   = '_codcrm_failed';   // tentatives échouées
	const META_RISK     = '_codcrm_risk';     // '1' si seuil atteint

	/**
	 * @param string[] $stages Séquence ordonnée d'étapes transporteur.
	 * @return array{attempts:int,failed:int}
	 */
	public static function compute( array $stages ): array {
		$attempts = 0;
		$failed   = 0;
		$in_round = false;

		foreach ( $stages as $stage ) {
			if ( CODCRM_Stages::EN_COURS_LIVRAISON === $stage ) {
				if ( ! $in_round ) {
					$attempts++;
					$in_round = true;
				}
				continue;
			}

			if ( $in_round ) {
				if ( in_array( $stage, [ CODCRM_Stages::LIVRES, CODCRM_Stages::LIVRES_PAYES ], true ) ) {
					$in_round = false; // livré : la tournée a réussi.
				} elseif ( in_array( $stage, CODCRM_Stages::FAILED_AFTER_ROUND, true ) ) {
					$failed++;
					$in_round = false;
				}
				// Autres étapes (TRANSFERT, INCONNU…) : on reste dans la tournée.
			}
		}

		return [ 'attempts' => $attempts, 'failed' => $failed ];
	}

	/** Seuil de tentatives avant risque de retour (défaut 3). */
	public static function threshold(): int {
		return max( 1, (int) apply_filters( 'codcrm_attempts_threshold', (int) get_option( 'codcrm_attempts_threshold', 3 ) ) );
	}

	/**
	 * Recalcule et mémorise les compteurs d'une commande ; déclenche les
	 * actions quand une nouvelle tentative échoue / le seuil est atteint.
	 */
	public static function refresh( WC_Order $order ): array {
		$res = self::compute( CODCRM_Events::carrier_stage_sequence( $order->get_id() ) );

		$old_failed = (int) $order->get_meta( self::META_FAILED );
		$changed    = (int) $order->get_meta( self::META_ATTEMPTS ) !== $res['attempts'] || $old_failed !== $res['failed'];

		if ( $changed ) {
			$order->update_meta_data( self::META_ATTEMPTS, $res['attempts'] );
			$order->update_meta_data( self::META_FAILED, $res['failed'] );
			$at_risk = $res['failed'] >= self::threshold();
			$order->update_meta_data( self::META_RISK, $at_risk ? '1' : '' );
			$order->save_meta_data();

			if ( $res['failed'] > $old_failed ) {
				/**
				 * Une tentative de livraison vient d'échouer.
				 *
				 * @param WC_Order $order  Commande.
				 * @param int      $failed Nombre de tentatives échouées.
				 * @param bool     $at_risk Seuil atteint.
				 */
				do_action( 'codcrm_attempt_failed', $order, $res['failed'], $at_risk );
			}
		}

		return $res;
	}
}
