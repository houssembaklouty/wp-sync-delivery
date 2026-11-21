<?php
defined( 'ABSPATH' ) || exit;

/**
 * Rapprochement du cash COD : montant attendu (commande) ↔ encaissé par le
 * transporteur ↔ reversé (relevé du transporteur).
 */
class CODCRM_Cash {

	const PENDING   = 'pending';   // colis en cours : rien à encaisser encore.
	const COLLECTED = 'collected'; // livré : le transporteur a encaissé, reversement attendu.
	const PAID_OUT  = 'paid_out';  // reversé (relevé rapproché ou marqué payé).
	const DISPUTED  = 'disputed';  // écart de montant ou paiement inattendu.
	const RETURNED  = 'returned';  // colis retourné : rien à encaisser.
	const CANCELLED = 'cancelled';

	public static function init(): void {
		add_action( 'wcmcs_order_synced', [ __CLASS__, 'on_synced' ], 30, 1 );
		add_action( 'codcrm_stage_changed', [ __CLASS__, 'on_stage_changed' ], 20, 3 );
	}

	public static function statuses(): array {
		return [
			self::PENDING   => [ __( 'En cours', 'cod-tracker-crm' ), '#e67e22' ],
			self::COLLECTED => [ __( 'À reverser', 'cod-tracker-crm' ), '#2271b1' ],
			self::PAID_OUT  => [ __( 'Reversé', 'cod-tracker-crm' ), '#00a651' ],
			self::DISPUTED  => [ __( 'Litige', 'cod-tracker-crm' ), '#c0392b' ],
			self::RETURNED  => [ __( 'Retourné', 'cod-tracker-crm' ), '#7f8c8d' ],
			self::CANCELLED => [ __( 'Annulé', 'cod-tracker-crm' ), '#7f8c8d' ],
		];
	}

	public static function money( $v ): string {
		return null === $v || '' === $v ? '—' : number_format_i18n( (float) $v, 3 ) . ' DT';
	}

	/** Montant « 37,000 » / « 37.000 » / « 1 037,500 » / « 1,037.500 » → float. */
	public static function parse_amount( string $s ): ?float {
		$s = trim( str_replace( [ "\xC2\xA0", ' ', 'DT', 'dt', 'TND' ], '', $s ) );
		if ( '' === $s || ! preg_match( '/^-?[\d.,]+$/', $s ) ) {
			return null;
		}
		$c = strrpos( $s, ',' );
		$d = strrpos( $s, '.' );
		if ( false !== $c && false !== $d ) {
			// Le dernier séparateur est la décimale ; l'autre sépare les milliers.
			$s = $c > $d ? str_replace( [ '.', ',' ], [ '', '.' ], $s ) : str_replace( ',', '', $s );
		} elseif ( false !== $c ) {
			$s = str_replace( ',', '.', $s );
		}
		return is_numeric( $s ) ? round( (float) $s, 3 ) : null;
	}

	// ── Lecture ────────────────────────────────────────────────

	public static function get( int $order_id ): ?object {
		global $wpdb;
		$t = CODCRM_Installer::table_cash();
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE order_id = %d", $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $r ?: null;
	}

	public static function get_by_tracking( string $tracking ): ?object {
		global $wpdb;
		$t = CODCRM_Installer::table_cash();
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE tracking = %s LIMIT 1", $tracking ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $r ?: null;
	}

	// ── Cycle de vie ───────────────────────────────────────────

	/** Crée la ligne cash d'une commande (idempotent). */
	public static function ensure( WC_Order $order ): ?object {
		$row = self::get( $order->get_id() );
		if ( $row ) {
			return $row;
		}
		$tracking = (string) $order->get_meta( '_wcmcs_tracking_id' );
		if ( '' === $tracking ) {
			return null;
		}
		global $wpdb;
		$wpdb->insert( CODCRM_Installer::table_cash(), [
			'order_id'     => $order->get_id(),
			'carrier_slug' => (string) $order->get_meta( '_wcmcs_carrier_slug' ),
			'tracking'     => substr( $tracking, 0, 64 ),
			'expected'     => round( (float) $order->get_total(), 3 ),
			'status'       => self::PENDING,
			'created_at'   => current_time( 'mysql' ),
		], [ '%d', '%s', '%s', '%f', '%s', '%s' ] );
		return self::get( $order->get_id() );
	}

	public static function on_synced( $order ): void {
		if ( $order instanceof WC_Order ) {
			self::ensure( $order );
		}
	}

	public static function on_stage_changed( $order, $old, $new ): void {
		if ( $order instanceof WC_Order ) {
			self::apply_stage( $order, (string) $new );
		}
	}

	/** Statut cash déduit de l'étape transporteur ('' = pas de changement). */
	public static function status_for_stage( string $stage ): string {
		if ( CODCRM_Stages::LIVRES_PAYES === $stage ) {
			return self::PAID_OUT;
		}
		if ( CODCRM_Stages::LIVRES === $stage ) {
			return self::COLLECTED;
		}
		if ( 0 === strpos( $stage, 'RETOUR_' ) ) {
			return self::RETURNED;
		}
		if ( in_array( $stage, [ CODCRM_Stages::SUPPRIME, CODCRM_Stages::ANNULEE ], true ) ) {
			return self::CANCELLED;
		}
		return '';
	}

	/**
	 * Aligne le statut cash sur l'étape transporteur. Un litige ou un
	 * reversement déjà acté n'est jamais écrasé automatiquement.
	 */
	public static function apply_stage( WC_Order $order, string $stage, string $at = '' ): void {
		$row = self::ensure( $order );
		if ( ! $row ) {
			return;
		}
		$target = self::status_for_stage( $stage );
		if ( '' === $target || $target === $row->status ) {
			return;
		}
		if ( in_array( $row->status, [ self::DISPUTED, self::PAID_OUT ], true ) && self::PAID_OUT !== $target ) {
			return;
		}
		if ( self::PAID_OUT === $row->status ) {
			return;
		}

		$extra = [];
		if ( self::COLLECTED === $target ) {
			$extra['collected_at'] = '' !== $at ? $at : current_time( 'mysql' );
		}
		self::set_status( (int) $row->order_id, $target, 0, __( 'Mis à jour depuis le suivi transporteur', 'cod-tracker-crm' ), $extra, 'system' );
	}

	/**
	 * Change le statut cash et trace l'événement.
	 *
	 * @param array $extra Colonnes supplémentaires (collected, fee, statement_ref, paid_at, collected_at, note).
	 */
	public static function set_status( int $order_id, string $status, int $actor_id, string $note = '', array $extra = [], string $source = 'agent' ): bool {
		global $wpdb;
		if ( ! isset( self::statuses()[ $status ] ) ) {
			return false;
		}
		$row = self::get( $order_id );
		if ( ! $row ) {
			return false;
		}

		$data    = [ 'status' => $status ];
		$formats = [ '%s' ];
		foreach ( [ 'collected' => '%f', 'fee' => '%f', 'statement_ref' => '%s', 'paid_at' => '%s', 'collected_at' => '%s' ] as $col => $fmt ) {
			if ( array_key_exists( $col, $extra ) ) {
				$data[ $col ] = $extra[ $col ];
				$formats[]    = $fmt;
			}
		}
		if ( '' !== $note ) {
			$data['note'] = substr( sanitize_text_field( $note ), 0, 255 );
			$formats[]    = '%s';
		}
		$wpdb->update( CODCRM_Installer::table_cash(), $data, [ 'order_id' => $order_id ], $formats, [ '%d' ] );

		CODCRM_Events::add( $order_id, [
			'type'     => 'cash',
			'outcome'  => $status,
			'note'     => sprintf( '%s → %s%s', self::statuses()[ $row->status ][0] ?? $row->status, self::statuses()[ $status ][0], '' !== $note ? ' — ' . $note : '' ),
			'actor_id' => $actor_id,
			'source'   => $source,
			'payload'  => [ 'from' => $row->status, 'to' => $status ],
		] );

		if ( self::DISPUTED === $status && self::DISPUTED !== $row->status ) {
			$order = wc_get_order( $order_id );
			CODCRM_Tasks::create( $order_id, $order ? CODCRM_Agents::agent_of( $order ) : 0, CODCRM_Tasks::KIND_CASH, current_time( 'mysql' ), '' !== $note ? $note : __( 'Écart de cash à vérifier', 'cod-tracker-crm' ), $actor_id );
		}
		return true;
	}

	// ── Relevé du transporteur (CSV) ───────────────────────────

	/** Colonne reconnue d'après son en-tête (tolérant : « N° relevé », « Montant encaissé »…). */
	private static function header_key( string $h ): string {
		$n = CODCRM_Stage_Mapper::norm( $h );
		if ( '' === $n ) {
			return '';
		}
		// L'ordre compte : « frais livraison » est des frais, pas un montant ni un code.
		$rules = [
			'fee'      => [ 'frais', 'fee', 'fraisport' ],
			'ref'      => [ 'releve', 'bordereau', 'virement', 'paiement', 'refpaiement' ],
			'amount'   => [ 'montant', 'amount', 'encaisse', 'crbt', 'prix', 'total' ],
			'tracking' => [ 'codebarre', 'barcode', 'tracking', 'suivi', 'colis' ],
		];
		foreach ( $rules as $key => $needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $n, $needle ) ) {
					return $key;
				}
			}
		}
		// « code » / « cod » seuls.
		if ( 'code' === $n ) {
			return 'tracking';
		}
		if ( 'cod' === $n ) {
			return 'amount';
		}
		return '';
	}

	/**
	 * Lit un CSV de relevé. En-têtes reconnus (insensibles à la casse/aux accents) :
	 * code/tracking, montant, frais, relevé/référence. Séparateur , ; ou tabulation.
	 *
	 * @return array{rows:array<int,array>,error:string}
	 */
	public static function parse_statement( string $path ): array {
		$fh = @fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return [ 'rows' => [], 'error' => __( 'Fichier illisible.', 'cod-tracker-crm' ) ];
		}
		$first = (string) fgets( $fh );
		rewind( $fh );
		$first = preg_replace( '/^\xEF\xBB\xBF/', '', $first );
		$delim = ';';
		foreach ( [ ';', "\t", ',' ] as $d ) {
			if ( substr_count( $first, $d ) > substr_count( $first, $delim ) ) {
				$delim = $d;
			}
		}

		$cols = [];
		$rows = [];
		$line = 0;
		while ( ( $r = fgetcsv( $fh, 4096, $delim ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$line++;
			if ( 1 === $line ) {
				$r[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $r[0] );
				foreach ( $r as $i => $h ) {
					$k = self::header_key( (string) $h );
					if ( $k && ! isset( $cols[ $k ] ) ) {
						$cols[ $k ] = $i;
					}
				}
				if ( ! isset( $cols['tracking'], $cols['amount'] ) ) {
					fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					return [ 'rows' => [], 'error' => __( 'Colonnes « code » (n° de suivi) et « montant » introuvables dans la 1re ligne.', 'cod-tracker-crm' ) ];
				}
				continue;
			}
			if ( count( $rows ) >= 5000 ) {
				break;
			}
			$tracking = preg_replace( '/\D+/', '', (string) ( $r[ $cols['tracking'] ] ?? '' ) );
			if ( '' === $tracking ) {
				continue;
			}
			$rows[] = [
				'tracking' => substr( $tracking, 0, 64 ),
				'amount'   => self::parse_amount( (string) ( $r[ $cols['amount'] ] ?? '' ) ),
				'fee'      => isset( $cols['fee'] ) ? (float) ( self::parse_amount( (string) ( $r[ $cols['fee'] ] ?? '' ) ) ?? 0 ) : 0.0,
				'ref'      => isset( $cols['ref'] ) ? sanitize_text_field( (string) ( $r[ $cols['ref'] ] ?? '' ) ) : '',
			];
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return [ 'rows' => $rows, 'error' => $rows ? '' : __( 'Aucune ligne exploitable.', 'cod-tracker-crm' ) ];
	}

	/**
	 * Rapproche les lignes du relevé avec la base (rien n'est modifié).
	 *
	 * @return array<int,array> lignes + 'verdict' (ok|diff|unexpected|duplicate|unknown|invalid|paid) + 'order_id' + 'expected'.
	 */
	public static function classify( array $rows ): array {
		$seen = [];
		$out  = [];
		foreach ( $rows as $r ) {
			$r['order_id'] = 0;
			$r['expected'] = null;
			$r['status']   = '';

			if ( isset( $seen[ $r['tracking'] ] ) ) {
				$r['verdict'] = 'duplicate';
				$out[]        = $r;
				continue;
			}
			$seen[ $r['tracking'] ] = true;

			if ( null === $r['amount'] ) {
				$r['verdict'] = 'invalid';
				$out[]        = $r;
				continue;
			}

			$row = self::get_by_tracking( $r['tracking'] );
			if ( ! $row ) {
				$r['verdict'] = 'unknown';
				$out[]        = $r;
				continue;
			}
			$r['order_id'] = (int) $row->order_id;
			$r['expected'] = (float) $row->expected;
			$r['status']   = $row->status;

			if ( self::PAID_OUT === $row->status ) {
				$r['verdict'] = 'paid';
			} elseif ( ! in_array( $row->status, [ self::COLLECTED, self::DISPUTED ], true ) ) {
				// Le transporteur reverse un colis non livré (en cours / retourné / annulé).
				$r['verdict'] = 'unexpected';
			} elseif ( abs( $r['amount'] - (float) $row->expected ) < 0.0005 ) {
				$r['verdict'] = 'ok';
			} else {
				$r['verdict'] = 'diff';
			}
			$out[] = $r;
		}
		return $out;
	}

	/**
	 * Applique un relevé classé : ok → reversé ; diff / unexpected → litige.
	 *
	 * @return array{paid:int,disputed:int,skipped:int}
	 */
	public static function apply_statement( array $classified, string $default_ref, int $actor_id ): array {
		$res = [ 'paid' => 0, 'disputed' => 0, 'skipped' => 0 ];
		$now = current_time( 'mysql' );

		foreach ( $classified as $r ) {
			$ref = '' !== $r['ref'] ? $r['ref'] : $default_ref;
			if ( 'ok' === $r['verdict'] ) {
				self::set_status( $r['order_id'], self::PAID_OUT, $actor_id, '' !== $ref ? sprintf( __( 'Relevé %s', 'cod-tracker-crm' ), $ref ) : __( 'Relevé importé', 'cod-tracker-crm' ), [
					'collected' => $r['amount'], 'fee' => $r['fee'], 'statement_ref' => $ref, 'paid_at' => $now,
				] );
				$res['paid']++;
			} elseif ( in_array( $r['verdict'], [ 'diff', 'unexpected' ], true ) ) {
				$note = 'diff' === $r['verdict']
					? sprintf( __( 'Écart : attendu %1$s, relevé %2$s', 'cod-tracker-crm' ), self::money( $r['expected'] ), self::money( $r['amount'] ) )
					: __( 'Payé par le transporteur alors que le colis n\'est pas livré', 'cod-tracker-crm' );
				self::set_status( $r['order_id'], self::DISPUTED, $actor_id, $note, [ 'collected' => $r['amount'], 'fee' => $r['fee'], 'statement_ref' => $ref ] );
				$res['disputed']++;
			} else {
				$res['skipped']++;
			}
		}
		return $res;
	}

	// ── Liste / synthèse / export ──────────────────────────────

	private static function where( array $f ): array {
		global $wpdb;
		$w = [ '1=1' ];
		$a = [];
		if ( ! empty( $f['status'] ) && isset( self::statuses()[ $f['status'] ] ) ) {
			$w[] = 'status = %s';
			$a[] = $f['status'];
		}
		if ( ! empty( $f['carrier'] ) ) {
			$w[] = 'carrier_slug = %s';
			$a[] = $f['carrier'];
		}
		if ( ! empty( $f['from'] ) ) {
			$w[] = 'created_at >= %s';
			$a[] = $f['from'] . ' 00:00:00';
		}
		if ( ! empty( $f['to'] ) ) {
			$w[] = 'created_at <= %s';
			$a[] = $f['to'] . ' 23:59:59';
		}
		if ( ! empty( $f['q'] ) ) {
			$w[] = '(tracking LIKE %s OR order_id = %d)';
			$a[] = '%' . $wpdb->esc_like( $f['q'] ) . '%';
			$a[] = (int) $f['q'];
		}
		return [ implode( ' AND ', $w ), $a ];
	}

	/** @return array{rows:array,total:int} */
	public static function query( array $f, int $page, int $per_page ): array {
		global $wpdb;
		$t = CODCRM_Installer::table_cash();
		[ $w, $a ] = self::where( $f );

		$total = (int) $wpdb->get_var( $a ? $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$w}", $a ) : "SELECT COUNT(*) FROM {$t} WHERE {$w}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$sql   = "SELECT * FROM {$t} WHERE {$w} ORDER BY order_id DESC LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $a, [ $per_page, max( 0, ( $page - 1 ) * $per_page ) ] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return [ 'rows' => (array) $rows, 'total' => $total ];
	}

	/** Synthèse par statut. @return array<string,array{n:int,expected:float,collected:float,fee:float}> */
	public static function summary( array $f ): array {
		global $wpdb;
		$t = CODCRM_Installer::table_cash();
		[ $w, $a ] = self::where( $f );
		$sql  = "SELECT status, COUNT(*) n, SUM(expected) expected, SUM(COALESCE(collected, expected)) collected, SUM(fee) fee FROM {$t} WHERE {$w} GROUP BY status";
		$rows = $wpdb->get_results( $a ? $wpdb->prepare( $sql, $a ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		$out = [];
		foreach ( array_keys( self::statuses() ) as $s ) {
			$out[ $s ] = [ 'n' => 0, 'expected' => 0.0, 'collected' => 0.0, 'fee' => 0.0 ];
		}
		foreach ( (array) $rows as $r ) {
			$out[ $r['status'] ] = [ 'n' => (int) $r['n'], 'expected' => (float) $r['expected'], 'collected' => (float) $r['collected'], 'fee' => (float) $r['fee'] ];
		}
		return $out;
	}

	/** Neutralise l'injection de formules dans Excel/LibreOffice. */
	public static function csv_cell( $v ): string {
		$v = (string) $v;
		return '' !== $v && in_array( $v[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ? "'" . $v : $v;
	}
}
